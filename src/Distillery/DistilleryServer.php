<?php

declare(strict_types=1);

namespace HalfBaked\Distillery;

/**
 * Lightweight HTTP server for the Distillery wizard UI.
 *
 * Uses PHP's built-in web server with a router script that dispatches
 * API routes and serves static files from the public directory.
 */
class DistilleryServer
{
    public function __construct(
        private string $host,
        private int $port,
        private ExpertRegistry $registry,
        private string $publicDir,
        private string $dataDir,
    ) {}

    /**
     * Start the PHP built-in web server.
     * This method blocks until the server process exits.
     */
    public function start(): void
    {
        $routerPath = $this->writeRouterScript();

        fprintf(STDERR, "HalfBaked Distillery starting on http://%s:%d\n", $this->host, $this->port);
        fprintf(STDERR, "Public dir: %s\n", $this->publicDir);
        fprintf(STDERR, "Data dir:   %s\n", $this->dataDir);
        fprintf(STDERR, "Press Ctrl+C to stop.\n\n");

        $cmd = sprintf(
            'php -S %s:%d -t %s %s',
            escapeshellarg($this->host),
            $this->port,
            escapeshellarg($this->publicDir),
            escapeshellarg($routerPath),
        );

        // Pass environment variables so the router can find the DB and data dir
        putenv('HALFBAKED_DB=' . $this->dataDir . '/experts.db');
        putenv('HALFBAKED_DATA_DIR=' . $this->dataDir);
        putenv('HALFBAKED_PUBLIC_DIR=' . $this->publicDir);
        putenv('HALFBAKED_ROOT=' . dirname($this->dataDir));

        passthru($cmd);

        // Cleanup
        if (file_exists($routerPath)) {
            unlink($routerPath);
        }
    }

