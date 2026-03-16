<?php

declare(strict_types=1);

namespace HalfBaked\Builder\Steps;

use HalfBaked\Builder\BuildRegistry;
use HalfBaked\Builder\BuildStatus;
use HalfBaked\Builder\ProjectAssembler;
use HalfBaked\Pipeline\BakedStepInterface;

class AssembleStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        $buildId = $input['build_id'] ?? throw new \RuntimeException('Missing build_id');
        $projectPath = $input['project_path'] ?? throw new \RuntimeException('Missing project_path');
        $outputDir = $input['output_dir'] ?? '';

        $registry = new BuildRegistry($input['db_path'] ?? 'data/builder.db');

        try {
            // Update status to Assembling
            $registry->updateBuild($buildId, ['status' => BuildStatus::Assembling->value]);
            $this->log("Assembling build {$buildId} into {$projectPath}");

            // Get all completed subtasks for the build
            $subtasks = $registry->getSubtasks($buildId);
            $subtaskResults = [];

            foreach ($subtasks as $st) {
                if ($st['status'] !== 'completed') {
                    continue;
                }

                $files = $st['files'];
                if (is_string($files)) {
                    $files = json_decode($files, true);
                }

                if (!empty($files)) {
                    $subtaskResults[] = [
                        'title' => $st['title'],
                        'files' => $files,
                    ];
                }
            }

            $this->log("Found " . count($subtaskResults) . " completed subtask(s) with files");

            // Create ProjectAssembler and run
            $assembler = new ProjectAssembler($outputDir);
            $assemblyResult = $assembler->assemble($subtaskResults, $projectPath);

            $this->log("Assembled: " . count($assemblyResult['created']) . " created, "
                . count($assemblyResult['modified']) . " modified, "
                . count($assemblyResult['errors']) . " errors");

            // Store result summary in build
            $registry->updateBuild($buildId, [
                'result' => $assemblyResult,
            ]);

            // Determine final status
            $hasOutput = !empty($assemblyResult['created']) || !empty($assemblyResult['modified']);
            $hasCriticalErrors = !empty($assemblyResult['errors']) && !$hasOutput;
            $allFailed = empty($subtaskResults) && count($subtasks) > 0;

            if ($allFailed) {
                $failedCount = count($subtasks);
                $registry->updateBuild($buildId, [
                    'status' => BuildStatus::Failed->value,
                    'error' => "All {$failedCount} subtask(s) failed — nothing to assemble",
                ]);
                $this->log("Build failed: all {$failedCount} subtasks failed");
            } elseif ($hasCriticalErrors) {
                $registry->updateBuild($buildId, [
                    'status' => BuildStatus::Failed->value,
                    'error' => 'Assembly produced no files. Errors: ' . implode('; ', $assemblyResult['errors']),
                ]);
                $this->log("Build failed: assembly produced no output files");
            } else {
                $registry->updateBuild($buildId, [
                    'status' => BuildStatus::Done->value,
                ]);
                $this->log("Build complete");
            }

            return array_merge($input, [
                'assembly_result' => $assemblyResult,
            ]);
        } catch (\Throwable $e) {
            $registry->updateBuild($buildId, [
                'status' => BuildStatus::Failed->value,
                'error' => 'Assembly failed: ' . $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function log(string $message): void
    {
        $time = date('H:i:s');
        echo "[{$time}][assemble] {$message}\n";
    }
}
