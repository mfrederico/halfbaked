<?php

declare(strict_types=1);

namespace HalfBaked\Tests\Integration;

use HalfBaked\Baking\ExecutionLog;
use HalfBaked\Baking\Verifier;
use HalfBaked\Pipeline\BakedStepInterface;
use HalfBaked\Pipeline\Pipeline;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: the full dough → log → bake → verify loop.
 *
 * Uses baked steps as stand-ins for LLM calls (no Ollama needed).
 * This tests the pipeline mechanics, logging, verification, and
 * the transition from soft to hard code.
 */
class FullBakeLoopTest extends TestCase
{
    private string $logDir;
    private string $bakeDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/halfbaked-integration-' . uniqid();
        $this->bakeDir = sys_get_temp_dir() . '/halfbaked-baked-' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach ([$this->logDir, $this->bakeDir] as $dir) {
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') as $file) {
                    unlink($file);
                }
                rmdir($dir);
            }
        }
    }

    /**
     * Simulates the complete lifecycle:
     *
     * 1. Build pipeline with "dough" step (we use a baked step to simulate)
     * 2. Run it 5 times with varied input to build execution logs
     * 3. Verify the logs recorded correctly
     * 4. Verify the baked step reproduces all logged outputs
     * 5. Confirm stats and readiness
     */
    public function testFullLifecycle(): void
    {
        // PHASE 1: Build pipeline with a "simulated LLM" step
        $pipeline = Pipeline::create('address_normalizer', logDir: $this->logDir)
            ->step('normalize')
                ->baked(SimulatedNormalizerStep::class)
            ->build();

        // PHASE 2: Run with diverse inputs (simulating the prototyping phase)
        $testCases = [
            ['street' => '123 Main St', 'city' => 'new york', 'state' => 'ny'],
            ['street' => '456 Oak Ave', 'city' => 'LOS ANGELES', 'state' => 'CA'],
            ['street' => '789 Pine Blvd', 'city' => 'chicago', 'state' => 'il'],
            ['street' => '100 Elm Dr', 'city' => 'MIAMI', 'state' => 'fl'],
            ['street' => '200 Cedar Ln', 'city' => 'Seattle', 'state' => 'WA'],
        ];

        foreach ($testCases as $input) {
            $result = $pipeline->run($input);
            $this->assertTrue($result->success, "Pipeline should succeed for: {$input['street']}");
        }

        // PHASE 3: Verify logs recorded
        $log = new ExecutionLog($this->logDir);
        $entries = $log->getStepLogs('normalize');
        $this->assertCount(5, $entries, 'Should have 5 execution logs');

        // Verify log structure
        foreach ($entries as $entry) {
            $this->assertArrayHasKey('input', $entry);
            $this->assertArrayHasKey('output', $entry);
            $this->assertArrayHasKey('duration', $entry);
            $this->assertArrayHasKey('timestamp', $entry);
        }

        // PHASE 4: Verify baked step reproduces all outputs
        $verifier = new Verifier();
        $instance = new SimulatedNormalizerStep();
        $result = $verifier->verify($instance, $entries);

        $this->assertTrue($result->isPerfect(), "Baked step should match all logged outputs:\n" . $result->report());
        $this->assertEquals(5, $result->passed);
        $this->assertEquals(1.0, $result->accuracy);

        // PHASE 5: Stats
        $stats = $pipeline->stats('normalize');
        $this->assertEquals(5, $stats['runs']);
        $this->assertTrue($stats['ready_to_bake']);
        $this->assertGreaterThanOrEqual(0, $stats['avg_duration_ms']);

        // PHASE 6: Verify via Pipeline
        $verifyReport = $pipeline->verify('normalize');
        $this->assertTrue($verifyReport['perfect']);
        $this->assertEquals(1.0, $verifyReport['accuracy']);
    }

    /**
     * Test that verification catches divergence between two implementations.
     */
    public function testVerificationCatchesDivergence(): void
    {
        // Build logs from the "good" implementation
        $pipeline = Pipeline::create('divergence_test', logDir: $this->logDir)
            ->step('transform')
                ->baked(GoodTransformer::class)
            ->build();

        // Run 5 times
        for ($i = 1; $i <= 5; $i++) {
            $pipeline->run(['value' => $i]);
        }

        // Now verify with a "bad" implementation that differs
        $log = new ExecutionLog($this->logDir);
        $entries = $log->getStepLogs('transform');

        $verifier = new Verifier();

        // Good impl should pass
        $good = $verifier->verify(new GoodTransformer(), $entries);
        $this->assertTrue($good->isPerfect());

        // Bad impl should fail
        $bad = $verifier->verify(new BadTransformer(), $entries);
        $this->assertFalse($bad->isPerfect());
        $this->assertGreaterThan(0, $bad->failed);
    }

    /**
     * Test multi-step pipeline logs each step independently.
     */
    public function testMultiStepLogging(): void
    {
        $pipeline = Pipeline::create('multi_step', logDir: $this->logDir)
            ->step('parse')
                ->baked(ParseStep::class)
            ->step('enrich')
                ->baked(EnrichStep::class)
            ->step('format')
                ->baked(FormatStep::class)
            ->build();

        // Run 3 times
        $inputs = [
            ['raw' => 'alice:30'],
            ['raw' => 'bob:25'],
            ['raw' => 'charlie:35'],
        ];

        foreach ($inputs as $input) {
            $result = $pipeline->run($input);
            $this->assertTrue($result->success);
        }

        // Each step should have 3 logs
        $log = new ExecutionLog($this->logDir);
        $this->assertCount(3, $log->getStepLogs('parse'));
        $this->assertCount(3, $log->getStepLogs('enrich'));
        $this->assertCount(3, $log->getStepLogs('format'));

        // Verify data flows correctly through the chain
        $parseLogs = $log->getStepLogs('parse');
        $this->assertEquals('alice', $parseLogs[0]['output']['name']);
        $this->assertEquals(30, $parseLogs[0]['output']['age']);

        $enrichLogs = $log->getStepLogs('enrich');
        $this->assertEquals('adult', $enrichLogs[0]['output']['category']);

        $formatLogs = $log->getStepLogs('format');
        $this->assertStringContainsString('Alice', $formatLogs[0]['output']['display']);

        // All 3 steps should be independently verifiable
        $verifier = new Verifier();
        foreach (['parse' => new ParseStep(), 'enrich' => new EnrichStep(), 'format' => new FormatStep()] as $name => $instance) {
            $result = $verifier->verify($instance, $log->getStepLogs($name));
            $this->assertTrue($result->isPerfect(), "Step '{$name}' failed verification:\n" . $result->report());
        }
    }

    /**
     * Test the describe output includes run counts.
     */
    public function testDescribeShowsRunCounts(): void
    {
        $pipeline = Pipeline::create('desc_test', logDir: $this->logDir)
            ->step('parse')
                ->baked(ParseStep::class)
            ->step('enrich')
                ->baked(EnrichStep::class)
            ->build();

        $pipeline->run(['raw' => 'alice:30']);
        $pipeline->run(['raw' => 'bob:25']);

        $desc = $pipeline->describe();
        $this->assertEquals(2, $desc[0]['runs_logged']);
        $this->assertEquals(2, $desc[1]['runs_logged']);
    }
}

