<?php

declare(strict_types=1);

namespace HalfBaked\Tests\Unit;

use HalfBaked\Baking\ExecutionLog;
use PHPUnit\Framework\TestCase;

class ExecutionLogTest extends TestCase
{
    private string $logDir;
    private ExecutionLog $log;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/halfbaked-test-' . uniqid();
        $this->log = new ExecutionLog($this->logDir);
    }

    protected function tearDown(): void
    {
        // Clean up
        if (is_dir($this->logDir)) {
            foreach (glob($this->logDir . '/*') as $file) {
                unlink($file);
            }
            rmdir($this->logDir);
        }
    }

    public function testRecordCreatesDirectoryAndFile(): void
    {
        $this->assertDirectoryDoesNotExist($this->logDir);

        $this->log->record('test_step', ['in' => 'a'], ['out' => 'b'], 0.05);

        $this->assertDirectoryExists($this->logDir);
        $this->assertFileExists($this->logDir . '/test_step.jsonl');
    }

    public function testRecordAndRetrieve(): void
    {
        $this->log->record('step1', ['x' => 1], ['y' => 2], 0.01);
        $this->log->record('step1', ['x' => 3], ['y' => 4], 0.02);
        $this->log->record('step1', ['x' => 5], ['y' => 6], 0.03);

        $logs = $this->log->getStepLogs('step1');

        $this->assertCount(3, $logs);
        $this->assertEquals(['x' => 1], $logs[0]['input']);
        $this->assertEquals(['y' => 2], $logs[0]['output']);
        $this->assertEquals(0.01, $logs[0]['duration']);
        $this->assertEquals(['x' => 5], $logs[2]['input']);
    }

    public function testGetStepLogsReturnsEmptyForMissingStep(): void
    {
        $this->assertEmpty($this->log->getStepLogs('nonexistent'));
    }

    public function testCount(): void
    {
        $this->assertEquals(0, $this->log->count('step1'));

        $this->log->record('step1', [], [], 0.01);
        $this->log->record('step1', [], [], 0.02);

        $this->assertEquals(2, $this->log->count('step1'));
    }

    public function testClear(): void
    {
        $this->log->record('step1', [], [], 0.01);
        $this->assertEquals(1, $this->log->count('step1'));

        $this->log->clear('step1');
        $this->assertEquals(0, $this->log->count('step1'));
    }

    public function testGetLoggedSteps(): void
    {
        $this->log->record('alpha', [], [], 0.01);
        $this->log->record('beta', [], [], 0.01);
        $this->log->record('gamma', [], [], 0.01);

        $steps = $this->log->getLoggedSteps();
        sort($steps);

        $this->assertEquals(['alpha', 'beta', 'gamma'], $steps);
    }

    public function testMultipleStepsIsolated(): void
    {
        $this->log->record('step_a', ['a' => 1], ['r' => 'a'], 0.01);
        $this->log->record('step_b', ['b' => 2], ['r' => 'b'], 0.02);

        $this->assertCount(1, $this->log->getStepLogs('step_a'));
        $this->assertCount(1, $this->log->getStepLogs('step_b'));
        $this->assertEquals(['a' => 1], $this->log->getStepLogs('step_a')[0]['input']);
        $this->assertEquals(['b' => 2], $this->log->getStepLogs('step_b')[0]['input']);
    }
}
