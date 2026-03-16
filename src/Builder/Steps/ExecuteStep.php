<?php

declare(strict_types=1);

namespace HalfBaked\Builder\Steps;

use HalfBaked\Builder\BuildRegistry;
use HalfBaked\Builder\BuildStatus;
use HalfBaked\Builder\ExpertRouter;
use HalfBaked\Builder\SubtaskExecutor;
use HalfBaked\Ollama\OllamaClient;
use HalfBaked\Pipeline\BakedStepInterface;

class ExecuteStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        $buildId = $input['build_id'] ?? throw new \RuntimeException('Missing build_id');
        $projectContext = $input['project_context'] ?? '';

        $registry = new BuildRegistry($input['db_path'] ?? 'data/builder.db');

        try {
            // Update status to Generating
            $registry->updateBuild($buildId, ['status' => BuildStatus::Generating->value]);
            $this->log("Starting subtask execution for build {$buildId}");

            // Create OllamaClient with host/port from input
            $ollamaHost = $input['ollama_host'] ?? '127.0.0.1';
            $ollamaPort = (int) ($input['ollama_port'] ?? 11434);
            $ollama = new OllamaClient($ollamaHost, $ollamaPort);

            // Create ExpertRouter with overrides from input
            $expertOverrides = $input['expert_overrides'] ?? [];
            $router = new ExpertRouter(overrides: $expertOverrides);

            // Create SubtaskExecutor and run
            $executor = new SubtaskExecutor($ollama, $router, $registry);
            $result = $executor->execute($buildId, $projectContext);

            $this->log("Execution complete: {$result['completed']}/{$result['total']} completed, {$result['failed']} failed");

            // Store result counts in build config
            $build = $registry->getBuild($buildId);
            $config = $build['config'] ?? [];
            $config['execution_result'] = $result;
            $registry->updateBuild($buildId, ['config' => $config]);

            // If all subtasks failed, mark build as failed
            if ($result['completed'] === 0 && $result['total'] > 0) {
                $registry->updateBuild($buildId, [
                    'status' => BuildStatus::Failed->value,
                    'error' => "All {$result['failed']} subtask(s) failed during execution",
                ]);
                $this->log("Build failed: all subtasks failed");
            }

            return array_merge($input, [
                'execution_result' => $result,
            ]);
        } catch (\Throwable $e) {
            $registry->updateBuild($buildId, [
                'status' => BuildStatus::Failed->value,
                'error' => 'Execution failed: ' . $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function log(string $message): void
    {
        $time = date('H:i:s');
        echo "[{$time}][execute] {$message}\n";
    }
}
