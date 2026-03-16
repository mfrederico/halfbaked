<?php

declare(strict_types=1);

namespace HalfBaked\Baking;

/**
 * Result of verifying a baked class against execution logs.
 */
class VerifyResult
{
    public function __construct(
        public readonly int $total,
        public readonly int $passed,
        public readonly int $failed,
        public readonly int $errors,
        public readonly float $accuracy,
        public readonly array $failures,
    ) {}

    /**
     * Did every single logged example pass?
     */
    public function isPerfect(): bool
    {
        return $this->failed === 0 && $this->errors === 0;
    }

    /**
     * Is accuracy above the given threshold? (default 95%)
     */
    public function isAcceptable(float $threshold = 0.95): bool
    {
        return $this->accuracy >= $threshold;
    }

    /**
     * Human-readable report.
     */
    public function report(): string
    {
        $pct = round($this->accuracy * 100, 1);
        $lines = ["{$this->passed}/{$this->total} passed ({$pct}%)"];

        if ($this->errors > 0) {
            $lines[0] .= ", {$this->errors} threw exceptions";
        }

        if ($this->isPerfect()) {
            $lines[] = "PERFECT — baked code reproduces all logged outputs.";
        } elseif ($this->isAcceptable()) {
            $lines[] = "ACCEPTABLE — minor deviations, review failures below.";
        } else {
            $lines[] = "FAILED — baked code does not match LLM behavior. Do not deploy.";
        }

        // Show first 5 failures
        $shown = array_slice($this->failures, 0, 5);
        foreach ($shown as $f) {
            $lines[] = '';
            $lines[] = "  Example #{$f['example']}:";

            if ($f['type'] === 'exception') {
                $lines[] = "    EXCEPTION: {$f['message']}";
            } else {
                foreach ($f['diffs'] as $diff) {
                    $expected = is_array($diff['expected']) ? json_encode($diff['expected']) : $diff['expected'];
                    $actual = is_array($diff['actual']) ? json_encode($diff['actual']) : $diff['actual'];
                    $lines[] = "    {$diff['field']}: expected [{$expected}] got [{$actual}] ({$diff['issue']})";
                }
            }
        }

        if (count($this->failures) > 5) {
            $lines[] = "  ... and " . (count($this->failures) - 5) . " more failures";
        }

        return implode("\n", $lines);
    }
}
