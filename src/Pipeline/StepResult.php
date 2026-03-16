<?php

declare(strict_types=1);

namespace HalfBaked\Pipeline;

class StepResult
{
    public function __construct(
        public readonly string $step,
        public readonly bool $success,
        public readonly array $data,
        public readonly float $duration,
        public readonly string $mode,    // 'llm' or 'baked'
        public readonly ?string $model,
    ) {}
}
