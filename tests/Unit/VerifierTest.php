<?php

declare(strict_types=1);

namespace HalfBaked\Tests\Unit;

use HalfBaked\Baking\Verifier;
use HalfBaked\Pipeline\BakedStepInterface;
use PHPUnit\Framework\TestCase;

class VerifierTest extends TestCase
{
    private Verifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new Verifier();
    }

    public function testPerfectMatch(): void
    {
        $baked = new class implements BakedStepInterface {
            public function execute(array $input): array
            {
                return ['doubled' => $input['value'] * 2];
            }
        };

        $logs = [
            ['input' => ['value' => 5], 'output' => ['doubled' => 10]],
            ['input' => ['value' => 3], 'output' => ['doubled' => 6]],
            ['input' => ['value' => 0], 'output' => ['doubled' => 0]],
        ];

        $result = $this->verifier->verify($baked, $logs);

        $this->assertTrue($result->isPerfect());
        $this->assertEquals(1.0, $result->accuracy);
        $this->assertEquals(3, $result->passed);
        $this->assertEquals(0, $result->failed);
        $this->assertEmpty($result->failures);
    }

    public function testMismatchDetected(): void
    {
        $baked = new class implements BakedStepInterface {
            public function execute(array $input): array
            {
                return ['doubled' => $input['value'] * 2];
            }
        };

        $logs = [
            ['input' => ['value' => 5], 'output' => ['doubled' => 10]],
            ['input' => ['value' => 3], 'output' => ['doubled' => 99]], // WRONG
        ];

        $result = $this->verifier->verify($baked, $logs);

        $this->assertFalse($result->isPerfect());
        $this->assertEquals(0.5, $result->accuracy);
        $this->assertEquals(1, $result->failed);
        $this->assertCount(1, $result->failures);
        $this->assertEquals('mismatch', $result->failures[0]['type']);
    }

    public function testExceptionCaught(): void
    {
        $baked = new class implements BakedStepInterface {
            public function execute(array $input): array
            {
                throw new \RuntimeException('kaboom');
            }
        };

        $logs = [
            ['input' => ['value' => 1], 'output' => ['result' => 1]],
        ];

        $result = $this->verifier->verify($baked, $logs);

        $this->assertFalse($result->isPerfect());
        $this->assertEquals(0, $result->passed);
        $this->assertEquals(1, $result->errors);
        $this->assertEquals('exception', $result->failures[0]['type']);
        $this->assertStringContainsString('kaboom', $result->failures[0]['message']);
    }

    public function testCaseInsensitiveByDefault(): void
    {
        $baked = new class implements BakedStepInterface {
            public function execute(array $input): array
            {
                return ['name' => strtoupper($input['name'])];
            }
        };

        $logs = [
            ['input' => ['name' => 'hello'], 'output' => ['name' => 'Hello']], // LLM used title case
        ];

        // Default: case insensitive
        $result = $this->verifier->verify($baked, $logs);
        $this->assertTrue($result->isPerfect(), 'Should pass with case-insensitive comparison');

        // Strict mode
        $strict = new Verifier(caseSensitive: true);
        $result = $strict->verify($baked, $logs);
        $this->assertFalse($result->isPerfect(), 'Should fail with case-sensitive comparison');
    }

    public function testNumericTolerance(): void
    {
        $baked = new class implements BakedStepInterface {
            public function execute(array $input): array
            {
                return ['ratio' => $input['a'] / $input['b']];
            }
        };

        $logs = [
            ['input' => ['a' => 1, 'b' => 3], 'output' => ['ratio' => 0.333]], // LLM rounded
        ];

        $result = $this->verifier->verify($baked, $logs);
        $this->assertTrue($result->isPerfect(), '0.33333... should match 0.333 within default tolerance');

        $tight = new Verifier(numericTolerance: 0.0001);
        $result = $tight->verify($baked, $logs);
        $this->assertFalse($result->isPerfect(), 'Should fail with tight tolerance');
    }

    public function testMissingField(): void
    {
        $baked = new class implements BakedStepInterface {
            public function execute(array $input): array
            {
                return ['a' => 1]; // missing 'b'
            }
        };

        $logs = [
            ['input' => [], 'output' => ['a' => 1, 'b' => 2]],
        ];

        $result = $this->verifier->verify($baked, $logs);
        $this->assertFalse($result->isPerfect());
        $this->assertEquals('missing', $result->failures[0]['diffs'][0]['issue']);
    }

    public function testExtraField(): void
    {
        $baked = new class implements BakedStepInterface {
            public function execute(array $input): array
            {
                return ['a' => 1, 'b' => 2, 'extra' => 'oops'];
            }
        };

        $logs = [
            ['input' => [], 'output' => ['a' => 1, 'b' => 2]],
        ];

        $result = $this->verifier->verify($baked, $logs);
        $this->assertFalse($result->isPerfect());
        $this->assertEquals('extra_field', $result->failures[0]['diffs'][0]['issue']);
    }

    public function testListOrderInsensitive(): void
    {
        $baked = new class implements BakedStepInterface {
            public function execute(array $input): array
            {
                $tags = $input['tags'];
                sort($tags); // baked sorts alphabetically
                return ['tags' => $tags];
            }
        };

        $logs = [
            ['input' => ['tags' => ['c', 'a', 'b']], 'output' => ['tags' => ['b', 'a', 'c']]],
        ];

        $result = $this->verifier->verify($baked, $logs);
        $this->assertTrue($result->isPerfect(), 'List arrays should match regardless of order');
    }

    public function testAcceptableThreshold(): void
    {
        $baked = new class implements BakedStepInterface {
            public function execute(array $input): array
            {
                // Gets 19 out of 20 right
                if ($input['n'] === 13) {
                    return ['result' => 'wrong'];
                }
                return ['result' => 'ok'];
            }
        };

        $logs = [];
        for ($i = 0; $i < 20; $i++) {
            $logs[] = ['input' => ['n' => $i], 'output' => ['result' => 'ok']];
        }

        $result = $this->verifier->verify($baked, $logs);

        $this->assertFalse($result->isPerfect());
        $this->assertTrue($result->isAcceptable(0.95)); // 19/20 = 95%
        $this->assertFalse($result->isAcceptable(0.96));
    }

    public function testReportOutput(): void
    {
        $baked = new class implements BakedStepInterface {
            public function execute(array $input): array
            {
                return ['x' => 1];
            }
        };

        $logs = [
            ['input' => [], 'output' => ['x' => 1]],
            ['input' => [], 'output' => ['x' => 2]],
        ];

        $result = $this->verifier->verify($baked, $logs);
        $report = $result->report();

        $this->assertStringContainsString('1/2 passed', $report);
        $this->assertStringContainsString('FAILED', $report);
        $this->assertStringContainsString('value_mismatch', $report);
    }
}
