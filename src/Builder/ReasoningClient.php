<?php

declare(strict_types=1);

namespace HalfBaked\Builder;

/**
 * LLM client for task decomposition and reasoning.
 *
 * Supports Anthropic Claude API and OpenAI-compatible endpoints.
 * Uses curl for HTTP calls (same pattern as OllamaClient).
 */
class ReasoningClient
{
    public function __construct(
        private readonly string $provider = 'anthropic',
        private readonly string $model = 'claude-sonnet-4-20250514',
        private readonly string $apiKey = '',
        private readonly string $apiBase = 'https://api.anthropic.com',
    ) {}

    /**
     * Send a chat message and get a text response.
     *
     * @param string $system System prompt
     * @param string $user User message
     * @return string|null Response text or null on failure
     */
    public function chat(string $system, string $user): ?string
    {
        try {
            if ($this->provider === 'anthropic') {
                return $this->chatAnthropic($system, $user);
            }

            return $this->chatOpenAi($system, $user);
        } catch (\Throwable $e) {
            $this->log("Chat failed: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Send a chat message expecting JSON response.
     *
     * Calls chat(), strips markdown fences, then json_decodes.
     *
     * @return array|null Decoded JSON or null on failure
     */
    public function chatJson(string $system, string $user): ?array
    {
        $response = $this->chat($system, $user);
        if ($response === null) {
            return null;
        }

        // Strip markdown fences if present (```json ... ``` or ``` ... ```)
        $cleaned = preg_replace('/^```(?:json)?\s*\n?/m', '', $response);
        $cleaned = preg_replace('/\n?```\s*$/m', '', $cleaned);
        $cleaned = trim($cleaned);

        $decoded = json_decode($cleaned, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->log("JSON decode failed: " . json_last_error_msg() . " — raw: " . substr($response, 0, 200));
            return null;
        }

        return $decoded;
    }

    /**
     * Chat via Anthropic Messages API.
     */
    private function chatAnthropic(string $system, string $user): ?string
    {
        $url = rtrim($this->apiBase, '/') . '/v1/messages';

        $payload = [
            'model' => $this->model,
            'max_tokens' => 4096,
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $user],
            ],
        ];

        $headers = [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ];

        $this->log("Anthropic request to {$this->model} (" . strlen($user) . " chars)");
        $response = $this->post($url, $payload, $headers);

        $text = $response['content'][0]['text'] ?? null;
        if ($text === null) {
            $this->log("Anthropic response missing content[0].text");
            return null;
        }

        return $text;
    }

    /**
     * Chat via OpenAI-compatible Chat Completions API.
     */
    private function chatOpenAi(string $system, string $user): ?string
    {
        $url = rtrim($this->apiBase, '/') . '/v1/chat/completions';

        $payload = [
            'model' => $this->model,
            'max_tokens' => 4096,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'content-type: application/json',
        ];

        $this->log("OpenAI request to {$this->model} (" . strlen($user) . " chars)");
        $response = $this->post($url, $payload, $headers);

        $text = $response['choices'][0]['message']['content'] ?? null;
        if ($text === null) {
            $this->log("OpenAI response missing choices[0].message.content");
            return null;
        }

        return $text;
    }

    /**
     * HTTP POST via curl.
     *
     * @param string $url Full endpoint URL
     * @param array $payload Request body (will be JSON-encoded)
     * @param array $headers HTTP headers
     * @return array Decoded JSON response
     */
    private function post(string $url, array $payload, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 120,
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("Connection failed: {$error}");
        }
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("HTTP {$code}: " . substr((string) $body, 0, 300));
        }

        return json_decode($body, true) ?? [];
    }

    private function log(string $message): void
    {
        $time = date('H:i:s');
        echo "[{$time}][reasoning] {$message}\n";
    }
}