    /**
     * Write the router script to a temp file.
     * The router handles API routes and falls back to static files.
     */
    private function writeRouterScript(): string
    {
        $routerPath = sys_get_temp_dir() . '/halfbaked-distillery-router-' . getmypid() . '.php';

        $routerCode = <<<'ROUTER'
<?php
/**
 * HalfBaked Distillery Router Script
 * Dispatched by PHP built-in web server.
 */
declare(strict_types=1);

// Bootstrap autoloader
$autoloadPaths = [
    getenv('HALFBAKED_ROOT') . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require $path;
        break;
    }
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// --- API Routes ---
if (str_starts_with($uri, '/api/')) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $dbPath = getenv('HALFBAKED_DB') ?: (getenv('HALFBAKED_DATA_DIR') . '/experts.db');
    $registry = new \HalfBaked\Distillery\ExpertRegistry($dbPath);

    try {
        routeApi($uri, $method, $registry);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// --- Static Files ---
// Serve distillery.html for root
if ($uri === '/' || $uri === '/index.html') {
    $publicDir = getenv('HALFBAKED_PUBLIC_DIR') ?: __DIR__;
    $html = $publicDir . '/distillery.html';
    if (file_exists($html)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($html);
        exit;
    }
}

// Let PHP built-in server handle static files
return false;

// ============================================================
// API Route Handlers
// ============================================================

function routeApi(string $uri, string $method, \HalfBaked\Distillery\ExpertRegistry $registry): void
{
    // GET /api/experts
    if ($uri === '/api/experts' && $method === 'GET') {
        echo json_encode(['experts' => $registry->list()]);
        return;
    }

    // POST /api/experts — create new expert + launch distillation
    if ($uri === '/api/experts' && $method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];

        // Accept sources array or legacy single source
        $sources = $body['sources'] ?? [];
        if (empty($sources)) {
            $source = $body['source'] ?? '';
            if (empty($source)) {
                http_response_code(400);
                echo json_encode(['error' => 'source or sources is required']);
                return;
            }
            $sources = [$source];
        }
        $source = $sources[0]; // Primary source for display/naming

        $name = $body['name'] ?? '';
        if (empty($name)) {
            // Derive name from primary source
            if (preg_match('/([^\/]+?)(?:\.git)?$/', $source, $m)) {
                $name = $m[1];
            } else {
                $name = basename(rtrim($source, '/'));
            }
        }

        $sourceType = 'git';
        if (!preg_match('#^(https?://|git@|[\w.-]+\.\w+:)#', $source)) {
            $sourceType = 'local';
        }

        $modelSize = $body['model_size'] ?? '7B';
        $targetSamples = (int)($body['target_samples'] ?? 1000);

        // Snapshot all settings at creation time for reproducibility
        $globalSettings = $registry->getSettings();
        $modelMap = [
            '0.5b' => 'unsloth/Qwen2.5-Coder-0.5B-Instruct',
            '1.5b' => 'unsloth/Qwen2.5-Coder-1.5B-Instruct',
            '3b' => 'unsloth/Qwen2.5-Coder-3B-Instruct',
            '7b' => 'unsloth/Qwen2.5-Coder-7B-Instruct',
            '9b' => 'unsloth/Qwen3.5-9B',
            '14b' => 'unsloth/Qwen2.5-Coder-14B-Instruct',
        ];

        // Custom base_model overrides size-based selection
        $customBaseModel = trim($body['base_model'] ?? '');
        $baseModel = $customBaseModel ?: ($modelMap[strtolower($modelSize)] ?? $modelMap['7b']);

        $config = [
            'language' => $body['language'] ?? 'auto',
            'model_size' => $modelSize,
            'target_samples' => $targetSamples,
            'target_examples' => $targetSamples,
            'base_model' => $baseModel,
            'sources' => $sources,
            'distill_provider' => $globalSettings['distill_provider'] ?? 'anthropic',
            'distill_model' => $globalSettings['distill_model'] ?? '',
            'api_base' => $globalSettings['api_base'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $id = $registry->create($name, $source, $sourceType, $config);
        $registry->update($id, ['model_size' => $modelSize]);

        // Launch distillation in background
        launchDistillation($id);

        $expert = $registry->get($id);
        http_response_code(201);
        echo json_encode(['expert' => $expert]);
        return;
    }

    // GET /api/experts/{id}
    if (preg_match('#^/api/experts/([a-f0-9-]+)$#', $uri, $m) && $method === 'GET') {
        $expert = $registry->get($m[1]);
        if (!$expert) {
            http_response_code(404);
            echo json_encode(['error' => 'Expert not found']);
            return;
        }
        echo json_encode(['expert' => $expert]);
        return;
    }

    // DELETE /api/experts/{id}
    if (preg_match('#^/api/experts/([a-f0-9-]+)$#', $uri, $m) && $method === 'DELETE') {
        $expert = $registry->get($m[1]);
        if (!$expert) {
            http_response_code(404);
            echo json_encode(['error' => 'Expert not found']);
            return;
        }

        // Clean up GGUF file
        if (!empty($expert['gguf_path']) && file_exists($expert['gguf_path'])) {
            unlink($expert['gguf_path']);
        }

        $registry->delete($m[1]);
        echo json_encode(['deleted' => true]);
        return;
    }

    // GET /api/experts/{id}/logs
    if (preg_match('#^/api/experts/([a-f0-9-]+)/logs$#', $uri, $m) && $method === 'GET') {
        $expert = $registry->get($m[1]);
        if (!$expert) {
            http_response_code(404);
            echo json_encode(['error' => 'Expert not found']);
            return;
        }

        $step = $_GET['step'] ?? '';
        $dataDir = getenv('HALFBAKED_DATA_DIR') ?: dirname(getenv('HALFBAKED_DB'));
        $expertDir = $dataDir . '/experts/' . $m[1];
        $logDir = $expertDir . '/logs';

        $logFile = '';
        if (!empty($step)) {
            // Check per-step logs dir first, then expert dir
            $logFile = $logDir . '/' . basename($step) . '.log';
            if (!file_exists($logFile)) {
                $logFile = $expertDir . '/' . basename($step) . '.log';
            }
        } else {
            // Map status to the active log file
            $status = $expert['status'] ?? 'pending';
            $statusToLog = [
                'distilling' => $expertDir . '/distill.log',
                'training' => $expertDir . '/train.log',
                'exporting' => $expertDir . '/export.log',
            ];
            if (isset($statusToLog[$status])) {
                $logFile = $statusToLog[$status];
            }
            if (!$logFile || !file_exists($logFile)) {
                // Find the most recent log file
                $logFiles = array_merge(
                    glob($logDir . '/*.log') ?: [],
                    glob($expertDir . '/*.log') ?: []
                );
                if (!empty($logFiles)) {
                    usort($logFiles, fn($a, $b) => filemtime($b) - filemtime($a));
                    $logFile = $logFiles[0];
                }
            }
        }

        $lines = [];
        if (!empty($logFile) && file_exists($logFile)) {
            $all = file($logFile, FILE_IGNORE_NEW_LINES);
            $lines = array_slice($all, -100);
        }

        // List available log files from both directories
        $available = [];
        foreach (array_merge(glob($logDir . '/*.log') ?: [], glob($expertDir . '/*.log') ?: []) as $f) {
            $available[] = basename($f, '.log');
        }
        $available = array_unique($available);

        echo json_encode([
            'lines' => $lines,
            'step' => basename($logFile ?? '', '.log'),
            'available_steps' => $available,
        ]);
        return;
    }

    // POST /api/experts/import — import an existing GGUF as a ready expert
    if ($uri === '/api/experts/import' && $method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $name = $body['name'] ?? '';
        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['error' => 'name is required']);
            return;
        }

        $ggufPath = $body['gguf_path'] ?? '';
        $ollamaModel = $body['ollama_model'] ?? '';
        $ggufSize = 0;

        // Try to get size from Ollama
        if ($ollamaModel) {
            $ch = curl_init('http://127.0.0.1:11434/api/tags');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
            $listBody = curl_exec($ch);
            curl_close($ch);
            if ($listBody) {
                $listData = json_decode($listBody, true) ?: [];
                foreach ($listData['models'] ?? [] as $m) {
                    if ($m['name'] === $ollamaModel || $m['name'] === $ollamaModel . ':latest') {
                        $ggufSize = $m['size'] ?? 0;
                        break;
                    }
                }
            }
            if (!$ggufPath) {
                $ggufPath = 'ollama://' . $ollamaModel;
            }
        } elseif ($ggufPath && file_exists($ggufPath)) {
            $ggufSize = filesize($ggufPath);
        }

        $config = [
            'model_size' => $body['model_size'] ?? '7b',
            'base_model' => $body['base_model'] ?? '',
            'system_prompt' => $body['system_prompt'] ?? '',
            'ollama_model' => $ollamaModel,
            'imported' => true,
            'imported_at' => date('Y-m-d H:i:s'),
            'samples_count' => (int)($body['samples_count'] ?? 0),
            'dataset_count' => (int)($body['dataset_count'] ?? 0),
            'training_loss' => (float)($body['training_loss'] ?? 0),
        ];

        $id = $registry->create($name, $body['source'] ?? 'imported', 'imported', $config);
        $registry->update($id, [
            'language' => $body['language'] ?? '',
            'framework' => $body['framework'] ?? '',
            'model_size' => $body['model_size'] ?? '7b',
            'samples_count' => (int)($body['samples_count'] ?? 0),
            'dataset_count' => (int)($body['dataset_count'] ?? 0),
            'training_loss' => (float)($body['training_loss'] ?? 0),
            'gguf_path' => $ggufPath,
            'gguf_size' => $ggufSize,
        ]);
        $registry->updateStatus($id, \HalfBaked\Distillery\ExpertStatus::Ready);

        $expert = $registry->get($id);
        http_response_code(201);
        echo json_encode(['expert' => $expert]);
        return;
    }

    // POST /api/detect — detect language for a source
    if ($uri === '/api/detect' && $method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $source = $body['source'] ?? '';
        if (empty($source)) {
            http_response_code(400);
            echo json_encode(['error' => 'source is required']);
            return;
        }

        $result = detectLanguage($source);
        echo json_encode($result);
        return;
    }

    // GET /api/models — list Ollama models
    if ($uri === '/api/models' && $method === 'GET') {
        $ch = curl_init('http://127.0.0.1:11434/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            echo json_encode(['models' => [], 'error' => 'Cannot connect to Ollama']);
            return;
        }

        $data = json_decode($body, true) ?: [];
        echo json_encode(['models' => $data['models'] ?? []]);
        return;
    }

    // GET /api/settings — get all settings
    if ($uri === '/api/settings' && $method === 'GET') {
        $settings = $registry->getSettings();
        // Mask API key for display
        if (isset($settings['anthropic_api_key']) && strlen($settings['anthropic_api_key']) > 8) {
            $settings['anthropic_api_key_masked'] = substr($settings['anthropic_api_key'], 0, 8) . '...' . substr($settings['anthropic_api_key'], -4);
        }
        echo json_encode(['settings' => $settings]);
        return;
    }

    // POST /api/settings — update settings
    if ($uri === '/api/settings' && $method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $allowed = ['anthropic_api_key', 'ollama_host', 'ollama_port', 'default_model_size', 'default_target_examples', 'python_path', 'distill_provider', 'distill_model', 'api_base', 'openai_api_key'];
        foreach ($body as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $registry->setSetting($key, (string) $value);
            }
        }
        echo json_encode(['saved' => true]);
        return;
    }

    // GET /api/experts/{id}/progress — real-time progress for active distillation
    if (preg_match('#^/api/experts/([a-f0-9-]+)/progress$#', $uri, $m) && $method === 'GET') {
        $expert = $registry->get($m[1]);
        if (!$expert) {
            http_response_code(404);
            echo json_encode(['error' => 'Expert not found']);
            return;
        }

        $dataDir = getenv('HALFBAKED_DATA_DIR') ?: dirname(getenv('HALFBAKED_DB'));
        $expertDir = $dataDir . '/experts/' . $m[1];

        $progress = [
            'status' => $expert['status'],
            'batch_current' => 0,
            'batch_total' => 0,
            'dataset_pairs' => 0,
            'batch_type' => '',
        ];

        // Read current dataset pair count from the file directly
        $datasetFile = $expertDir . '/data/dataset.jsonl';
        if (file_exists($datasetFile)) {
            $progress['dataset_pairs'] = count(file($datasetFile, FILE_SKIP_EMPTY_LINES));
        }

        // Parse batch progress from distill log
        $distillLog = $expertDir . '/distill.log';
        if (file_exists($distillLog)) {
            $content = file_get_contents($distillLog);
            // Match last batch line: "Batch N/M | type | P pairs"
            if (preg_match_all('/Batch (\d+)\/(\d+)\s*\|\s*(\w+)\s*\|\s*(\d+) pairs/', $content, $matches, PREG_SET_ORDER)) {
                $last = end($matches);
                $progress['batch_current'] = (int) $last[1];
                $progress['batch_total'] = (int) $last[2];
                $progress['batch_type'] = $last[3];
            }
            // Check for "Generation plan" line for total
            if (preg_match('/Generation plan: (\d+) API calls, ~(\d+) expected pairs/', $content, $planMatch)) {
                $progress['batch_total'] = (int) $planMatch[1];
                $progress['expected_pairs'] = (int) $planMatch[2];
            }
        }

        // Parse training progress from train log
        $trainLog = $expertDir . '/train.log';
        if (file_exists($trainLog) && $expert['status'] === 'training') {
            $content = file_get_contents($trainLog);
            // Match Hugging Face training progress: "{'loss': 1.234, 'epoch': 0.5}"
            if (preg_match_all("/\\{'loss': ([\\d.]+).*?'epoch': ([\\d.]+)/", $content, $trainMatches, PREG_SET_ORDER)) {
                $last = end($trainMatches);
                $progress['current_loss'] = (float) $last[1];
                $progress['current_epoch'] = (float) $last[2];
            }
        }

        echo json_encode(['progress' => $progress]);
        return;
    }

    // POST /api/experts/{id}/retry — retry a failed distillation from a specific step
    if (preg_match('#^/api/experts/([a-f0-9-]+)/retry$#', $uri, $m) && $method === 'POST') {
        $expert = $registry->get($m[1]);
        if (!$expert) {
            http_response_code(404);
            echo json_encode(['error' => 'Expert not found']);
            return;
        }
        if ($expert['status'] !== 'failed') {
            http_response_code(400);
            echo json_encode(['error' => 'Expert is not in failed state (current: ' . $expert['status'] . ')']);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $fromStep = $body['from_step'] ?? '';
        $baseModel = $body['base_model'] ?? '';
        $modelSize = $body['model_size'] ?? '';

        $validSteps = ['clone-repo', 'detect-language', 'extract-code', 'generate-dataset', 'train-model', 'export-gguf'];
        if ($fromStep && !in_array($fromStep, $validSteps, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid step: ' . $fromStep, 'valid_steps' => $validSteps]);
            return;
        }

        // Update config if model overrides provided
        $config = $expert['config'] ?? [];
        if ($baseModel) {
            $config['base_model'] = $baseModel;
        }
        if ($modelSize) {
            $config['model_size'] = $modelSize;
        }
        if ($baseModel || $modelSize) {
            $registry->update($m[1], ['config' => $config]);
            if ($modelSize) {
                $registry->update($m[1], ['model_size' => $modelSize]);
            }
        }

        // Clear error and reset status
        $registry->updateStatus($m[1], \HalfBaked\Distillery\ExpertStatus::Pending, '');

        // Launch with --from-step
        launchDistillation($m[1], $fromStep, $baseModel);

        echo json_encode(['status' => 'retrying', 'from_step' => $fromStep ?: 'clone-repo']);
        return;
    }

    // 404 fallback
    http_response_code(404);
    echo json_encode(['error' => 'Not found: ' . $uri]);
}

/**
 * Detect the primary language of a source path or repo URL.
 */
function detectLanguage(string $source): array
{
    $path = $source;

    // For git URLs, we can't clone here — just guess from URL
    if (preg_match('#^(https?://|git@|[\w.-]+\.\w+:)#', $source)) {
        $lang = 'auto';
        $frameworks = [];

        // Heuristics from URL
        if (str_contains($source, 'laravel') || str_contains($source, 'symfony')) {
            $lang = 'PHP';
            $frameworks[] = str_contains($source, 'laravel') ? 'Laravel' : 'Symfony';
        } elseif (str_contains($source, 'django') || str_contains($source, 'flask')) {
            $lang = 'Python';
            $frameworks[] = str_contains($source, 'django') ? 'Django' : 'Flask';
        } elseif (str_contains($source, 'react') || str_contains($source, 'next')) {
            $lang = 'JavaScript';
            $frameworks[] = str_contains($source, 'react') ? 'React' : 'Next.js';
        }

        return [
            'language' => $lang,
            'frameworks' => $frameworks,
            'confidence' => $lang === 'auto' ? 0 : 0.6,
            'note' => 'Detected from URL heuristics. Full detection will run during distillation.',
        ];
    }

    // Local path detection
    if (!is_dir($path)) {
        return ['language' => 'auto', 'frameworks' => [], 'confidence' => 0, 'error' => 'Path not found'];
    }

    $counts = ['PHP' => 0, 'Python' => 0, 'JavaScript' => 0, 'TypeScript' => 0, 'CSS' => 0, 'Go' => 0, 'Rust' => 0];
    $frameworks = [];

    // Check for framework markers
    if (file_exists($path . '/composer.json')) {
        $counts['PHP'] += 50;
        $composer = json_decode(file_get_contents($path . '/composer.json'), true) ?: [];
        $requires = array_merge(
            array_keys($composer['require'] ?? []),
            array_keys($composer['require-dev'] ?? []),
        );
        foreach ($requires as $pkg) {
            if (str_contains($pkg, 'laravel')) $frameworks[] = 'Laravel';
            if (str_contains($pkg, 'symfony')) $frameworks[] = 'Symfony';
            if (str_contains($pkg, 'slim')) $frameworks[] = 'Slim';
        }
    }
    if (file_exists($path . '/package.json')) {
        $pkg = json_decode(file_get_contents($path . '/package.json'), true) ?: [];
        $deps = array_merge(
            array_keys($pkg['dependencies'] ?? []),
            array_keys($pkg['devDependencies'] ?? []),
        );
        foreach ($deps as $dep) {
            if ($dep === 'react' || str_starts_with($dep, 'react-')) { $frameworks[] = 'React'; $counts['JavaScript'] += 20; }
            if ($dep === 'vue' || str_starts_with($dep, 'vue-')) { $frameworks[] = 'Vue'; $counts['JavaScript'] += 20; }
            if ($dep === 'next') { $frameworks[] = 'Next.js'; $counts['JavaScript'] += 20; }
            if ($dep === 'typescript') { $counts['TypeScript'] += 30; }
        }
        $counts['JavaScript'] += 30;
    }
    if (file_exists($path . '/requirements.txt') || file_exists($path . '/setup.py') || file_exists($path . '/pyproject.toml')) {
        $counts['Python'] += 50;
        if (file_exists($path . '/manage.py')) $frameworks[] = 'Django';
    }
    if (file_exists($path . '/go.mod')) {
        $counts['Go'] += 50;
    }
    if (file_exists($path . '/Cargo.toml')) {
        $counts['Rust'] += 50;
    }
    if (file_exists($path . '/tsconfig.json')) {
        $counts['TypeScript'] += 40;
    }

    // Count files by extension (quick scan, max 500 files)
    $extMap = [
        'php' => 'PHP', 'py' => 'Python', 'js' => 'JavaScript', 'jsx' => 'JavaScript',
        'ts' => 'TypeScript', 'tsx' => 'TypeScript', 'css' => 'CSS', 'scss' => 'CSS',
        'go' => 'Go', 'rs' => 'Rust',
    ];
    $scanned = 0;
    $iter = new \RecursiveIteratorIterator(
        new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            function ($current) {
                if ($current->isDir()) {
                    return !in_array($current->getFilename(), ['vendor', 'node_modules', '.git', '__pycache__', 'target']);
                }
                return true;
            }
        )
    );
    foreach ($iter as $file) {
        if ($scanned++ > 500) break;
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if (isset($extMap[$ext])) {
            $counts[$extMap[$ext]]++;
        }
    }

    arsort($counts);
    $top = array_key_first($counts);
    $topCount = $counts[$top];
    $total = array_sum($counts);

    return [
        'language' => $topCount > 0 ? $top : 'auto',
        'frameworks' => array_values(array_unique($frameworks)),
        'confidence' => $total > 0 ? round($topCount / $total, 2) : 0,
        'counts' => $counts,
    ];
}

/**
 * Launch the distillation pipeline as a background process.
 * Calls the real `halfbaked distill run <id>` CLI command.
 */
function launchDistillation(string $expertId, string $fromStep = '', string $baseModel = ''): void
{
    $root = getenv('HALFBAKED_ROOT') ?: dirname(getenv('HALFBAKED_DATA_DIR'));
    $dataDir = getenv('HALFBAKED_DATA_DIR') ?: ($root . '/data');
    $halfbakedBin = $root . '/bin/halfbaked';

    // Load API key from settings
    $dbPath = getenv('HALFBAKED_DB') ?: ($dataDir . '/experts.db');
    $reg = new \HalfBaked\Distillery\ExpertRegistry($dbPath);
    $apiKey = $reg->getSetting('anthropic_api_key', getenv('ANTHROPIC_API_KEY') ?: '');

    $logDir = $dataDir . '/experts/' . $expertId;
    if (!is_dir($logDir)) { mkdir($logDir, 0755, true); }
    $logFile = $logDir . '/launch.log';

    $pythonPath = $reg->getSetting('python_path', '');

    $envPrefix = '';
    if ($apiKey) {
        $envPrefix .= sprintf('ANTHROPIC_API_KEY=%s ', escapeshellarg($apiKey));
    }
    if ($pythonPath) {
        $envPrefix .= sprintf('HALFBAKED_PYTHON=%s ', escapeshellarg($pythonPath));
    }

    $extraFlags = '';
    if ($fromStep) {
        $extraFlags .= ' --from-step=' . escapeshellarg($fromStep);
    }
    if ($baseModel) {
        $extraFlags .= ' --base-model=' . escapeshellarg($baseModel);
    }

    $cmd = sprintf(
        '%sphp %s distill run %s%s > %s 2>&1 &',
        $envPrefix,
        escapeshellarg($halfbakedBin),
        escapeshellarg($expertId),
        $extraFlags,
        escapeshellarg($logFile)
    );
    exec($cmd);
}
ROUTER;

        file_put_contents($routerPath, $routerCode);
        return $routerPath;
    }

    /**
     * Get the base URL for this server.
     */
    public function getUrl(): string
    {
        return sprintf('http://%s:%d', $this->host, $this->port);
    }
}
