<?php

declare(strict_types=1);

namespace HalfBaked\Builder\Steps;

use HalfBaked\Builder\BuildRegistry;
use HalfBaked\Builder\BuildStatus;
use HalfBaked\Distillery\LanguageDetector;
use HalfBaked\Pipeline\BakedStepInterface;

class ScanProjectStep implements BakedStepInterface
{
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git', '.idea', 'data', '__pycache__', '.cache', '.venv', 'venv', 'dist', 'build'];

    public function execute(array $input): array
    {
        $buildId = $input['build_id'] ?? throw new \RuntimeException('Missing build_id');
        $projectPath = $input['project_path'] ?? throw new \RuntimeException('Missing project_path');
        $task = $input['task'] ?? '';

        $registry = new BuildRegistry($input['db_path'] ?? 'data/builder.db');

        try {
            // Update status to Scanning
            $registry->updateBuild($buildId, ['status' => BuildStatus::Scanning->value]);

            // Clone git repos to a local workspace
            if ($this->isGitUrl($projectPath)) {
                $dataDir = dirname($input['db_path'] ?? 'data/builder.db');
                $cloneDir = $dataDir . '/builds/' . $buildId . '/repo';
                if (is_dir($cloneDir . '/.git')) {
                    $this->log("Using existing clone: {$cloneDir}");
                } else {
                    $this->log("Cloning git repo: {$projectPath}");
                    if (!is_dir($cloneDir)) {
                        mkdir($cloneDir, 0755, true);
                    }
                    $cmd = sprintf('git clone --depth=1 %s %s 2>&1', escapeshellarg($projectPath), escapeshellarg($cloneDir));
                    $output = [];
                    $exitCode = 0;
                    exec($cmd, $output, $exitCode);
                    if ($exitCode !== 0) {
                        throw new \RuntimeException('Git clone failed: ' . implode("\n", $output));
                    }
                    $this->log("Cloned to: {$cloneDir}");
                }
                $input['project_path'] = $cloneDir;
                $projectPath = $cloneDir;
                // Store original URL and clone path in build config
                $build = $registry->getBuild($buildId);
                $config = $build['config'] ?? [];
                $config['git_url'] = $build['project_path'];
                $config['clone_path'] = $cloneDir;
                $registry->updateBuild($buildId, ['config' => $config]);
            }

            $this->log("Scanning project: {$projectPath}");

            // Detect language and frameworks
            $detector = new LanguageDetector();
            $detection = $detector->detect($projectPath);
            $language = $detection['language'] ?? 'unknown';
            $frameworks = $detection['frameworks'] ?? [];

            $this->log("Detected language: {$language}, frameworks: " . implode(', ', $frameworks ?: ['none']));

            // Build project context: file tree + README + project type
            $projectContext = $this->buildProjectContext($projectPath);

            // Store context in build config for UI display
            $build = $registry->getBuild($buildId);
            $config = $build['config'] ?? [];
            $config['project_context'] = $projectContext;
            $config['language'] = $language;
            $config['frameworks'] = $frameworks;
            $registry->updateBuild($buildId, ['config' => $config]);

            $this->log("Project context built (" . strlen($projectContext) . " chars)");

            return array_merge($input, [
                'project_context' => $projectContext,
                'language' => $language,
                'frameworks' => $frameworks,
            ]);
        } catch (\Throwable $e) {
            $registry->updateBuild($buildId, [
                'status' => BuildStatus::Failed->value,
                'error' => 'Scan failed: ' . $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Build project context string: file tree (depth 2), README, project type.
     */
    private function buildProjectContext(string $projectPath): string
    {
        $lines = [];
        $this->scanDir($projectPath, '', $lines, 0, 2);
        $tree = implode("\n", $lines);

        // Detect project type from marker files
        $projectType = $this->detectProjectType($projectPath);
        if ($projectType !== '') {
            $tree = "## Project Type\n{$projectType}\n\n## File Tree\n" . $tree;
        }

        // Include README if present
        $readmePath = $projectPath . '/README.md';
        if (file_exists($readmePath)) {
            $readme = file_get_contents($readmePath);
            if (strlen($readme) > 2000) {
                $readme = substr($readme, 0, 2000) . "\n... (truncated)";
            }
            $tree .= "\n\n## README.md\n{$readme}";
        }

        // Limit total context size
        if (strlen($tree) > 4000) {
            $tree = substr($tree, 0, 4000) . "\n... (truncated)";
        }

        return $tree;
    }

    /**
     * Recursively scan a directory up to maxDepth, building a display tree.
     */
    private function scanDir(string $basePath, string $prefix, array &$lines, int $depth, int $maxDepth): void
    {
        if ($depth > $maxDepth) {
            return;
        }

        $entries = @scandir($basePath);
        if ($entries === false) {
            return;
        }

        $entries = array_diff($entries, ['.', '..']);
        sort($entries);

        foreach ($entries as $entry) {
            if (in_array($entry, self::SKIP_DIRS, true)) {
                continue;
            }

            $fullPath = $basePath . '/' . $entry;
            $display = $prefix . $entry;

            if (is_dir($fullPath)) {
                $lines[] = $display . '/';
                $this->scanDir($fullPath, $display . '/', $lines, $depth + 1, $maxDepth);
            } else {
                $lines[] = $display;
            }
        }
    }

    /**
     * Detect the project's primary language/framework from marker files.
     */
    private function detectProjectType(string $projectPath): string
    {
        $markers = [
            'composer.json'    => 'PHP',
            'package.json'     => 'JavaScript/TypeScript',
            'requirements.txt' => 'Python',
            'pyproject.toml'   => 'Python',
            'Cargo.toml'       => 'Rust',
            'go.mod'           => 'Go',
            'Gemfile'          => 'Ruby',
            'build.gradle'     => 'Java/Kotlin',
            'pom.xml'          => 'Java',
            'mix.exs'          => 'Elixir',
            'tsconfig.json'    => 'TypeScript',
            'CMakeLists.txt'   => 'C/C++',
        ];

        $detected = [];
        foreach ($markers as $file => $lang) {
            if (file_exists($projectPath . '/' . $file)) {
                $detected[$lang] = $file;
            }
        }

        if (empty($detected)) {
            return '';
        }

        $primary = array_key_first($detected);
        $markerFile = $detected[$primary];
        $result = "This is a **{$primary}** project (detected via {$markerFile}).";

        if (count($detected) > 1) {
            $others = array_diff_key($detected, [$primary => true]);
            $result .= " Also uses: " . implode(', ', array_keys($others)) . ".";
        }

        return $result;
    }

    private function isGitUrl(string $path): bool
    {
        return (bool) preg_match('#^(https?://|git@|ssh://|[\w.-]+\.\w+:)#', $path);
    }

    private function log(string $message): void
    {
        $time = date('H:i:s');
        echo "[{$time}][scan] {$message}\n";
    }
}
