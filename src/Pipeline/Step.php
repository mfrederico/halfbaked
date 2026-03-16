<?php

declare(strict_types=1);

namespace HalfBaked\Pipeline;

/**
 * A single step in a pipeline.
 *
 * Steps have two modes:
 * - LLM mode: sends data to a specialist model, gets structured response
 * - Baked mode: runs a deterministic PHP class
 */
class Step
{
    private string $name;
    private ?string $model = null;
    private ?string $prompt = null;
    private ?array $inputSchema = null;
    private ?array $outputSchema = null;
    private ?string $bakedClass = null;
    private int $retries = 2;
    private float $temperature = 0.1;
    private ?string $systemPrompt = null;
    private array $context = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string { return $this->name; }
    public function getModel(): ?string { return $this->model; }
    public function getPrompt(): ?string { return $this->prompt; }
    public function getInputSchema(): ?array { return $this->inputSchema; }
    public function getOutputSchema(): ?array { return $this->outputSchema; }
    public function getBakedClass(): ?string { return $this->bakedClass; }
    public function getRetries(): int { return $this->retries; }
    public function getTemperature(): float { return $this->temperature; }
    public function getSystemPrompt(): ?string { return $this->systemPrompt; }
    public function getContext(): array { return $this->context; }

    public function isBaked(): bool { return $this->bakedClass !== null; }
    public function hasInputSchema(): bool { return $this->inputSchema !== null; }
    public function hasOutputSchema(): bool { return $this->outputSchema !== null; }

    /**
     * Set the Ollama model to use for this step.
     */
    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    /**
     * Set the user prompt template.
     * Use {{field}} placeholders for input data substitution.
     */
    public function setPrompt(string $prompt): self
    {
        $this->prompt = $prompt;
        return $this;
    }

    /**
     * Set the system prompt (role/context for the model).
     */
    public function setSystemPrompt(string $prompt): self
    {
        $this->systemPrompt = $prompt;
        return $this;
    }

    /**
     * Define expected input schema.
     * Keys are field names, values are type hints (for documentation/validation).
     */
    public function setInputSchema(array $schema): self
    {
        $this->inputSchema = $schema;
        return $this;
    }

    /**
     * Define expected output schema.
     * This is used for schema-constrained generation AND validation.
     */
    public function setOutputSchema(array $schema): self
    {
        $this->outputSchema = $schema;
        return $this;
    }

    /**
     * Mark this step as baked — runs a PHP class instead of LLM.
     */
    public function setBakedClass(string $className): self
    {
        $this->bakedClass = $className;
        return $this;
    }

    /**
     * Set retry count for LLM failures.
     */
    public function setRetries(int $retries): self
    {
        $this->retries = $retries;
        return $this;
    }

    /**
     * Set temperature for LLM generation.
     */
    public function setTemperature(float $temp): self
    {
        $this->temperature = $temp;
        return $this;
    }

    /**
     * Add static context that gets included in every LLM call.
     */
    public function addContext(string $key, mixed $value): self
    {
        $this->context[$key] = $value;
        return $this;
    }

    /**
     * Validate output data against the output schema.
     */
    public function validateOutput(array $data): bool
    {
        if (!$this->outputSchema) {
            return true;
        }

        foreach ($this->outputSchema as $field => $spec) {
            $type = is_string($spec) ? $spec : ($spec['type'] ?? 'string');
            $required = is_array($spec) ? ($spec['required'] ?? true) : true;

            if ($required && !array_key_exists($field, $data)) {
                return false;
            }

            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $valid = match ($type) {
                    'string' => is_string($data[$field]),
                    'int', 'integer' => is_int($data[$field]),
                    'float', 'number' => is_numeric($data[$field]),
                    'bool', 'boolean' => is_bool($data[$field]),
                    'array' => is_array($data[$field]),
                    'object' => is_array($data[$field]) || is_object($data[$field]),
                    default => true,
                };
                if (!$valid) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Render the prompt with input data substitution.
     */
    public function renderPrompt(array $input): string
    {
        $prompt = $this->prompt ?? 'Process this input and return a JSON result.';

        // Substitute {{field}} placeholders
        foreach ($input as $key => $value) {
            $replacement = is_array($value) ? json_encode($value) : (string) $value;
            $prompt = str_replace('{{' . $key . '}}', $replacement, $prompt);
        }

        return $prompt;
    }
}
