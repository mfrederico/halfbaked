<?php

declare(strict_types=1);

namespace HalfBaked\Pipeline;

use HalfBaked\Ollama\OllamaClient;

/**
 * Fluent builder for pipelines.
 *
 * Usage:
 *   Pipeline::create('my_pipeline')
 *       ->step('step_name')
 *           ->using('model-name')
 *           ->prompt('Do something with {{input_field}}')
 *           ->input(['input_field' => 'string'])
 *           ->output(['result' => 'string', 'score' => 'float'])
 *       ->step('next_step')
 *           ->baked(MyBakedClass::class)
 *       ->build();
 */
class PipelineBuilder
{
    /** @var Step[] */
    private array $steps = [];
    private ?Step $currentStep = null;

    public function __construct(
        private string $name,
        private ?OllamaClient $ollama = null,
        private string $logDir = '',
    ) {}

    /**
     * Start defining a new step.
     */
    public function step(string $name): self
    {
        $this->finalizeCurrentStep();
        $this->currentStep = new Step($name);
        return $this;
    }

    /**
     * Set which Ollama model this step uses.
     */
    public function using(string $model): self
    {
        $this->requireCurrentStep();
        $this->currentStep->setModel($model);
        return $this;
    }

    /**
     * Set the prompt template. Use {{field}} for input substitution.
     */
    public function prompt(string $prompt): self
    {
        $this->requireCurrentStep();
        $this->currentStep->setPrompt($prompt);
        return $this;
    }

    /**
     * Set the system prompt (model role/context).
     */
    public function system(string $systemPrompt): self
    {
        $this->requireCurrentStep();
        $this->currentStep->setSystemPrompt($systemPrompt);
        return $this;
    }

    /**
     * Define input schema (field => type).
     */
    public function input(array $schema): self
    {
        $this->requireCurrentStep();
        $this->currentStep->setInputSchema($schema);
        return $this;
    }

    /**
     * Define output schema (field => type). Enables schema-constrained generation.
     */
    public function output(array $schema): self
    {
        $this->requireCurrentStep();
        $this->currentStep->setOutputSchema($schema);
        return $this;
    }

    /**
     * Mark this step as baked — runs a PHP class instead of LLM.
     * The class must implement BakedStepInterface.
     */
    public function baked(string $className): self
    {
        $this->requireCurrentStep();
        $this->currentStep->setBakedClass($className);
        return $this;
    }

    /**
     * Set retry count for LLM failures (default: 2).
     */
    public function retries(int $count): self
    {
        $this->requireCurrentStep();
        $this->currentStep->setRetries($count);
        return $this;
    }

    /**
     * Set temperature (default: 0.1 for consistency).
     */
    public function temperature(float $temp): self
    {
        $this->requireCurrentStep();
        $this->currentStep->setTemperature($temp);
        return $this;
    }

    /**
     * Add static context to this step.
     */
    public function context(string $key, mixed $value): self
    {
        $this->requireCurrentStep();
        $this->currentStep->addContext($key, $value);
        return $this;
    }

    /**
     * Build the pipeline.
     */
    public function build(): Pipeline
    {
        $this->finalizeCurrentStep();

        if (empty($this->steps)) {
            throw new \LogicException('Pipeline must have at least one step');
        }

        return Pipeline::fromBuilder($this->name, $this->steps, $this->ollama, $this->logDir);
    }

    private function finalizeCurrentStep(): void
    {
        if ($this->currentStep !== null) {
            $this->steps[] = $this->currentStep;
            $this->currentStep = null;
        }
    }

    private function requireCurrentStep(): void
    {
        if ($this->currentStep === null) {
            throw new \LogicException('Call ->step() before setting step properties');
        }
    }
}
