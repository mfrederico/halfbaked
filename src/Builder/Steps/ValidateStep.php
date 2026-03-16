<?php

declare(strict_types=1);

namespace HalfBaked\Builder\Steps;

use HalfBaked\Builder\BuildRegistry;
use HalfBaked\Builder\BuildStatus;
use HalfBaked\Pipeline\BakedStepInterface;

class ValidateStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        $buildId = $input['build_id'] ?? throw new \RuntimeException('Missing build_id');

        $registry = new BuildRegistry($input['db_path'] ?? 'data/builder.db');

        try {
            // Update status to Validating
            $registry->updateBuild($buildId, ['status' => BuildStatus::Validating->value]);
            $this->log("Validating build {$buildId}");

            // Stub: Gorilla-based API contract validation is Phase 3
            $this->log("Validation is a stub -- Gorilla API validation planned for Phase 3");

            $validationResult = ['passed' => true];

            return array_merge($input, [
                'validation_result' => $validationResult,
            ]);
        } catch (\Throwable $e) {
            $registry->updateBuild($buildId, [
                'status' => BuildStatus::Failed->value,
                'error' => 'Validation failed: ' . $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function log(string $message): void
    {
        $time = date('H:i:s');
        echo "[{$time}][validate] {$message}\n";
    }
}
