<?php

declare(strict_types=1);

namespace HalfBaked\Baking;

/**
 * Records pipeline step executions for baking analysis.
 *
 * Each step's input/output pairs are logged as JSONL files.
 * The Baker uses these logs to infer deterministic patterns
 * and generate PHP code that replaces the LLM.
 */
class ExecutionLog
{
    public function __construct(private string $logDir) {}

    /**
     * Record a step execution.
     */
    public function record(string $stepName, array $input, array $output, float $duration): void
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }

        $file = $this->logFile($stepName);
        $entry = json_encode([
            'timestamp' => microtime(true),
            'input' => $input,
            'output' => $output,
            'duration' => $duration,
        ], JSON_UNESCAPED_SLASHES);

        file_put_contents($file, $entry . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Get all logged executions for a step.
     *
     * @return array[] Array of log entries
     */
    public function getStepLogs(string $stepName): array
    {
        $file = $this->logFile($stepName);
        if (!file_exists($file)) {
            return [];
        }

        $lines = array_filter(explode("\n", file_get_contents($file)));
        return array_values(array_filter(
            array_map(fn(string $line) => json_decode($line, true), $lines)
        ));
    }

    /**
     * Get all step names that have logs.
     *
     * @return string[]
     */
    public function getLoggedSteps(): array
    {
        if (!is_dir($this->logDir)) {
            return [];
        }

        $steps = [];
        foreach (glob($this->logDir . '/*.jsonl') as $file) {
            $steps[] = basename($file, '.jsonl');
        }
        return $steps;
    }

    /**
     * Count logged executions for a step.
     */
    public function count(string $stepName): int
    {
        return count($this->getStepLogs($stepName));
    }

    /**
     * Clear logs for a step (e.g., after successful baking).
     */
    public function clear(string $stepName): void
    {
        $file = $this->logFile($stepName);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    private function logFile(string $stepName): string
    {
        return $this->logDir . '/' . $stepName . '.jsonl';
    }
}
