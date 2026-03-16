<?php

declare(strict_types=1);

namespace HalfBaked\Builder;

/**
 * Decomposes high-level feature requests into ordered subtasks using an LLM.
 *
 * Port of VoidLux's TaskPlanner::decompose() adapted for HalfBaked.
 */
class FeatureDecomposer
{
    public function __construct(
        private readonly ReasoningClient $client,
    ) {}

    /**
     * Decompose a feature request into ordered subtasks.
     *
     * @param string $feature The feature description
     * @param string $projectPath Path to the project
     * @param string $projectContext Pre-built project context string (from ScanProjectStep)
     * @return array[] Each: ['id', 'title', 'description', 'domain', 'work_instructions',
     *                        'acceptance_criteria', 'complexity', 'priority', 'dependsOn']
     */
    public function decompose(string $feature, string $projectPath, string $projectContext = ''): array
    {
        // Build project context if not provided
        if ($projectContext === '' && $projectPath !== '' && is_dir($projectPath)) {
            $projectContext = $this->getProjectContext($projectPath);
        }

        $systemPrompt = <<<'PROMPT'
You are a senior software architect. Given a high-level feature request and project structure, decompose it into specific subtasks that can be executed by specialist AI coding models.

Each subtask must be:
- Specific: reference exact files to create or modify, and describe the approach
- Verifiable: include clear acceptance criteria
- Tagged with a domain: backend, frontend, api, or shared

Subtasks CAN depend on each other. If subtask B needs the output of subtask A, declare the dependency.

Return ONLY a valid JSON array (no markdown fences, no explanation). Each element:
{
    "id": "subtask-1",
    "title": "Short imperative title",
    "description": "What this subtask accomplishes",
    "domain": "backend|frontend|api|shared",
    "work_instructions": "Specific files to modify/create, approach to take, code patterns to follow",
    "acceptance_criteria": "How to verify this subtask is done correctly",
    "complexity": "small|medium|large|xl",
    "priority": 0,
    "dependsOn": []
}

Rules:
- Return between 1 and 8 subtasks
- Each subtask must have a unique "id" (e.g., "subtask-1", "subtask-2")
- "domain" must be one of: backend, frontend, api, shared
- "dependsOn" is an array of other subtask IDs that must complete first
- Higher priority number = more important
- If the request is simple enough for one agent, return a single subtask
- Do NOT include testing/documentation subtasks unless explicitly requested
- Only declare dependencies when truly needed
PROMPT;

        $userPrompt = "## Feature Request\n{$feature}\n";
        if ($projectContext !== '') {
            $userPrompt .= "\n## Project Structure\n{$projectContext}\n";
        }

        $this->log("Decomposing feature: " . substr($feature, 0, 120));
        $response = $this->client->chat($systemPrompt, $userPrompt);
        if ($response === null) {
            $this->log("LLM returned null -- decomposition failed");
            return [];
        }

        $this->log("LLM response (" . strlen($response) . " chars): " . substr($response, 0, 200));
        $subtasks = $this->parseResponse($response);
        $this->log("Parsed " . count($subtasks) . " subtask(s)");

        // Generate architecture context and inject into each subtask (multi-subtask only)
        if (count($subtasks) > 1) {
            $archContext = $this->generateArchitectureContext($feature, $subtasks);
            if ($archContext !== '') {
                foreach ($subtasks as &$st) {
                    $st['work_instructions'] = $st['work_instructions'] . "\n\n" . $archContext;
                }
                unset($st);
                $this->log("Injected architecture context (" . strlen($archContext) . " chars) into " . count($subtasks) . " subtasks");
            }
        }

        return $subtasks;
    }

