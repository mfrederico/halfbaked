<?php

declare(strict_types=1);

namespace HalfBaked\Builder;

/**
 * Unified HalfBaked HTTP server — Distillery + Bakery.
 *
 * Serves both the Distillery (expert training) and Builder (code generation)
 * UIs and APIs from a single server. Distillery creates specialist models,
 * Builder uses them to generate code via multi-expert pipelines.
 */
class BuilderServer
{
    public function __construct(
        private string $host,
        private int $port,
        private string $publicDir,
        private string $dataDir,
    ) {}

    public function start(): void
    {
        $routerPath = $this->writeRouterScript();

        fprintf(STDERR, "HalfBaked starting on http://%s:%d\n", $this->host, $this->port);
        fprintf(STDERR, "  Distillery: http://%s:%d/distillery\n", $this->host, $this->port);
        fprintf(STDERR, "  Builder:    http://%s:%d/builder\n", $this->host, $this->port);
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

        putenv('HALFBAKED_BUILDER_DB=' . $this->dataDir . '/builder.db');
        putenv('HALFBAKED_DB=' . $this->dataDir . '/experts.db');
        putenv('HALFBAKED_DATA_DIR=' . $this->dataDir);
        putenv('HALFBAKED_PUBLIC_DIR=' . $this->publicDir);
        putenv('HALFBAKED_ROOT=' . dirname($this->dataDir));

        passthru($cmd);

        if (file_exists($routerPath)) {
            unlink($routerPath);
        }
    }

