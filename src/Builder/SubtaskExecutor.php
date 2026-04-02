<?php

declare(strict_types=1);

namespace HalfBaked\Builder;

use HalfBaked\Ollama\OllamaClient;

/**
 * Executes subtasks in dependency order using specialist Ollama models.
 *
 * Port of VoidLux's TaskDispatcher dependency cascade + AgentBridge prerequisite
 * injection, adapted for local sequential execution via Ollama.
 */
class SubtaskExecutor
{
    private const MAX_RETRIES = 3;

    public function __construct(
        private readonly OllamaClient $ollama,
        private readonly ExpertRouter $router,
        private readonly BuildRegistry $registry,
    ) {}

    /**
     * Execute all subtasks for a build in dependency order.
     *
     * @param string $buildId Build ID
     * @param string $projectContext Project context string for prompts
     * @return array{completed: int, failed: int, total: int}
     */
    public function execute(string $buildId, string $projectContext = ''): array
    {
        $subtasks = $this->registry->getSubtasks($buildId);
        if (empty($subtasks)) {
            return ['completed' => 0, 'failed' => 0, 'total' => 0];
        }

        // Build local_id → subtask index map for dependency resolution
        $idMap = [];
        foreach ($subtasks as $i => $st) {
            if ($st['local_id']) {
                $idMap[$st['local_id']] = $i;
            }
        }

        // Initialize statuses: tasks with deps → blocked, others → pending
        foreach ($subtasks as &$st) {
            $deps = $st['depends_on'] ?? [];
            if (!empty($deps)) {
                $this->registry->updateSubtask($st['id'], ['status' => 'blocked']);
                $st['status'] = 'blocked';
            }
        }
        unset($st);

        // Route expert models for each subtask
        foreach ($subtasks as &$st) {
            $model = $this->router->route($st['domain'] ?? 'shared');
            $this->registry->updateSubtask($st['id'], ['expert_model' => $model]);
            $st['expert_model'] = $model;
        }
        unset($st);

        $completed = 0;
        $failed = 0;
        $total = count($subtasks);
        $maxIterations = $total * 2; // Safety valve
        $iteration = 0;

        while ($iteration < $maxIterations) {
            $iteration++;

            // Phase 1: Cascade-fail — blocked tasks whose deps failed
            $changed = $this->cascadeFail($subtasks, $idMap);

            // Phase 2: Unblock — blocked tasks whose deps all completed
            $changed = $this->unblockReady($subtasks, $idMap) || $changed;

            // Pick next pending subtask (highest priority first)
            $nextIdx = $this->pickNextPending($subtasks);
            if ($nextIdx === null) {
                // No pending tasks — check if we're done
                $allTerminal = true;
                foreach ($subtasks as $st) {
                    if (!in_array($st['status'], ['completed', 'failed'], true)) {
                        $allTerminal = false;
                        break;
                    }
                }
                if ($allTerminal || !$changed) {
                    break;
                }
                continue;
            }

            $subtask = &$subtasks[$nextIdx];
            $this->log("Executing [{$subtask['local_id']}] {$subtask['title']} via {$subtask['expert_model']}");

            // Mark as generating
            $this->registry->updateSubtask($subtask['id'], ['status' => 'generating']);
            $subtask['status'] = 'generating';
            $this->registry->refreshCounts($buildId);

            // Build prompt with prerequisite results
            $prompt = $this->buildPrompt($subtask, $subtasks, $idMap, $projectContext);

            // Persist the prompt for preference-pair harvesting
            $this->registry->updateSubtask($subtask['id'], ['prompt' => $prompt]);

            // Execute via Ollama with validate-retry loop
            $domain = $subtask['domain'] ?? 'backend';
            $attempt = 0;
            $lastResult = '';
            $lastFiles = [];
            $lastErrors = [];
            $attemptsLog = [];
            $success = false;

            while ($attempt < self::MAX_RETRIES) {
                $attempt++;
                try {
                    if ($attempt === 1) {
                        $currentPrompt = $prompt;
                    } else {
                        // Retry: append validation feedback to original prompt
                        $currentPrompt = $this->buildRetryPrompt($prompt, $lastResult, $lastErrors, $attempt);
                        $this->log("  Retry {$attempt}/" . self::MAX_RETRIES . " — feeding back " . count($lastErrors) . " error(s)");
                    }

                    $lastResult = $this->callOllama($subtask['expert_model'], $currentPrompt, $domain);
                    $lastFiles = $this->parseGeneratedFiles($lastResult);

                    // Validate the generated output
                    $lastErrors = $this->validateGenerated($lastFiles, $subtask);

                    // Log this attempt for DPO preference-pair harvesting
                    $attemptsLog[] = [
                        'attempt' => $attempt,
                        'output' => $lastResult,
                        'errors' => $lastErrors,
                        'accepted' => empty($lastErrors),
                    ];

                    if (empty($lastErrors)) {
                        $success = true;
                        break;
                    }

                    $this->log("  Attempt {$attempt}: " . count($lastErrors) . " validation error(s)");
                    foreach ($lastErrors as $err) {
                        $this->log("    - {$err}");
                    }
                } catch (\Throwable $e) {
                    $lastErrors = ["Generation error: {$e->getMessage()}"];
                    $attemptsLog[] = [
                        'attempt' => $attempt,
                        'output' => $lastResult,
                        'errors' => $lastErrors,
                        'accepted' => false,
                    ];
                    $this->log("  Attempt {$attempt} threw: {$e->getMessage()}");
                }
            }

            if ($success) {
                $this->registry->updateSubtask($subtask['id'], [
                    'status' => 'completed',
                    'generated_code' => $lastResult,
                    'files' => $lastFiles,
                    'attempts' => $attempt,
                    'attempts_log' => $attemptsLog,
                ]);
                $subtask['status'] = 'completed';
                $subtask['generated_code'] = $lastResult;
                $subtask['files'] = $lastFiles;
                $completed++;

                $msg = count($lastFiles) . " file(s) generated";
                if ($attempt > 1) {
                    $msg .= " (after {$attempt} attempts)";
                }
                $this->log("  Completed: {$msg}");
            } else {
                $errorSummary = implode('; ', $lastErrors);
                $this->registry->updateSubtask($subtask['id'], [
                    'status' => 'failed',
                    'error' => "Failed after {$attempt} attempts: {$errorSummary}",
                    'generated_code' => $lastResult, // Store last attempt for inspection
                    'files' => $lastFiles,
                    'attempts' => $attempt,
                    'attempts_log' => $attemptsLog,
                ]);
                $subtask['status'] = 'failed';
                $failed++;

                $this->log("  Failed after {$attempt} attempts: {$errorSummary}");
            }
            unset($subtask);

            $this->registry->refreshCounts($buildId);
        }

        // Count final states
        $completed = 0;
        $failed = 0;
        foreach ($subtasks as $st) {
            if ($st['status'] === 'completed') $completed++;
            if ($st['status'] === 'failed') $failed++;
        }

        return ['completed' => $completed, 'failed' => $failed, 'total' => $total];
    }

