<?php

declare(strict_types=1);

namespace HalfBaked\Tests\Unit;

use HalfBaked\Pipeline\Step;
use PHPUnit\Framework\TestCase;

class StepTest extends TestCase
{
    public function testRenderPromptSubstitution(): void
    {
        $step = new Step('test');
        $step->setPrompt('Hello {{name}}, you are {{age}} years old.');

        $rendered = $step->renderPrompt(['name' => 'Alice', 'age' => 30]);

        $this->assertEquals('Hello Alice, you are 30 years old.', $rendered);
    }

    public function testRenderPromptWithArrayValue(): void
    {
        $step = new Step('test');
        $step->setPrompt('Tags: {{tags}}');

        $rendered = $step->renderPrompt(['tags' => ['php', 'laravel']]);

        $this->assertEquals('Tags: ["php","laravel"]', $rendered);
    }

    public function testValidateOutputPassesValidData(): void
    {
        $step = new Step('test');
        $step->setOutputSchema([
            'name' => 'string',
            'age' => 'integer',
            'score' => 'number',
            'active' => 'boolean',
            'tags' => 'array',
        ]);

        $this->assertTrue($step->validateOutput([
            'name' => 'Alice',
            'age' => 30,
            'score' => 9.5,
            'active' => true,
            'tags' => ['a', 'b'],
        ]));
    }

    public function testValidateOutputFailsMissingRequired(): void
    {
        $step = new Step('test');
        $step->setOutputSchema(['name' => 'string', 'age' => 'integer']);

        $this->assertFalse($step->validateOutput(['name' => 'Alice'])); // missing age
    }

    public function testValidateOutputFailsWrongType(): void
    {
        $step = new Step('test');
        $step->setOutputSchema(['age' => 'integer']);

        $this->assertFalse($step->validateOutput(['age' => 'not a number']));
    }

    public function testValidateOutputAllowsOptionalFields(): void
    {
        $step = new Step('test');
        $step->setOutputSchema([
            'name' => 'string',
            'nickname' => ['type' => 'string', 'required' => false],
        ]);

        $this->assertTrue($step->validateOutput(['name' => 'Alice'])); // nickname optional
    }

    public function testIsBaked(): void
    {
        $step = new Step('test');
        $this->assertFalse($step->isBaked());

        $step->setBakedClass('SomeClass');
        $this->assertTrue($step->isBaked());
    }

    public function testFluentSetters(): void
    {
        $step = new Step('my_step');
        $step->setModel('voidlux-coder')
            ->setPrompt('Do the thing')
            ->setSystemPrompt('You are helpful')
            ->setTemperature(0.5)
            ->setRetries(3)
            ->addContext('project', 'halfbaked');

        $this->assertEquals('my_step', $step->getName());
        $this->assertEquals('voidlux-coder', $step->getModel());
        $this->assertEquals('Do the thing', $step->getPrompt());
        $this->assertEquals('You are helpful', $step->getSystemPrompt());
        $this->assertEquals(0.5, $step->getTemperature());
        $this->assertEquals(3, $step->getRetries());
        $this->assertEquals(['project' => 'halfbaked'], $step->getContext());
    }
}
