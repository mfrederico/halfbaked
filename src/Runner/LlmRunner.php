<?php

declare(strict_types=1);

namespace HalfBaked\Runner;

use HalfBaked\Ollama\OllamaClient;
use HalfBaked\Pipeline\Step;

/**
 * Executes a pipeline step through an Ollama LLM.
 *
 * Handles prompt rendering, schema-constrained generation,
 * retries on failure, and feedback-driven re-execution.
 */
class LlmRunner
{
    public function __construct(
        private Step $step,
        private OllamaClient $ollama,
    ) {}

    /**
     * Execute the step with the given input data.
     *
     * @param array $input Data from the previous step
     * @return array Structured output from the model
     * @throws \RuntimeException on LLM failure after retries
     */
    public function execute(array $input): array
    {
        $prompt = $this->step->renderPrompt($input);
        $system = $this->buildSystemPrompt($input);
        $schema = $this->step->getOutputSchema();
        $model = $this->step->getModel() ?? 'llama3.2';
        $temperature = $this->step->getTemperature();

        $lastError = null;
        $maxAttempts = $this->step->getRetries() + 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->ollama->generate(
                    model: $model,
                    prompt: $prompt,
                    system: $system,
                    schema: $schema,
                    temperature: $temperature,
                );
            } catch (\Throwable $e) {
                $lastError = $e;
                if ($attempt < $maxAttempts) {
                    // Bump temperature slightly on retry to get different output
                    $temperature = min(1.0, $temperature + 0.1);
                }
            }
        }

        throw new \RuntimeException(
            "Step '{$this->step->getName()}' failed after {$maxAttempts} attempts: " . $lastError->getMessage(),
            previous: $lastError,
        );
    }

    /**
     * Re-execute with explicit feedback about what went wrong.
     *
     * Used when output validation fails — the model gets a second
     * chance with error context appended to the prompt.
     */
    public function executeWithFeedback(array $input, string $feedback): array
    {
        $prompt = $this->step->renderPrompt($input);
        $prompt .= "\n\n## Correction Required\n" . $feedback;
        $prompt .= "\n\nPlease try again, ensuring your output matches the required format exactly.";

        $model = $this->step->getModel() ?? 'llama3.2';

        return $this->ollama->generate(
            model: $model,
            prompt: $prompt,
            system: $this->buildSystemPrompt($input),
            schema: $this->step->getOutputSchema(),
            temperature: $this->step->getTemperature(),
        );
    }

    /**
     * Build the system prompt from step config + context.
     */
    private function buildSystemPrompt(array $input): string
    {
        $parts = [];

        if ($systemPrompt = $this->step->getSystemPrompt()) {
            $parts[] = $systemPrompt;
        } else {
            $parts[] = "You are a pipeline step processor. Return valid JSON matching the requested schema.";
        }

        // Append static context
        $context = $this->step->getContext();
        if (!empty($context)) {
            $parts[] = "\n## Context";
            foreach ($context as $key => $value) {
                $val = is_array($value) ? json_encode($value) : (string) $value;
                $parts[] = "- {$key}: {$val}";
            }
        }

        // Include input schema description if available
        if ($this->step->hasInputSchema()) {
            $parts[] = "\n## Input Schema";
            $parts[] = json_encode($this->step->getInputSchema(), JSON_PRETTY_PRINT);
        }

        // Include output schema so the model knows what to produce
        if ($this->step->hasOutputSchema()) {
            $parts[] = "\n## Required Output Schema";
            $parts[] = json_encode($this->step->getOutputSchema(), JSON_PRETTY_PRINT);
        }

        return implode("\n", $parts);
    }
}
