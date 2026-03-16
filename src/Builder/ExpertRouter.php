<?php

declare(strict_types=1);

namespace HalfBaked\Builder;

use HalfBaked\Distillery\ExpertRegistry;

/**
 * Maps subtask domain to the correct Ollama model.
 *
 * Uses the Distillery's ExpertRegistry to discover available trained experts,
 * with manual overrides and sensible defaults.
 */
class ExpertRouter
{
    /** Gorilla model — reserved for contract validation (Phase 3), NOT code generation. */
    public const GORILLA_MODEL = 'adrienbrault/gorilla-openfunctions-v2:Q6_K';

    /** Domain → expert language mapping for registry lookups.
     * All code-generation domains use the PHP expert (voidlux-coder)
     * which was trained on the full project including views/, controls/, routes, etc.
     * Gorilla is NOT a code generator — it's a function-calling model for API routing.
     */
    private const DOMAIN_LANGUAGE_MAP = [
        'backend' => 'php',
        'frontend' => 'php',
        'api' => 'php',
    ];

    /**
     * @param string $defaultModel Fallback Ollama model if no expert matches
     * @param array<string,string> $overrides Manual domain->model overrides (e.g. ['backend' => 'voidlux-coder'])
     * @param string|null $registryDbPath Path to experts.db (default: data/experts.db)
     */
    public function __construct(
        private readonly string $defaultModel = 'qwen2.5-coder:7b',
        private readonly array $overrides = [],
        private readonly ?string $registryDbPath = null,
    ) {}

    /**
     * Route a subtask to the appropriate Ollama model.
     *
     * Priority: manual overrides > registry experts > default
     *
     * @param string $domain The subtask domain: backend, frontend, api, shared
     * @return string The Ollama model name to use
     */
    public function route(string $domain): string
    {
        $domain = strtolower(trim($domain));

        // 1. Check manual overrides first
        if (isset($this->overrides[$domain])) {
            $model = $this->overrides[$domain];
            $this->log("Override for '{$domain}': {$model}");
            return $model;
        }

        // 2. Check ExpertRegistry for matching trained experts
        $language = self::DOMAIN_LANGUAGE_MAP[$domain] ?? null;
        if ($language !== null) {
            $model = $this->findExpertModel($language);
            if ($model !== null) {
                $this->log("Registry expert for '{$domain}' (language={$language}): {$model}");
                return $model;
            }
            $this->log("No ready expert for '{$domain}' (language={$language}), using default");
        }

        // 4. Fall back to default model
        $this->log("Default model for '{$domain}': {$this->defaultModel}");
        return $this->defaultModel;
    }

    /**
     * Get the routing table (for display).
     *
     * Returns a map of all known domains to their resolved models.
     * Useful for CLI --dry-run output and web UI display.
     *
     * @return array<string, string> domain => model name
     */
    public function getRoutingTable(): array
    {
        $domains = ['backend', 'frontend', 'api', 'shared'];
        $table = [];

        foreach ($domains as $domain) {
            $table[$domain] = $this->route($domain);
        }

        return $table;
    }

    /**
     * Look up a ready expert model from the ExpertRegistry by language.
     *
     * @param string $language Language to match (e.g. 'php', 'css')
     * @return string|null Ollama model name or null if none found
     */
    private function findExpertModel(string $language): ?string
    {
        try {
            $dbPath = $this->registryDbPath ?? 'data/experts.db';

            if (!file_exists($dbPath)) {
                $this->log("Expert registry DB not found: {$dbPath}");
                return null;
            }

            $registry = new ExpertRegistry($dbPath);
            $experts = $registry->list();

            foreach ($experts as $expert) {
                if (
                    strtolower($expert['language'] ?? '') === $language
                    && ($expert['status'] ?? '') === 'ready'
                ) {
                    return $expert['name'];
                }
            }

            return null;
        } catch (\Throwable $e) {
            $this->log("Registry lookup failed: {$e->getMessage()}");
            return null;
        }
    }

    private function log(string $message): void
    {
        $time = date('H:i:s');
        echo "[{$time}][router] {$message}\n";
    }
}