    /**
     * Cascade-fail: blocked tasks whose dependencies failed → failed.
     * Port of TaskDispatcher::failBlockedWithFailedDeps().
     */
    private function cascadeFail(array &$subtasks, array $idMap): bool
    {
        $changed = false;
        foreach ($subtasks as &$st) {
            if ($st['status'] !== 'blocked') continue;

            foreach ($st['depends_on'] as $depLocalId) {
                $depIdx = $idMap[$depLocalId] ?? null;
                if ($depIdx === null) continue;
                if ($subtasks[$depIdx]['status'] === 'failed') {
                    $this->registry->updateSubtask($st['id'], [
                        'status' => 'failed',
                        'error' => "Dependency '{$depLocalId}' failed",
                    ]);
                    $st['status'] = 'failed';
                    $changed = true;
                    $this->log("  Cascade-failed [{$st['local_id']}]: dependency '{$depLocalId}' failed");
                    break;
                }
            }
        }
        unset($st);
        return $changed;
    }

    /**
     * Unblock: blocked tasks whose deps all completed → pending.
     * Port of TaskDispatcher::unblockReadyTasks().
     */
    private function unblockReady(array &$subtasks, array $idMap): bool
    {
        $changed = false;
        foreach ($subtasks as &$st) {
            if ($st['status'] !== 'blocked') continue;

            $allCompleted = true;
            foreach ($st['depends_on'] as $depLocalId) {
                $depIdx = $idMap[$depLocalId] ?? null;
                if ($depIdx === null) {
                    $allCompleted = false;
                    break;
                }
                if ($subtasks[$depIdx]['status'] !== 'completed') {
                    $allCompleted = false;
                    break;
                }
            }

            if ($allCompleted) {
                $this->registry->updateSubtask($st['id'], ['status' => 'pending']);
                $st['status'] = 'pending';
                $changed = true;
                $this->log("  Unblocked [{$st['local_id']}]: all deps completed");
            }
        }
        unset($st);
        return $changed;
    }

