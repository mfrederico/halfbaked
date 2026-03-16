<?php

declare(strict_types=1);

namespace HalfBaked\Distillery\Profiles;

use HalfBaked\Distillery\LanguageProfile;

class PhpProfile extends LanguageProfile
{
    public function getLanguage(): string
    {
        return 'php';
    }

    public function getExtensions(): array
    {
        return ['.php', '.phtml'];
    }

    public function getSkipDirs(): array
    {
        return ['vendor', '.git', 'node_modules', 'cache', 'storage', 'logs', 'training'];
    }

    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are generating training data for a specialized PHP AI coding assistant.
The assistant will specialize in:
- FlightPHP (micro-framework, routing, middleware)
- RedBeanPHP (ORM, CRUD, associations, R::find/store/trash)
- OpenSwoole/Swoole (coroutines, async servers, channels, tables)
- P2P networking, gossip protocols, leader election
- Swarm orchestration, task dispatch, agent management
- PHP 8.1+ features (enums, fibers, readonly, match, named args)
- PSR-4 autoloading, PDO/SQLite, tmux automation

Generate diverse, high-quality instruction-response pairs that teach the model
to write code in these specific patterns. Vary the difficulty and question types.
PROMPT;
    }

    public function getPromptTemplates(): array
    {
        return [
            [
                'category' => 'explain',
                'prompt' => <<<'TPL'
Given this PHP code from a real project, generate {n} instruction-response pairs.
Each pair should ask the model to explain what the code does, how it works, or why
certain patterns were chosen.

Code from `{file}`:
```php
{code}
```

Return a JSON array of objects with "instruction" and "response" fields.
Keep responses concise but technically accurate. Reference the specific frameworks used.
TPL,
            ],
            [
                'category' => 'generate',
                'prompt' => <<<'TPL'
Based on this PHP code pattern, generate {n} instruction-response pairs where
someone asks the model to write similar code. The instructions should describe WHAT to build,
and the response should be working PHP code following the same patterns.

Reference code from `{file}`:
```php
{code}
```

Return a JSON array of objects with "instruction" and "response" fields.
Responses should be complete, working PHP code with the same style and framework usage.
TPL,
            ],
            [
                'category' => 'debug',
                'prompt' => <<<'TPL'
Based on this PHP code, generate {n} instruction-response pairs about debugging.
Create realistic bug scenarios: wrong Swoole coroutine usage, RedBean association errors,
FlightPHP routing issues, race conditions, etc. The response should diagnose and fix the issue.

Code context from `{file}`:
```php
{code}
```

Return a JSON array of objects with "instruction" and "response" fields.
TPL,
            ],
            [
                'category' => 'architecture',
                'prompt' => <<<'TPL'
Based on this code's architecture, generate {n} instruction-response pairs about
design decisions. Questions like "How should I structure X?", "What pattern for Y?",
"How do I handle Z with Swoole?"

Code context from `{file}`:
```php
{code}
```

Return a JSON array of objects with "instruction" and "response" fields.
Responses should recommend patterns consistent with this codebase.
TPL,
            ],
            [
                'category' => 'refactor',
                'prompt' => <<<'TPL'
Based on this PHP code, generate {n} instruction-response pairs about refactoring.
Ask the model to improve, optimize, or modernize the code while keeping the same frameworks.

Code from `{file}`:
```php
{code}
```

Return a JSON array of objects with "instruction" and "response" fields.
Show before/after code in responses.
TPL,
            ],
        ];
    }

    public function getStandalonePrompts(): array
    {
        return [
            [
                'category' => 'framework_knowledge',
                'prompt' => <<<'TPL'
Generate {n} instruction-response pairs about FlightPHP framework usage.
Cover: routing, middleware, dependency injection, error handling, JSON responses,
grouping routes, before/after filters, Flight::json(), Flight::halt(), custom methods.

Return a JSON array of objects with "instruction" and "response" fields.
Responses should include working code examples.
TPL,
            ],
            [
                'category' => 'framework_knowledge',
                'prompt' => <<<'TPL'
Generate {n} instruction-response pairs about RedBeanPHP ORM.
Cover: R::dispense, R::store, R::find, R::findOne, R::trash, associations (ownList, sharedList),
bean manipulation, queries with R::getAll, transactions, model formatting (FUSE).

Return a JSON array of objects with "instruction" and "response" fields.
Responses should include working code examples.
TPL,
            ],
            [
                'category' => 'framework_knowledge',
                'prompt' => <<<'TPL'
Generate {n} instruction-response pairs about Swoole/OpenSwoole.
Cover: coroutines (go(), Channel, WaitGroup), HTTP server, WebSocket server,
Table (shared memory), Timer, async TCP client/server, Process, coroutine-safe patterns.

Return a JSON array of objects with "instruction" and "response" fields.
Responses should include working code examples.
TPL,
            ],
            [
                'category' => 'patterns',
                'prompt' => <<<'TPL'
Generate {n} instruction-response pairs about PHP P2P networking patterns.
Cover: TCP mesh networking, gossip protocols, leader election (Bully algorithm),
binary wire protocols (length-prefixed JSON), UDP multicast discovery,
anti-entropy sync, Lamport timestamps, peer deduplication.

Return a JSON array of objects with "instruction" and "response" fields.
Responses should include working PHP code examples using Swoole.
TPL,
            ],
            [
                'category' => 'patterns',
                'prompt' => <<<'TPL'
Generate {n} instruction-response pairs about PHP 8.1+ features in practice.
Cover: enums (backed enums, methods on enums), readonly properties, fibers,
match expressions, named arguments, intersection types, first-class callables,
array unpacking, nullsafe operator.

Return a JSON array of objects with "instruction" and "response" fields.
Show practical usage, not just syntax.
TPL,
            ],
            [
                'category' => 'integration',
                'prompt' => <<<'TPL'
Generate {n} instruction-response pairs about building a task/agent orchestration
system in PHP. Cover: task queues with SQLite, agent lifecycle management,
tmux session automation, MCP (Model Context Protocol) tool implementation,
task dependencies, parent-child task decomposition, status state machines.

Return a JSON array of objects with "instruction" and "response" fields.
Responses should include working PHP code using Swoole and SQLite/PDO.
TPL,
            ],
        ];
    }

    public function getBaseModel(): string
    {
        return 'unsloth/Qwen2.5-Coder-7B-Instruct';
    }

    public function getFunctionPattern(): string
    {
        return '(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\(';
    }

    public function getMetadataExtractor(): string
    {
        return 'php';
    }

    public function getTrainingSystemPrompt(): string
    {
        return 'You are a PHP development assistant specializing in FlightPHP, RedBeanPHP, OpenSwoole, and modern PHP 8.1+ patterns. You write clean, efficient, production-ready code.';
    }
}
