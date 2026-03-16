<?php

declare(strict_types=1);

namespace HalfBaked\Ollama;

/**
 * Lightweight Ollama API client.
 *
 * Works with any Ollama-compatible endpoint (local or remote).
 * Supports schema-constrained generation via the `format` parameter.
 */
class OllamaClient
{
    public function __construct(
        private string $host = '127.0.0.1',
        private int $port = 11434,
        private int $timeout = 1800,
    ) {}

    /**
     * Generate a structured response from the model.
     *
     * @param string $model   Ollama model name
     * @param string $prompt  User prompt
     * @param string|null $system System prompt (optional)
     * @param array|null $schema JSON schema to constrain output format
     * @param float $temperature Generation temperature
     * @return array Decoded JSON response
     */
    public function generate(
        string $model,
        string $prompt,
        ?string $system = null,
        ?array $schema = null,
        float $temperature = 0.1,
    ): array {
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => ['temperature' => $temperature],
        ];

        if ($system !== null) {
            $payload['system'] = $system;
        }

        // Schema-constrained generation: model MUST output valid JSON matching this
        if ($schema !== null) {
            $payload['format'] = $this->buildJsonSchema($schema);
        } else {
            $payload['format'] = 'json';
        }

        $response = $this->post('/api/generate', $payload);

        if (!isset($response['response'])) {
            throw new \RuntimeException('Ollama returned no response');
        }

        $decoded = json_decode($response['response'], true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Model returned invalid JSON: ' . substr($response['response'], 0, 200));
        }

        return $decoded;
    }

    /**
     * Raw generate (no JSON parsing) — used for Gorilla-style function routing.
     */
    public function generateRaw(
        string $model,
        string $prompt,
        bool $raw = false,
        float $temperature = 0.0,
        ?string $system = null,
    ): string {
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => ['temperature' => $temperature],
        ];

        if ($system !== null) {
            $payload['system'] = $system;
        }

        if ($raw) {
            $payload['raw'] = true;
        }

        $response = $this->post('/api/generate', $payload);
        return trim($response['response'] ?? '');
    }

    /**
     * Chat completion with message history.
     */
    public function chat(
        string $model,
        array $messages,
        ?array $schema = null,
        float $temperature = 0.1,
    ): array {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'options' => ['temperature' => $temperature],
        ];

        if ($schema !== null) {
            $payload['format'] = $this->buildJsonSchema($schema);
        } else {
            $payload['format'] = 'json';
        }

        $response = $this->post('/api/chat', $payload);
        $content = $response['message']['content'] ?? '';

        $decoded = json_decode($content, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Model returned invalid JSON: ' . substr($content, 0, 200));
        }

        return $decoded;
    }

    /**
     * Check if a model is loaded and available.
     */
    public function isAvailable(string $model): bool
    {
        try {
            $response = $this->get('/api/tags');
            $models = array_column($response['models'] ?? [], 'name');
            foreach ($models as $m) {
                if (str_starts_with($m, $model)) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Convert simple schema shorthand to JSON Schema.
     * Input:  ['name' => 'string', 'age' => 'integer', 'active' => 'boolean']
     * Output: Full JSON Schema object
     */
    private function buildJsonSchema(array $schema): array
    {
        $properties = [];
        $required = [];

        foreach ($schema as $field => $spec) {
            if (is_string($spec)) {
                $properties[$field] = ['type' => $this->normalizeType($spec)];
                $required[] = $field;
            } elseif (is_array($spec)) {
                $prop = ['type' => $this->normalizeType($spec['type'] ?? 'string')];
                if (isset($spec['description'])) {
                    $prop['description'] = $spec['description'];
                }
                if (isset($spec['enum'])) {
                    $prop['enum'] = $spec['enum'];
                }
                if (isset($spec['items'])) {
                    $prop['items'] = $spec['items'];
                }
                $properties[$field] = $prop;
                if ($spec['required'] ?? true) {
                    $required[] = $field;
                }
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    private function normalizeType(string $type): string
    {
        return match ($type) {
            'int' => 'integer',
            'float', 'double' => 'number',
            'bool' => 'boolean',
            'list' => 'array',
            'dict', 'map', 'hash' => 'object',
            default => $type,
        };
    }

    private function post(string $path, array $payload): array
    {
        $ch = curl_init("http://{$this->host}:{$this->port}{$path}");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("Ollama connection failed: {$error}");
        }
        if ($code !== 200) {
            throw new \RuntimeException("Ollama HTTP {$code}: " . substr($body, 0, 300));
        }

        return json_decode($body, true) ?? [];
    }

    private function get(string $path): array
    {
        $ch = curl_init("http://{$this->host}:{$this->port}{$path}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $body = curl_exec($ch);
        curl_close($ch);

        return json_decode($body ?: '{}', true) ?? [];
    }
}