    /**
     * Pick the next pending subtask with highest priority.
     */
    private function pickNextPending(array $subtasks): ?int
    {
        $bestIdx = null;
        $bestPriority = -1;

        foreach ($subtasks as $i => $st) {
            if ($st['status'] !== 'pending') continue;
            $priority = $st['sort_order'] ?? 0;
            if ($bestIdx === null || $priority > $bestPriority) {
                $bestIdx = $i;
                $bestPriority = $priority;
            }
        }

        return $bestIdx;
    }

    /**
     * Build the prompt for a subtask, including prerequisite results.
     * Port of AgentBridge::buildTaskPrompt() prerequisite injection.
     */
    private function buildPrompt(array $subtask, array $allSubtasks, array $idMap, string $projectContext): string
    {
        $prompt = "## Task: {$subtask['title']}\n\n";
        $prompt .= ($subtask['description'] ?? $subtask['title']) . "\n";

        if (!empty($subtask['work_instructions'])) {
            $prompt .= "\n## Work Instructions\n" . $subtask['work_instructions'] . "\n";
        }

        if (!empty($subtask['acceptance_criteria'])) {
            $prompt .= "\n## Acceptance Criteria\n" . $subtask['acceptance_criteria'] . "\n";
        }

        $prompt .= $this->buildPrerequisiteSection($subtask, $allSubtasks, $idMap);

        if ($projectContext !== '') {
            $prompt .= "\n## Project Context\n" . $projectContext . "\n";
        }

        $prompt .= "\n## Output Format\n";
        $prompt .= "Output ONLY code. For each file, use this format:\n\n";
        $prompt .= "--- FILE: path/to/file.php ---\n";
        $prompt .= "```\n<file content>\n```\n\n";
        $prompt .= "Start each file with the `--- FILE: <path> ---` marker.\n";
        $prompt .= "Write complete, production-ready code. No explanations.\n";

        return $prompt;
    }

