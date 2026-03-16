<?php

declare(strict_types=1);

namespace HalfBaked\Tests\Unit;

use HalfBaked\Pipeline\BakedStepInterface;
use HalfBaked\Pipeline\Step;
use HalfBaked\Runner\BakedRunner;
use PHPUnit\Framework\TestCase;

class BakedRunnerTest extends TestCase
{
    public function testExecutesBakedClass(): void
    {
        $step = new Step('test');
        $step->setBakedClass(AdderStep::class);

        $runner = new BakedRunner($step);
        $result = $runner->execute(['a' => 3, 'b' => 4]);

        $this->assertEquals(['sum' => 7], $result);
    }

    public function testThrowsOnMissingClass(): void
    {
        $step = new Step('test');
        $step->setBakedClass('NonExistentClass');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');

        new BakedRunner($step);
    }

    public function testThrowsOnNonBakedInterface(): void
    {
        $step = new Step('test');
        $step->setBakedClass(\stdClass::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/BakedStepInterface/');

        new BakedRunner($step);
    }

    public function testThrowsOnNoBakedClass(): void
    {
        $step = new Step('test');

        $this->expectException(\LogicException::class);

        new BakedRunner($step);
    }
}

class AdderStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        return ['sum' => $input['a'] + $input['b']];
    }
}
