<?php

declare(strict_types=1);

namespace HalfBaked\Builder\Steps;

use HalfBaked\Builder\BuildRegistry;
use HalfBaked\Builder\BuildStatus;
use HalfBaked\Builder\FeatureDecomposer;
use HalfBaked\Builder\ReasoningClient;
use HalfBaked\Pipeline\BakedStepInterface;

class DecomposeStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        $buildId = $input['build_id'] ?? throw new \RuntimeException('Missing build_id');
        $task = $input['task'] ?? throw new \RuntimeException('Missing task');
        $projectPath = $input['project_path'] ?? '';
        $projectContext = $input['project_context'] ?? '';

        $registry = new BuildRegistry($input['db_path'] ?? 'data/builder.db');

        try {
            // Update status to Decomposing
            $registry->updateBuild($buildId, ['status' => BuildStatus::Decomposing->value]);
            $this->log("Decomposing task: " . substr($task, 0, 120));

            // Create ReasoningClient from input config
            $provider = $input['decomposer'] ?? 'anthropic';
            $model = $input['decomposer_model'] ?: ($provider === 'anthropic' ? 'claude-sonnet-4-20250514' : '');
            $apiKey = $input['api_key'] ?? '';
            $apiBase = $input['api_base'] ?: ($provider === 'anthropic' ? 'https://api.anthropic.com' : '');

            $client = new ReasoningClient($provider, $model, $apiKey, $apiBase);
            $decomposer = new FeatureDecomposer($client);

            // Decompose the feature
            $subtasks = $decomposer->decompose($task, $projectPath, $projectContext);

            if (empty($subtasks)) {
                throw new \RuntimeException('Decomposition returned no subtasks');
            }

            $this->log("Decomposed into " . count($subtasks) . " subtask(s)");

            // Two-pass storage: first create all subtasks, then resolve dependency local IDs
            // Pass 1: Create subtasks and build local_id -> real_id map
            $localIdMap = [];
            foreach ($subtasks as $st) {
                $localId = $st['id'] ?? 'subtask-' . (count($localIdMap) + 1);
                $hasDeps = !empty($st['dependsOn']);

                $realId = $registry->createSubtask($buildId, [
                    'local_id' => $localId,
                    'title' => $st['title'],
                    'description' => $st['description'] ?? '',
                    'domain' => $st['domain'] ?? 'shared',
                    'complexity' => $st['complexity'] ?? 'medium',
                    'work_instructions' => $st['work_instructions'] ?? '',
                    'acceptance_criteria' => $st['acceptance_criteria'] ?? '',
                    'sort_order' => $st['priority'] ?? 0,
                    'depends_on' => $st['dependsOn'] ?? [],
                ]);

                $localIdMap[$localId] = $realId;

                // Set initial status based on dependencies
                $status = $hasDeps ? 'blocked' : 'pending';
                $registry->updateSubtask($realId, ['status' => $status]);

                $this->log("  Created [{$localId}] {$st['title']} -> {$status}");
            }

            // Pass 2: Resolve local dependency IDs (deps are already stored as local IDs,
            // which is how SubtaskExecutor expects them -- no real ID resolution needed)

            // Refresh counts
            $registry->refreshCounts($buildId);

            // Update build with decomposer info
            $registry->updateBuild($buildId, [
                'decomposer' => $provider,
                'decomposer_model' => $model,
            ]);

            return array_merge($input, [
                'subtasks' => $subtasks,
            ]);
        } catch (\Throwable $e) {
            $registry->updateBuild($buildId, [
                'status' => BuildStatus::Failed->value,
                'error' => 'Decomposition failed: ' . $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function log(string $message): void
    {
        $time = date('H:i:s');
        echo "[{$time}][decompose] {$message}\n";
    }
}
