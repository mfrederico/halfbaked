<?php

declare(strict_types=1);

namespace HalfBaked\Builder;

/**
 * Writes generated files from completed subtasks to the project directory.
 *
 * Handles create, modify, and append actions with path traversal protection.
 */
class ProjectAssembler
{
    public function __construct(
        private readonly string $outputDir = '',
    ) {}

    /**
     * Assemble generated files from subtask results into the project.
     *
     * @param array[] $subtaskResults Each has: 'title', 'files' => [['path' => string, 'content' => string, 'action' => 'create'|'modify'|'append']]
     * @param string $projectPath Base project directory
     * @return array{created: string[], modified: string[], errors: string[]}
     */
    public function assemble(array $subtaskResults, string $projectPath): array
    {
        $result = [
            'created' => [],
            'modified' => [],
            'errors' => [],
        ];

        $basePath = $this->outputDir !== '' ? $this->outputDir : $projectPath;

        foreach ($subtaskResults as $subtask) {
            $title = $subtask['title'] ?? 'unknown';
            $files = $subtask['files'] ?? [];

            if (empty($files)) {
                $this->log("Subtask '{$title}' has no files to assemble");
                continue;
            }

            $this->log("Assembling " . count($files) . " file(s) from subtask: {$title}");

            foreach ($files as $file) {
                $path = $file['path'] ?? '';
                $content = $file['content'] ?? '';
                $action = $file['action'] ?? 'create';

                if ($path === '') {
                    $result['errors'][] = "Empty path in subtask '{$title}'";
                    continue;
                }

                $success = $this->writeFile($basePath, $path, $content, $action);

                if ($success) {
                    if ($action === 'create') {
                        $result['created'][] = $path;
                    } else {
                        $result['modified'][] = $path;
                    }
                } else {
                    $result['errors'][] = "{$action} failed: {$path}";
                }
            }
        }

        $this->log("Assembly complete: " . count($result['created']) . " created, "
            . count($result['modified']) . " modified, "
            . count($result['errors']) . " errors");

        return $result;
    }

    /**
     * Write a single file, creating directories as needed.
     *
     * @param string $basePath Project root
     * @param string $relativePath File path relative to project root
     * @param string $content File content
     * @param string $action create, modify, or append
     * @return bool Success
     */
    public function writeFile(string $basePath, string $relativePath, string $content, string $action = 'create'): bool
    {
        $fullPath = $basePath . '/' . ltrim($relativePath, '/');

        // Security: resolve real path components and verify no traversal
        $resolvedBase = realpath($basePath);
        if ($resolvedBase === false) {
            $this->log("Base path does not exist: {$basePath}");
            return false;
        }

        // Normalize the target path (resolve ./ and ../ without requiring the file to exist)
        $normalizedDir = dirname($fullPath);
        if (!is_dir($normalizedDir)) {
            if (!mkdir($normalizedDir, 0755, true)) {
                $this->log("Failed to create directory: {$normalizedDir}");
                return false;
            }
        }

        $resolvedDir = realpath($normalizedDir);
        if ($resolvedDir === false || !str_starts_with($resolvedDir, $resolvedBase)) {
            $this->log("Path traversal detected: {$relativePath}");
            return false;
        }

        $resolvedFull = $resolvedDir . '/' . basename($fullPath);
        if (!str_starts_with($resolvedFull, $resolvedBase)) {
            $this->log("Path traversal detected: {$relativePath}");
            return false;
        }

        // Lint PHP files before writing
        if (str_ends_with(strtolower($relativePath), '.php')) {
            $lintError = $this->lintPhp($content, $relativePath);
            if ($lintError !== null) {
                $this->log("LINT FAIL: {$relativePath} — {$lintError}");
                // Write to .lint-failed/ so it can be inspected but doesn't break the project
                $failedPath = $resolvedBase . '/.lint-failed/' . ltrim($relativePath, '/');
                $failedDir = dirname($failedPath);
                if (!is_dir($failedDir)) {
                    mkdir($failedDir, 0755, true);
                }
                file_put_contents($failedPath, $content);
                return false;
            }
        }

        try {
            switch ($action) {
                case 'append':
                    $written = file_put_contents($resolvedFull, $content, FILE_APPEND);
                    if ($written === false) {
                        $this->log("Failed to append to: {$relativePath}");
                        return false;
                    }
                    $this->log("Appended to: {$relativePath}");
                    return true;

                case 'modify':
                case 'create':
                default:
                    $written = file_put_contents($resolvedFull, $content);
                    if ($written === false) {
                        $this->log("Failed to write: {$relativePath}");
                        return false;
                    }
                    $this->log(ucfirst($action) . ": {$relativePath} ({$written} bytes)");
                    return true;
            }
        } catch (\Throwable $e) {
            $this->log("Write error for {$relativePath}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Generate a human-readable summary of assembled files.
     *
     * @param array{created: string[], modified: string[], errors: string[]} $result
     * @return string Formatted summary
     */
    public function summarize(array $result): string
    {
        $lines = ['Build Assembly Summary:'];

        // Created
        $createdCount = count($result['created'] ?? []);
        $lines[] = "  Created: {$createdCount} file" . ($createdCount !== 1 ? 's' : '');
        foreach ($result['created'] ?? [] as $path) {
            $lines[] = "    - {$path}";
        }

        // Modified
        $modifiedCount = count($result['modified'] ?? []);
        $lines[] = "  Modified: {$modifiedCount} file" . ($modifiedCount !== 1 ? 's' : '');
        foreach ($result['modified'] ?? [] as $path) {
            $lines[] = "    - {$path}";
        }

        // Errors
        $errorCount = count($result['errors'] ?? []);
        $lines[] = "  Errors: {$errorCount}";
        foreach ($result['errors'] ?? [] as $error) {
            $lines[] = "    - {$error}";
        }

        return implode("\n", $lines);
    }

    /**
     * Lint PHP content using php -l.
     *
     * @return string|null Error message, or null if valid
     */
    private function lintPhp(string $content, string $relativePath): ?string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'hb_lint_');
        if ($tmpFile === false) {
            return null; // Can't create temp file — skip lint, don't block
        }

        try {
            file_put_contents($tmpFile, $content);
            $output = [];
            $exitCode = 0;
            exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);

            if ($exitCode !== 0) {
                $errorMsg = implode("\n", $output);
                // Replace temp file path with the real relative path for readability
                $errorMsg = str_replace($tmpFile, $relativePath, $errorMsg);
                return $errorMsg;
            }

            return null;
        } finally {
            @unlink($tmpFile);
        }
    }

    private function log(string $message): void
    {
        $time = date('H:i:s');
        echo "[{$time}][assembler] {$message}\n";
    }
}