    /**
     * Build a code-completion prompt for frontend models.
     *
     * Fine-tuned models like 'bootstrap' respond to code scaffolding,
     * not prose instructions. We embed task details as HTML comments
     * and provide a starter template to complete.
     */
    private function buildFrontendPrompt(array $subtask, array $allSubtasks, array $idMap, string $projectContext): string
    {
        $title = $subtask['title'] ?? 'UI Component';
        $description = $subtask['description'] ?? $title;
        $instructions = $subtask['work_instructions'] ?? '';

        // Extract view paths from work instructions if mentioned
        $viewPath = 'views/' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $title));

        // Build prerequisite context as a compact comment
        $prereqComment = '';
        $prereqSection = $this->buildPrerequisiteSection($subtask, $allSubtasks, $idMap);
        if ($prereqSection !== '') {
            // Truncate for 4k context models
            $prereqComment = "<!-- Backend context:\n" . substr($prereqSection, 0, 1500) . "\n-->\n";
        }

        // Provide a code scaffold the model can complete
        $prompt = "--- FILE: {$viewPath}/index.php ---\n";
        $prompt .= "```php\n";
        $prompt .= "<?php\n";
        $prompt .= "/* {$title}\n";
        $prompt .= " * {$description}\n";
        if ($instructions) {
            // Keep instructions compact for 4k context
            $prompt .= " * " . substr(str_replace("\n", "\n * ", $instructions), 0, 800) . "\n";
        }
        $prompt .= " */\n";
        $prompt .= "?>\n";
        $prompt .= $prereqComment;
        $prompt .= '<div class="container-fluid mt-4">' . "\n";
        $prompt .= "    <h2>{$title}</h2>\n";

        return $prompt;
    }

    /**
     * Build the prerequisite results section for a subtask prompt.
     */
    private function buildPrerequisiteSection(array $subtask, array $allSubtasks, array $idMap): string
    {
        if (empty($subtask['depends_on'])) {
            return '';
        }

        $depResults = [];
        foreach ($subtask['depends_on'] as $depLocalId) {
            $depIdx = $idMap[$depLocalId] ?? null;
            if ($depIdx === null) continue;
            $dep = $allSubtasks[$depIdx];
            if ($dep['status'] === 'completed' && !empty($dep['generated_code'])) {
                $depResults[] = $dep;
            }
        }

        if (empty($depResults)) {
            return '';
        }

        $section = "\n## Prerequisite Results\nThe following tasks completed before yours. Use their output as context:\n";
        foreach ($depResults as $dep) {
            $code = $dep['generated_code'];
            // Strip FILE markers so the parser doesn't extract them as real files
            $code = preg_replace('/^-*\s*FILE:\s*.+$/m', '// [prerequisite file reference]', $code);
            if (strlen($code) > 3000) {
                $code = substr($code, 0, 3000) . "\n... (truncated)";
            }
            $section .= "\n### [{$dep['local_id']}] {$dep['title']}\n{$code}\n";
        }

        return $section;
    }

    /**
     * Call Ollama to generate code for a subtask.
     */
    private function callOllama(string $model, string $prompt, string $domain = 'backend'): string
    {
        $system = match ($domain) {
            'frontend' => <<<'SYS'
You are an expert frontend developer specializing in Bootstrap 5.3, jQuery, and PHP templates.
You generate complete, production-ready HTML/PHP view files and JavaScript code.
Output ONLY code files. No commentary, no explanations, no CSS theory.
For each file use this exact marker on its own line: --- FILE: path/to/file.ext ---
Then write the complete file contents in a code block.
SYS,
            default => <<<'SYS'
You are an expert code generator. You write clean, production-ready code.
Follow the patterns, conventions, and frameworks described in the project context.
Output ONLY code files using the specified format. No commentary, no explanations.
For each file use this exact marker on its own line: --- FILE: path/to/file.ext ---
Then write the complete file contents in a code block.
SYS,
        };

        return $this->ollama->generateRaw($model, $prompt, temperature: 0.2, system: $system);
    }

    /**
     * Parse generated output into file entries.
     * Extracts files marked with FILE: path markers in various formats:
     *   --- FILE: path/to/file.php ---
     *   ---\nFILE: path/to/file.php
     *   FILE: path/to/file.php
     *
     * @return array<array{path: string, content: string, action: string}>
     */
    private function parseGeneratedFiles(string $output): array
    {
        $files = [];

        // Normalize multi-line "---\nFILE:" into single-line "--- FILE:" for easier parsing
        $normalized = preg_replace('/^---\s*\n\s*FILE:/m', '--- FILE:', $output);

        // Split on FILE markers: "--- FILE: <path> ---" or "--- FILE: <path>" or "FILE: <path>"
        $pattern = '/^-*\s*FILE:\s*(.+?)(?:\s*-+)?\s*$/m';
        $parts = preg_split($pattern, $normalized, -1, PREG_SPLIT_DELIM_CAPTURE);

        // parts[0] is text before first marker (ignored)
        // parts[1] is first path, parts[2] is first content, etc.
        for ($i = 1; $i < count($parts) - 1; $i += 2) {
            $path = trim($parts[$i]);
            $content = $parts[$i + 1] ?? '';

            // Strip markdown code fences
            $content = preg_replace('/^```\w*\s*\n?/m', '', $content);
            $content = preg_replace('/\n?```\s*$/m', '', $content);
            $content = trim($content);

            if ($path !== '' && $content !== '') {
                $files[] = [
                    'path' => $path,
                    'content' => $content,
                    'action' => 'create',
                ];
            }
        }

        // If no FILE markers found, try to extract a single file from the whole output
        if (empty($files) && trim($output) !== '') {
            $content = preg_replace('/^```\w*\s*\n?/m', '', $output);
            $content = preg_replace('/\n?```\s*$/m', '', $content);
            $content = trim($content);
            if ($content !== '') {
                $files[] = [
                    'path' => 'generated-output.txt',
                    'content' => $content,
                    'action' => 'create',
                ];
            }
        }

        return $files;
    }

    /**
     * Validate generated files — lint PHP, check for empty output, verify file markers.
     *
     * @return string[] List of error messages (empty = all valid)
     */
    private function validateGenerated(array $files, array $subtask): array
    {
        $errors = [];

        // Check 1: Did we get any files at all?
        if (empty($files)) {
            $errors[] = 'No output files generated';
            return $errors;
        }

        // Check 2: All files named generated-output.txt means no FILE markers were found
        $allGeneric = true;
        foreach ($files as $f) {
            if ($f['path'] !== 'generated-output.txt') {
                $allGeneric = false;
                break;
            }
        }
        if ($allGeneric && count($files) === 1) {
            $errors[] = 'No FILE markers found in output — code was not structured into named files';
        }

        // Check 3: Lint each PHP file
        foreach ($files as $f) {
            if (!str_ends_with(strtolower($f['path']), '.php')) {
                continue;
            }
            $lintError = $this->lintPhp($f['content'], $f['path']);
            if ($lintError !== null) {
                $errors[] = "PHP lint error in {$f['path']}: {$lintError}";
            }
        }

        // Check 4: Suspiciously small output (< 50 chars per file)
        foreach ($files as $f) {
            if (strlen($f['content']) < 50) {
                $errors[] = "File {$f['path']} is suspiciously small (" . strlen($f['content']) . " bytes)";
            }
        }

        return $errors;
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
            return null; // Can't create temp file — skip lint
        }

        try {
            file_put_contents($tmpFile, $content);
            $output = [];
            $exitCode = 0;
            exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);

            if ($exitCode !== 0) {
                $errorMsg = implode("\n", $output);
                return str_replace($tmpFile, $relativePath, $errorMsg);
            }

            return null;
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Build a retry prompt that includes the original task + validation feedback.
     */
    private function buildRetryPrompt(string $originalPrompt, string $previousOutput, array $errors, int $attempt): string
    {
        $prompt = $originalPrompt;

        $prompt .= "\n\n## RETRY — Previous Attempt Failed Validation\n";
        $prompt .= "Attempt {$attempt} of " . self::MAX_RETRIES . ". Fix the following errors:\n\n";

        foreach ($errors as $error) {
            $prompt .= "- {$error}\n";
        }

        // Include a truncated version of the previous output so the model can see what it did wrong
        $prevSnippet = $previousOutput;
        if (strlen($prevSnippet) > 2000) {
            $prevSnippet = substr($prevSnippet, 0, 2000) . "\n... (truncated)";
        }

        $prompt .= "\n## Your Previous Output (with errors)\n```\n{$prevSnippet}\n```\n";
        $prompt .= "\nGenerate the corrected version. Fix ALL listed errors. Output ONLY the corrected code files using the --- FILE: path --- format.\n";

        return $prompt;
    }

    private function log(string $message): void
    {
        $time = date('H:i:s');
        echo "[{$time}][executor] {$message}\n";
    }
}