    /**
     * Generate architecture context that gets injected into every subtask.
     *
     * Asks the LLM to produce a data flow map showing how new fields/concepts
     * connect across files, so each agent understands the full picture.
     */
    private function generateArchitectureContext(string $feature, array $subtasks): string
    {
        $subtaskSummary = '';
        foreach ($subtasks as $i => $st) {
            $subtaskSummary .= ($i + 1) . ". [{$st['id']}] {$st['title']}: {$st['description']}\n";
            if (!empty($st['work_instructions'])) {
                $subtaskSummary .= "   Files: " . $this->extractFilePaths($st['work_instructions']) . "\n";
            }
        }

        $systemPrompt = <<<'PROMPT'
You are a senior architect. Given a parent feature decomposed into subtasks, produce a concise "Architecture Context" section that every agent will receive.

This section must describe:
1. **Data Flow**: How new fields, concepts, or data structures flow across files and subtasks. Show the chain: where data originates -> where it's stored -> where it's consumed.
2. **Integration Points**: Which files/methods connect subtasks together. If subtask A adds a field and subtask B reads it, name the exact field and both locations.
3. **DB Migrations**: If any new database columns are needed, list them explicitly.
4. **Naming Conventions**: If a field/method name is chosen in one subtask, all other subtasks MUST use the same name.

Keep it under 500 words. Use bullet points. Be specific -- reference exact file paths and method names where possible. Do NOT repeat the subtask descriptions.

Return ONLY the architecture context text (no JSON, no fences).
PROMPT;

        $userPrompt = "## Feature\n{$feature}\n\n## Subtasks\n{$subtaskSummary}";

        $this->log("Generating architecture context for " . count($subtasks) . " subtasks");
        $response = $this->client->chat($systemPrompt, $userPrompt);
        if ($response === null || trim($response) === '') {
            $this->log("Architecture context generation failed or empty");
            return '';
        }

        return "## Architecture Context (shared across all subtasks)\n\n" . trim($response);
    }

    /**
     * Extract file paths mentioned in work instructions for the architecture context.
     */
    private function extractFilePaths(string $text): string
    {
        preg_match_all('/(?:src\/|bin\/|scripts\/|tests?\/|public\/|config\/)[\w\/\-.]+\.\w+/', $text, $matches);
        $paths = array_unique($matches[0] ?? []);
        return $paths ? implode(', ', array_slice($paths, 0, 5)) : '(unspecified)';
    }

    /**
     * Get project directory tree + README + project type for LLM context.
     */
    private function getProjectContext(string $projectPath): string
    {
        $lines = [];
        $this->scanDir($projectPath, '', $lines, 0, 2);

        $tree = implode("\n", $lines);

        // Detect project type
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

        // Limit total context
        if (strlen($tree) > 4000) {
            $tree = substr($tree, 0, 4000) . "\n... (truncated)";
        }

        return $tree;
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

    /**
     * Recursively scan a directory up to maxDepth, building a display tree.
     */
    private function scanDir(string $basePath, string $prefix, array &$lines, int $depth, int $maxDepth): void
    {
        if ($depth > $maxDepth) {
            return;
        }

        $skip = ['vendor', 'node_modules', '.git', '.idea', 'data', '__pycache__', '.cache'];
        $entries = @scandir($basePath);
        if ($entries === false) {
            return;
        }

        $entries = array_diff($entries, ['.', '..']);
        sort($entries);

        foreach ($entries as $entry) {
            if (in_array($entry, $skip, true)) {
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
     * Parse the LLM JSON response into a validated subtask array.
     *
     * Strips markdown fences, validates required fields, normalizes complexity.
     */
    private function parseResponse(string $response): array
    {
        // Strip markdown fences if present
        $response = preg_replace('/^```(?:json)?\s*\n?/m', '', $response);
        $response = preg_replace('/\n?```\s*$/m', '', $response);
        $response = trim($response);

        $data = json_decode($response, true);
        if (!is_array($data)) {
            $this->log("Failed to parse JSON response: " . json_last_error_msg());
            return [];
        }

        $validDomains = ['backend', 'frontend', 'api', 'shared'];
        $validComplexity = ['small', 'medium', 'large', 'xl'];

        $subtasks = [];
        foreach ($data as $item) {
            if (!is_array($item) || empty($item['title'])) {
                continue;
            }

            $complexity = (string) ($item['complexity'] ?? 'medium');
            if (!in_array($complexity, $validComplexity, true)) {
                $complexity = 'medium';
            }

            $domain = (string) ($item['domain'] ?? 'shared');
            if (!in_array($domain, $validDomains, true)) {
                $domain = 'shared';
            }

            $subtasks[] = [
                'id' => (string) ($item['id'] ?? 'subtask-' . (count($subtasks) + 1)),
                'title' => (string) $item['title'],
                'description' => (string) ($item['description'] ?? ''),
                'domain' => $domain,
                'work_instructions' => (string) ($item['work_instructions'] ?? ''),
                'acceptance_criteria' => (string) ($item['acceptance_criteria'] ?? ''),
                'complexity' => $complexity,
                'priority' => (int) ($item['priority'] ?? 0),
                'dependsOn' => (array) ($item['dependsOn'] ?? []),
            ];
        }

        return $subtasks;
    }

    private function log(string $message): void
    {
        $time = date('H:i:s');
        echo "[{$time}][decomposer] {$message}\n";
    }
}
