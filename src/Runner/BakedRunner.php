<?php

declare(strict_types=1);

namespace HalfBaked\Runner;

use HalfBaked\Pipeline\BakedStepInterface;
use HalfBaked\Pipeline\Step;

/**
 * Executes a pipeline step through a deterministic PHP class.
 *
 * The baked class must implement BakedStepInterface.
 * No LLM calls — pure PHP execution.
 */
class BakedRunner
{
    private BakedStepInterface $instance;

    public function __construct(private Step $step)
    {
        $className = $step->getBakedClass();
        if ($className === null) {
            throw new \LogicException("Step '{$step->getName()}' has no baked class");
        }

        if (!class_exists($className)) {
            throw new \RuntimeException("Baked class '{$className}' not found. Did you forget to require it?");
        }

        $instance = new $className();
        if (!$instance instanceof BakedStepInterface) {
            throw new \RuntimeException(
                "Class '{$className}' must implement " . BakedStepInterface::class
            );
        }

        $this->instance = $instance;
    }

    /**
     * Execute the baked step.
     *
     * @param array $input Data from the previous step
     * @return array Output data for the next step
     */
    public function execute(array $input): array
    {
        return $this->instance->execute($input);
    }
}
