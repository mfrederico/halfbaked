<?php

declare(strict_types=1);

namespace HalfBaked\Pipeline;

class PipelineResult
{
    public function __construct(
        public readonly string $pipeline,
        public readonly bool $success,
        /** @var StepResult[] */
        public readonly array $steps,
        public readonly ?array $finalData,
        public readonly float $totalDuration,
        public readonly ?string $failedStep = null,
    ) {}

    /**
     * Get a summary showing which steps ran in which mode and how long.
     */
    public function summary(): string
    {
        $lines = ["Pipeline: {$this->pipeline} — " . ($this->success ? 'SUCCESS' : "FAILED at {$this->failedStep}")];
        foreach ($this->steps as $step) {
            $ms = round($step->duration * 1000, 1);
            $icon = $step->success ? '+' : 'x';
            $lines[] = "  [{$icon}] {$step->step} ({$step->mode}" . ($step->model ? ":{$step->model}" : '') . ") {$ms}ms";
        }
        $lines[] = "  Total: " . round($this->totalDuration * 1000, 1) . "ms";
        return implode("\n", $lines);
    }
}