// --- Test fixtures ---

class SimulatedNormalizerStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        return [
            'street' => ucwords(strtolower($input['street'] ?? '')),
            'city' => ucwords(strtolower($input['city'] ?? '')),
            'state' => strtoupper($input['state'] ?? ''),
            'valid' => !empty($input['street']) && !empty($input['city']),
        ];
    }
}

class GoodTransformer implements BakedStepInterface
{
    public function execute(array $input): array
    {
        return [
            'doubled' => $input['value'] * 2,
            'label' => $input['value'] > 3 ? 'high' : 'low',
        ];
    }
}

class BadTransformer implements BakedStepInterface
{
    public function execute(array $input): array
    {
        return [
            'doubled' => $input['value'] * 2,
            'label' => 'always_low', // BUG: doesn't branch on value
        ];
    }
}

class ParseStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        [$name, $age] = explode(':', $input['raw']);
        return ['name' => $name, 'age' => (int) $age];
    }
}

class EnrichStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        return [
            'name' => $input['name'],
            'age' => $input['age'],
            'category' => $input['age'] >= 18 ? 'adult' : 'minor',
        ];
    }
}

class FormatStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        return [
            'display' => ucfirst($input['name']) . ' (' . $input['age'] . ', ' . $input['category'] . ')',
        ];
    }
}
