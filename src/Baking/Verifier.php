<?php

declare(strict_types=1);

namespace HalfBaked\Baking;

use HalfBaked\Pipeline\BakedStepInterface;

/**
 * Verifies baked code against logged execution data.
 *
 * Replays every logged input through the baked class and compares
 * its output to what the LLM originally produced. Reports mismatches
 * with field-level diffs so you know exactly what diverged.
 */
class Verifier
{
    private float $numericTolerance;
    private bool $caseSensitive;

    public function __construct(
        float $numericTolerance = 0.001,
        bool $caseSensitive = false,
    ) {
        $this->numericTolerance = $numericTolerance;
        $this->caseSensitive = $caseSensitive;
    }

    /**
     * Verify a baked class against execution logs.
     *
     * @param BakedStepInterface $instance  The baked class to test
     * @param array[]            $logs      Execution logs with 'input' and 'output' keys
     * @return VerifyResult
     */
    public function verify(BakedStepInterface $instance, array $logs): VerifyResult
    {
        $passed = 0;
        $failed = 0;
        $errors = 0;
        $failures = [];

        foreach ($logs as $i => $log) {
            $input = $log['input'];
            $expectedOutput = $log['output'];

            try {
                $actualOutput = $instance->execute($input);
            } catch (\Throwable $e) {
                $errors++;
                $failures[] = [
                    'example' => $i + 1,
                    'type' => 'exception',
                    'message' => $e->getMessage(),
                    'input' => $this->truncate($input),
                ];
                continue;
            }

            $diffs = $this->compare($expectedOutput, $actualOutput);

            if (empty($diffs)) {
                $passed++;
            } else {
                $failed++;
                $failures[] = [
                    'example' => $i + 1,
                    'type' => 'mismatch',
                    'diffs' => $diffs,
                    'input' => $this->truncate($input),
                ];
            }
        }

        $total = count($logs);

        return new VerifyResult(
            total: $total,
            passed: $passed,
            failed: $failed,
            errors: $errors,
            accuracy: $total > 0 ? $passed / $total : 0,
            failures: $failures,
        );
    }

    /**
     * Compare expected vs actual output, returning field-level diffs.
     *
     * @return array[] List of diffs, empty if outputs match
     */
    private function compare(array $expected, array $actual, string $path = ''): array
    {
        $diffs = [];

        // Check for missing fields
        foreach ($expected as $key => $expectedValue) {
            $fieldPath = $path ? "{$path}.{$key}" : $key;

            if (!array_key_exists($key, $actual)) {
                $diffs[] = [
                    'field' => $fieldPath,
                    'issue' => 'missing',
                    'expected' => $expectedValue,
                    'actual' => '(absent)',
                ];
                continue;
            }

            $actualValue = $actual[$key];

            if (!$this->valuesMatch($expectedValue, $actualValue)) {
                // If both are arrays, recurse for deeper diff
                if (is_array($expectedValue) && is_array($actualValue)) {
                    $nested = $this->compare($expectedValue, $actualValue, $fieldPath);
                    if (!empty($nested)) {
                        $diffs = array_merge($diffs, $nested);
                    }
                } else {
                    $diffs[] = [
                        'field' => $fieldPath,
                        'issue' => 'value_mismatch',
                        'expected' => $expectedValue,
                        'actual' => $actualValue,
                    ];
                }
            }
        }

        // Check for extra fields the baked code produces
        foreach ($actual as $key => $value) {
            $fieldPath = $path ? "{$path}.{$key}" : $key;
            if (!array_key_exists($key, $expected)) {
                $diffs[] = [
                    'field' => $fieldPath,
                    'issue' => 'extra_field',
                    'expected' => '(absent)',
                    'actual' => $value,
                ];
            }
        }

        return $diffs;
    }

    /**
     * Fuzzy value comparison with type coercion awareness.
     */
    private function valuesMatch(mixed $expected, mixed $actual): bool
    {
        // Identical
        if ($expected === $actual) {
            return true;
        }

        // Both null
        if ($expected === null && $actual === null) {
            return true;
        }

        // One is null, other isn't
        if ($expected === null || $actual === null) {
            return false;
        }

        // Numeric tolerance
        if (is_numeric($expected) && is_numeric($actual)) {
            return abs((float) $expected - (float) $actual) <= $this->numericTolerance;
        }

        // String comparison (optional case insensitivity)
        if (is_string($expected) && is_string($actual)) {
            if (!$this->caseSensitive) {
                return mb_strtolower($expected) === mb_strtolower($actual);
            }
            return $expected === $actual;
        }

        // Bool: accept int equivalents (1/0)
        if (is_bool($expected) && is_int($actual)) {
            return $expected === ($actual !== 0);
        }
        if (is_int($expected) && is_bool($actual)) {
            return ($expected !== 0) === $actual;
        }

        // Array comparison (order-insensitive for non-associative arrays)
        if (is_array($expected) && is_array($actual)) {
            // If both are sequential (list) arrays, compare sorted
            if ($this->isList($expected) && $this->isList($actual)) {
                $sortedExpected = $expected;
                $sortedActual = $actual;
                sort($sortedExpected);
                sort($sortedActual);
                return $this->arraysMatch($sortedExpected, $sortedActual);
            }
            return $this->arraysMatch($expected, $actual);
        }

        // Loose comparison as last resort (e.g., "42" vs 42)
        return $expected == $actual;
    }

    private function arraysMatch(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $key => $value) {
            if (!array_key_exists($key, $b)) {
                return false;
            }
            if (!$this->valuesMatch($value, $b[$key])) {
                return false;
            }
        }
        return true;
    }

    private function isList(array $arr): bool
    {
        return array_is_list($arr);
    }

    private function truncate(mixed $data, int $maxLen = 200): mixed
    {
        if (is_string($data) && strlen($data) > $maxLen) {
            return substr($data, 0, $maxLen) . '...';
        }
        if (is_array($data)) {
            $json = json_encode($data);
            if (strlen($json) > $maxLen) {
                return json_decode(substr($json, 0, $maxLen) . '...', true) ?? $data;
            }
        }
        return $data;
    }
}