    private function writeRouterScript(): string
    {
        $routerPath = sys_get_temp_dir() . '/halfbaked-router-' . getmypid() . '.php';

        $routerCode = <<<'ROUTER'
<?php
/**
 * HalfBaked Unified Router — Distillery + Builder
 * Dispatched by PHP built-in web server.
 */
declare(strict_types=1);

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

    try {
        // Route to the right handler based on prefix
        if (str_starts_with($uri, '/api/builds')) {
            $dbPath = getenv('HALFBAKED_BUILDER_DB') ?: (getenv('HALFBAKED_DATA_DIR') . '/builder.db');
            $registry = new \HalfBaked\Builder\BuildRegistry($dbPath);
            routeBuilderApi($uri, $method, $registry, $dbPath);
        } elseif (str_starts_with($uri, '/api/experts') || str_starts_with($uri, '/api/settings') || $uri === '/api/detect') {
            $dbPath = getenv('HALFBAKED_DB') ?: (getenv('HALFBAKED_DATA_DIR') . '/experts.db');
            $registry = new \HalfBaked\Distillery\ExpertRegistry($dbPath);
            routeDistilleryApi($uri, $method, $registry);
        } elseif ($uri === '/api/models') {
            routeModelsApi();
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Not found: ' . $uri]);
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// --- Static HTML Pages ---
$publicDir = getenv('HALFBAKED_PUBLIC_DIR') ?: __DIR__;

// Landing page
if ($uri === '/' || $uri === '/index.html') {
    serveLandingPage($publicDir);
    exit;
}

// Builder UI
if ($uri === '/builder' || $uri === '/builder.html') {
    $html = $publicDir . '/builder.html';
    if (file_exists($html)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($html);
        exit;
    }
}

// Distillery UI
if ($uri === '/distillery' || $uri === '/distillery.html') {
    $html = $publicDir . '/distillery.html';
    if (file_exists($html)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($html);
        exit;
    }
}

// Let PHP built-in server handle other static files (CSS, JS, images)
return false;

// ============================================================
// Landing Page
// ============================================================

function serveLandingPage(string $publicDir): void
{
    // Check for expert count
    $expertCount = 0;
    $buildCount = 0;
    try {
        $dbPath = getenv('HALFBAKED_DB') ?: (getenv('HALFBAKED_DATA_DIR') . '/experts.db');
        if (file_exists($dbPath)) {
            $reg = new \HalfBaked\Distillery\ExpertRegistry($dbPath);
            $expertCount = count($reg->list());
        }
    } catch (\Throwable $e) {}
    try {
        $dbPath = getenv('HALFBAKED_BUILDER_DB') ?: (getenv('HALFBAKED_DATA_DIR') . '/builder.db');
        if (file_exists($dbPath)) {
            $reg = new \HalfBaked\Builder\BuildRegistry($dbPath);
            $buildCount = count($reg->listBuilds());
        }
    } catch (\Throwable $e) {}

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HalfBaked</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #0d1117; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card-link { text-decoration: none; transition: transform 0.2s, box-shadow 0.2s; display: block; }
        .card-link:hover { transform: translateY(-4px); box-shadow: 0 8px 25px rgba(0,0,0,0.4); }
        .card { border: 1px solid #30363d; background: #161b22; }
        .badge-count { font-size: 1.1rem; }
        .hero-icon { font-size: 3rem; margin-bottom: 0.5rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="text-center mb-5">
            <h1 class="display-4 fw-bold text-light">HalfBaked</h1>
            <p class="lead text-secondary">Distill expert models. Build with them.</p>
        </div>
        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="/distillery" class="card-link">
                    <div class="card p-4 text-center h-100">
                        <div class="hero-icon">&#x2697;</div>
                        <h2 class="h4 text-light">Distillery</h2>
                        <p class="text-secondary mb-3">Train specialist LLM experts from your codebase. Scan, distill, fine-tune, and export GGUF models for Ollama.</p>
                        <div>
                            <span class="badge bg-info badge-count">{$expertCount} expert(s)</span>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-5">
                <a href="/builder" class="card-link">
                    <div class="card p-4 text-center h-100">
                        <div class="hero-icon">&#x1F3D7;</div>
                        <h2 class="h4 text-light">Builder</h2>
                        <p class="text-secondary mb-3">Generate code with multi-expert pipelines. Decompose features into subtasks, route to specialists, assemble output.</p>
                        <div>
                            <span class="badge bg-success badge-count">{$buildCount} build(s)</span>
                        </div>
                    </div>
                </a>
            </div>
        </div>
        <div class="text-center mt-4">
            <small class="text-secondary">Distill your codebase into expert models, then use them to build new features.</small>
        </div>
    </div>
</body>
</html>
HTML;
}

// ============================================================
// Builder API Routes
// ============================================================

function routeBuilderApi(string $uri, string $method, \HalfBaked\Builder\BuildRegistry $registry, string $dbPath): void
{
    // GET /api/builds
    if ($uri === '/api/builds' && $method === 'GET') {
        echo json_encode(['builds' => $registry->listBuilds()]);
        return;
    }

    // POST /api/builds
    if ($uri === '/api/builds' && $method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $task = $body['task'] ?? '';
        $projectPath = $body['project_path'] ?? '';

        if (empty($task)) {
            http_response_code(400);
            echo json_encode(['error' => 'task is required']);
            return;
        }
        if (empty($projectPath)) {
            http_response_code(400);
            echo json_encode(['error' => 'project_path is required']);
            return;
        }

        // Detect git URL vs local directory
        $isGit = (bool) preg_match('#^(https?://|git@|ssh://|[\w.-]+\.\w+:)#', $projectPath);
        if (!$isGit && !is_dir($projectPath)) {
            http_response_code(400);
            echo json_encode(['error' => 'project_path is not a valid directory or git URL']);
            return;
        }

        $config = $body['config'] ?? [];
        $buildId = $registry->createBuild($task, realpath($projectPath) ?: $projectPath, $config);

        $build = $registry->getBuild($buildId);
        http_response_code(201);
        echo json_encode(['build' => $build]);
        return;
    }

    // GET /api/builds/{id}
    if (preg_match('#^/api/builds/([a-f0-9]+)$#', $uri, $m) && $method === 'GET') {
        $build = $registry->getBuild($m[1]);
        if (!$build) {
            http_response_code(404);
            echo json_encode(['error' => 'Build not found']);
            return;
        }
        $subtasks = $registry->getSubtasks($m[1]);
        echo json_encode(['build' => $build, 'subtasks' => $subtasks]);
        return;
    }

    // DELETE /api/builds/{id}
    if (preg_match('#^/api/builds/([a-f0-9]+)$#', $uri, $m) && $method === 'DELETE') {
        $build = $registry->getBuild($m[1]);
        if (!$build) {
            http_response_code(404);
            echo json_encode(['error' => 'Build not found']);
            return;
        }
        $registry->deleteBuild($m[1]);
        echo json_encode(['deleted' => true]);
        return;
    }

    // POST /api/builds/{id}/rebuild — reset failed subtasks and re-run execution + assembly
    if (preg_match('#^/api/builds/([a-f0-9]+)/rebuild$#', $uri, $m) && $method === 'POST') {
        $buildId = $m[1];
        $build = $registry->getBuild($buildId);
        if (!$build) {
            http_response_code(404);
            echo json_encode(['error' => 'Build not found']);
            return;
        }

        // Reset all non-completed subtasks back to pending
        $subtasks = $registry->getSubtasks($buildId);
        $resetCount = 0;
        foreach ($subtasks as $st) {
            if ($st['status'] !== 'completed') {
                $registry->updateSubtask($st['id'], [
                    'status' => 'pending',
                    'error' => '',
                    'generated_code' => '',
                    'files' => '[]',
                    'attempts' => 0,
                ]);
                $resetCount++;
            }
        }

        // Clear build error and reset status
        $registry->updateBuild($buildId, [
            'status' => 'generating',
            'error' => '',
            'result' => ['created' => [], 'modified' => [], 'errors' => []],
        ]);

        launchBuildPipeline($buildId, $dbPath);
        echo json_encode(['status' => 'rebuilding', 'build_id' => $buildId, 'reset_subtasks' => $resetCount]);
        return;
    }

    // POST /api/builds/{id}/execute
    if (preg_match('#^/api/builds/([a-f0-9]+)/execute$#', $uri, $m) && $method === 'POST') {
        $buildId = $m[1];
        $build = $registry->getBuild($buildId);
        if (!$build) {
            http_response_code(404);
            echo json_encode(['error' => 'Build not found']);
            return;
        }

        // Clear dry_run so the pipeline runs fully
        $config = $build['config'] ?? [];
        if (!empty($config['dry_run'])) {
            $config['dry_run'] = false;
            $registry->updateBuild($buildId, ['config' => $config]);
        }

        launchBuildPipeline($buildId, $dbPath);
        echo json_encode(['status' => 'started', 'build_id' => $buildId]);
        return;
    }

    // POST /api/builds/{id}/commit
    if (preg_match('#^/api/builds/([a-f0-9]+)/commit$#', $uri, $m) && $method === 'POST') {
        $buildId = $m[1];
        $build = $registry->getBuild($buildId);
        if (!$build) {
            http_response_code(404);
            echo json_encode(['error' => 'Build not found']);
            return;
        }

        $config = $build['config'] ?? [];
        // Try clone_path first (git URL builds), fall back to project_path (local builds)
        $clonePath = $config['clone_path'] ?? '';
        if (!$clonePath || !is_dir($clonePath . '/.git')) {
            $clonePath = $build['project_path'] ?? '';
        }
        if (!$clonePath || !is_dir($clonePath . '/.git')) {
            http_response_code(400);
            echo json_encode(['error' => 'No git repository found for this build']);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $branchName = $body['branch'] ?? 'build/' . substr($buildId, 0, 8);
        $commitMsg = $body['message'] ?? 'feat: ' . ($build['task'] ?? 'HalfBaked build');
        $push = $body['push'] ?? false;

        // Sanitize branch name
        $branchName = preg_replace('/[^a-zA-Z0-9\/_.-]/', '-', $branchName);

        $results = [];
        $cwd = $clonePath;

        // Create and checkout new branch
        exec("cd " . escapeshellarg($cwd) . " && git checkout -b " . escapeshellarg($branchName) . " 2>&1", $out, $code);
        $results['branch'] = ['output' => implode("\n", $out), 'code' => $code];

        if ($code !== 0) {
            // Branch might already exist, try switching
            $out = [];
            exec("cd " . escapeshellarg($cwd) . " && git checkout " . escapeshellarg($branchName) . " 2>&1", $out, $code);
            $results['branch'] = ['output' => implode("\n", $out), 'code' => $code];
        }

        if ($code !== 0) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create/switch branch', 'details' => $results]);
            return;
        }

        // Stage all new/modified files
        $out = [];
        exec("cd " . escapeshellarg($cwd) . " && git add -A 2>&1", $out, $code);
        $results['add'] = ['output' => implode("\n", $out), 'code' => $code];

        // Check if there's anything to commit
        $out = [];
        exec("cd " . escapeshellarg($cwd) . " && git diff --cached --stat 2>&1", $out);
        $diffStat = implode("\n", $out);
        $results['diff_stat'] = $diffStat;

        if (empty(trim($diffStat))) {
            echo json_encode(['status' => 'nothing_to_commit', 'branch' => $branchName, 'details' => $results]);
            return;
        }

        // Commit
        $out = [];
        $fullMsg = $commitMsg . "\n\nBuild-ID: " . $buildId . "\nGenerated by HalfBaked Builder";
        exec("cd " . escapeshellarg($cwd) . " && git commit -m " . escapeshellarg($fullMsg) . " 2>&1", $out, $code);
        $results['commit'] = ['output' => implode("\n", $out), 'code' => $code];

        if ($code !== 0) {
            http_response_code(500);
            echo json_encode(['error' => 'Commit failed', 'details' => $results]);
            return;
        }

        // Push if requested
        if ($push) {
            $out = [];
            exec("cd " . escapeshellarg($cwd) . " && git push -u origin " . escapeshellarg($branchName) . " 2>&1", $out, $code);
            $results['push'] = ['output' => implode("\n", $out), 'code' => $code];
        }

        // Extract PR URL from push output (GitHub/GitLab provide it)
        $prUrl = '';
        if ($push && isset($results['push']['output'])) {
            if (preg_match('#(https://\S+/pull/new/\S+)#', $results['push']['output'], $m)) {
                $prUrl = $m[1]; // GitHub
            } elseif (preg_match('#(https://\S+/merge_requests/new\S*)#', $results['push']['output'], $m)) {
                $prUrl = $m[1]; // GitLab
            }
        }

        // Store branch info in build config
        $config['branch'] = $branchName;
        $config['committed'] = true;
        $config['pushed'] = $push && ($results['push']['code'] ?? 1) === 0;
        if ($prUrl) {
            $config['pr_url'] = $prUrl;
        }
        $registry->updateBuild($buildId, ['config' => $config]);

        echo json_encode([
            'status' => 'committed',
            'branch' => $branchName,
            'pushed' => $config['pushed'],
            'pr_url' => $prUrl,
            'diff_stat' => $diffStat,
            'details' => $results,
        ]);
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Not found: ' . $uri]);
}

// ============================================================
// Distillery API Routes
// ============================================================

function routeDistilleryApi(string $uri, string $method, \HalfBaked\Distillery\ExpertRegistry $registry): void
{
    // GET /api/experts
    if ($uri === '/api/experts' && $method === 'GET') {
        echo json_encode(['experts' => $registry->list()]);
        return;
    }

    // POST /api/experts — create + launch distillation
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
        $source = $sources[0];

        $name = $body['name'] ?? '';
        if (empty($name)) {
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
            $logFile = $logDir . '/' . basename($step) . '.log';
            if (!file_exists($logFile)) {
                $logFile = $expertDir . '/' . basename($step) . '.log';
            }
        } else {
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

        $available = [];
        foreach (array_merge(glob($logDir . '/*.log') ?: [], glob($expertDir . '/*.log') ?: []) as $f) {
            $available[] = basename($f, '.log');
        }
        $available = array_unique($available);

        echo json_encode([
            'lines' => $lines,
            'step' => basename($logFile ?? '', '.log'),
            'available_steps' => array_values($available),
        ]);
        return;
    }

    // GET /api/experts/{id}/progress
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

        $datasetFile = $expertDir . '/data/dataset.jsonl';
        if (file_exists($datasetFile)) {
            $progress['dataset_pairs'] = count(file($datasetFile, FILE_SKIP_EMPTY_LINES));
        }

        $distillLog = $expertDir . '/distill.log';
        if (file_exists($distillLog)) {
            $content = file_get_contents($distillLog);
            if (preg_match_all('/Batch (\d+)\/(\d+)\s*\|\s*(\w+)\s*\|\s*(\d+) pairs/', $content, $matches, PREG_SET_ORDER)) {
                $last = end($matches);
                $progress['batch_current'] = (int) $last[1];
                $progress['batch_total'] = (int) $last[2];
                $progress['batch_type'] = $last[3];
            }
            if (preg_match('/Generation plan: (\d+) API calls, ~(\d+) expected pairs/', $content, $planMatch)) {
                $progress['batch_total'] = (int) $planMatch[1];
                $progress['expected_pairs'] = (int) $planMatch[2];
            }
        }

        $trainLog = $expertDir . '/train.log';
        if (file_exists($trainLog) && $expert['status'] === 'training') {
            $content = file_get_contents($trainLog);
            if (preg_match_all("/\\{'loss': ([\\d.]+).*?'epoch': ([\\d.]+)/", $content, $trainMatches, PREG_SET_ORDER)) {
                $last = end($trainMatches);
                $progress['current_loss'] = (float) $last[1];
                $progress['current_epoch'] = (float) $last[2];
            }
        }

        echo json_encode(['progress' => $progress]);
        return;
    }

    // POST /api/experts/import
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

    // POST /api/detect
    if ($uri === '/api/detect' && $method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $source = $body['source'] ?? '';
        if (empty($source)) {
            http_response_code(400);
            echo json_encode(['error' => 'source is required']);
            return;
        }
        echo json_encode(detectLanguage($source));
        return;
    }

    // GET /api/settings
    if ($uri === '/api/settings' && $method === 'GET') {
        $settings = $registry->getSettings();
        if (isset($settings['anthropic_api_key']) && strlen($settings['anthropic_api_key']) > 8) {
            $settings['anthropic_api_key_masked'] = substr($settings['anthropic_api_key'], 0, 8) . '...' . substr($settings['anthropic_api_key'], -4);
        }
        echo json_encode(['settings' => $settings]);
        return;
    }

    // POST /api/settings
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

    http_response_code(404);
    echo json_encode(['error' => 'Not found: ' . $uri]);
}

// ============================================================
// Shared Routes
// ============================================================

function routeModelsApi(): void
{
    $ch = curl_init('http://127.0.0.1:11434/api/tags');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        echo json_encode(['models' => [], 'error' => 'Cannot connect to Ollama']);
        return;
    }

    $data = json_decode($body, true) ?: [];
    echo json_encode(['models' => $data['models'] ?? []]);
}

// ============================================================
// Background Launchers
// ============================================================

function launchBuildPipeline(string $buildId, string $dbPath): void
{
    $root = getenv('HALFBAKED_ROOT') ?: dirname(getenv('HALFBAKED_DATA_DIR'));
    $halfbakedBin = $root . '/bin/halfbaked';
    $dataDir = getenv('HALFBAKED_DATA_DIR') ?: ($root . '/data');

    $logDir = $dataDir . '/builds';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/' . $buildId . '.log';

    $registry = new \HalfBaked\Builder\BuildRegistry($dbPath);
    $build = $registry->getBuild($buildId);
    $config = $build['config'] ?? [];

    $envPrefix = '';
    if (!empty($config['api_key'])) {
        $envPrefix .= sprintf('ANTHROPIC_API_KEY=%s ', escapeshellarg($config['api_key']));
    }

    $cmd = sprintf(
        '%sphp %s build run %s --db-path=%s > %s 2>&1 &',
        $envPrefix,
        escapeshellarg($halfbakedBin),
        escapeshellarg($buildId),
        escapeshellarg($dbPath),
        escapeshellarg($logFile),
    );
    exec($cmd);
}

function launchDistillation(string $expertId, string $fromStep = '', string $baseModel = ''): void
{
    $root = getenv('HALFBAKED_ROOT') ?: dirname(getenv('HALFBAKED_DATA_DIR'));
    $dataDir = getenv('HALFBAKED_DATA_DIR') ?: ($root . '/data');
    $halfbakedBin = $root . '/bin/halfbaked';

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

// ============================================================
// Helpers
// ============================================================

function detectLanguage(string $source): array
{
    $path = $source;

    if (preg_match('#^(https?://|git@|[\w.-]+\.\w+:)#', $source)) {
        $lang = 'auto';
        $frameworks = [];

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

    if (!is_dir($path)) {
        return ['language' => 'auto', 'frameworks' => [], 'confidence' => 0, 'error' => 'Path not found'];
    }

    $counts = ['PHP' => 0, 'Python' => 0, 'JavaScript' => 0, 'TypeScript' => 0, 'CSS' => 0, 'Go' => 0, 'Rust' => 0];
    $frameworks = [];

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
ROUTER;

        file_put_contents($routerPath, $routerCode);
        return $routerPath;
    }
}
