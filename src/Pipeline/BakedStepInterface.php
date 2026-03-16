<?php

declare(strict_types=1);

namespace HalfBaked\Pipeline;

/**
 * Interface for baked (deterministic) pipeline steps.
 *
 * Implement this in your generated or hand-written PHP classes.
 * The pipeline will call execute() instead of the LLM.
 */
interface BakedStepInterface
{
    /**
     * Execute this step with the given input data.
     *
     * @param array $input Data from the previous step (or pipeline input)
     * @return array Output data for the next step
     * @throws \RuntimeException on failure
     */
    public function execute(array $input): array;
}
