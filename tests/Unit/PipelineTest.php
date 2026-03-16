<?php

declare(strict_types=1);

namespace HalfBaked\Tests\Unit;

use HalfBaked\Pipeline\BakedStepInterface;
use HalfBaked\Pipeline\Pipeline;
use PHPUnit\Framework\TestCase;

class PipelineTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/halfbaked-pipeline-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->logDir)) {
            foreach (glob($this->logDir . '/*') as $file) {
                unlink($file);
            }
            rmdir($this->logDir);
        }
    }

    public function testSingleBakedStepRuns(): void
    {
        $pipeline = Pipeline::create('test', logDir: $this->logDir)
            ->step('double')
                ->baked(DoublerStep::class)
            ->build();

        $result = $pipeline->run(['value' => 5]);

        $this->assertTrue($result->success);
        $this->assertEquals(['doubled' => 10], $result->finalData);
        $this->assertCount(1, $result->steps);
        $this->assertEquals('baked', $result->steps[0]->mode);
    }

    public function testMultiBakedStepChain(): void
    {
        $pipeline = Pipeline::create('chain', logDir: $this->logDir)
            ->step('double')
                ->baked(DoublerStep::class)
            ->step('label')
                ->baked(LabelerStep::class)
            ->build();

        $result = $pipeline->run(['value' => 7]);

        $this->assertTrue($result->success);
        $this->assertEquals('Result is 14', $result->finalData['label']);
    }

    public function testFailedStepStopsPipeline(): void
    {
        $pipeline = Pipeline::create('failing', logDir: $this->logDir)
            ->step('bomb')
                ->baked(ExplodingStep::class)
            ->step('never_reached')
                ->baked(DoublerStep::class)
            ->build();

        $result = $pipeline->run(['value' => 1]);

        $this->assertFalse($result->success);
        $this->assertEquals('bomb', $result->failedStep);
        $this->assertCount(1, $result->steps); // second step never ran
        $this->assertNull($result->finalData);
    }

    public function testStatsTracking(): void
    {
        $pipeline = Pipeline::create('stats', logDir: $this->logDir)
            ->step('double')
                ->baked(DoublerStep::class)
            ->build();

        // Run 3 times
        $pipeline->run(['value' => 1]);
        $pipeline->run(['value' => 2]);
        $pipeline->run(['value' => 3]);

        $stats = $pipeline->stats('double');

        $this->assertEquals(3, $stats['runs']);
        $this->assertTrue($stats['ready_to_bake']);
        $this->assertArrayHasKey('avg_duration_ms', $stats);
        $this->assertArrayHasKey('min_duration_ms', $stats);
        $this->assertArrayHasKey('max_duration_ms', $stats);
    }

    public function testDescribe(): void
    {
        $pipeline = Pipeline::create('desc', logDir: $this->logDir)
            ->step('llm_step')
                ->using('some-model')
                ->prompt('Do something')
                ->output(['result' => 'string'])
            ->step('baked_step')
                ->baked(DoublerStep::class)
            ->build();

        $desc = $pipeline->describe();

        $this->assertCount(2, $desc);
        $this->assertEquals('llm', $desc[0]['mode']);
        $this->assertEquals('some-model', $desc[0]['model']);
        $this->assertTrue($desc[0]['has_output_schema']);
        $this->assertEquals('baked', $desc[1]['mode']);
    }

    public function testVerifyBakedStep(): void
    {
        $pipeline = Pipeline::create('verify', logDir: $this->logDir)
            ->step('double')
                ->baked(DoublerStep::class)
            ->build();

        // Run to build up logs
        $pipeline->run(['value' => 3]);
        $pipeline->run(['value' => 7]);
        $pipeline->run(['value' => 100]);

        $report = $pipeline->verify('double');

        $this->assertEquals(1.0, $report['accuracy']);
        $this->assertTrue($report['perfect']);
        $this->assertStringContainsString('PERFECT', $report['report']);
    }

    public function testVerifyUnbakedStepReturnsError(): void
    {
        $pipeline = Pipeline::create('nope', logDir: $this->logDir)
            ->step('llm_step')
                ->using('model')
                ->prompt('whatever')
            ->build();

        $report = $pipeline->verify('llm_step');

        $this->assertEquals(0, $report['accuracy']);
        $this->assertStringContainsString('not baked', $report['report']);
    }

    public function testPipelineResultSummary(): void
    {
        $pipeline = Pipeline::create('summary_test', logDir: $this->logDir)
            ->step('double')
                ->baked(DoublerStep::class)
            ->step('label')
                ->baked(LabelerStep::class)
            ->build();

        $result = $pipeline->run(['value' => 5]);
        $summary = $result->summary();

        $this->assertStringContainsString('summary_test', $summary);
        $this->assertStringContainsString('SUCCESS', $summary);
        $this->assertStringContainsString('double', $summary);
        $this->assertStringContainsString('label', $summary);
        $this->assertStringContainsString('baked', $summary);
    }

    public function testEmptyPipelineThrows(): void
    {
        $this->expectException(\LogicException::class);

        Pipeline::create('empty', logDir: $this->logDir)->build();
    }

    public function testStepBeforePropertiesThrows(): void
    {
        $this->expectException(\LogicException::class);

        Pipeline::create('bad', logDir: $this->logDir)
            ->using('model'); // no ->step() first
    }
}

// --- Test fixtures ---

class DoublerStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        return ['doubled' => $input['value'] * 2];
    }
}

class LabelerStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        return ['label' => 'Result is ' . $input['doubled']];
    }
}

class ExplodingStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        throw new \RuntimeException('Step exploded!');
    }
}
