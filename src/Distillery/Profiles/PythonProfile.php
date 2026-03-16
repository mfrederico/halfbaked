<?php

declare(strict_types=1);

namespace HalfBaked\Distillery\Profiles;

use HalfBaked\Distillery\LanguageProfile;

class PythonProfile extends LanguageProfile
{
    public function getLanguage(): string
    {
        return 'python';
    }

    public function getExtensions(): array
    {
        return ['.py', '.pyi'];
    }

    public function getSkipDirs(): array
    {
        return ['__pycache__', '.git', 'venv', '.venv', 'env', '.env', 'dist', 'build', '.eggs', '*.egg-info', 'node_modules'];
    }

    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are generating training data for a specialized Python AI coding assistant.
The assistant will specialize in:
- Django (models, views, templates, ORM, admin, DRF)
- Flask (routes, blueprints, Jinja2, SQLAlchemy)
- FastAPI (async endpoints, Pydantic models, dependency injection)
- Python 3.10+ features (match, type hints, dataclasses, protocols)
- asyncio (coroutines, tasks, gather, event loops)
- Testing (pytest, fixtures, mocking, parametrize)
- Type annotations and mypy compliance

Generate diverse, high-quality instruction-response pairs that teach the model
to write Python code following these patterns.
PROMPT;
    }

    public function getPromptTemplates(): array
    {
        return [
            [
                'category' => 'explain',
                'prompt' => <<<'TPL'
Given this Python code from a real project, generate {n} instruction-response pairs.
Each pair should explain what the code does, the patterns used, or design choices.

Code from `{file}`:
```python
{code}
```

Return a JSON array of objects with "instruction" and "response" fields.
TPL,
            ],
            [
                'category' => 'generate',
                'prompt' => <<<'TPL'
Based on this Python code pattern, generate {n} instruction-response pairs asking
to write similar code. Instructions describe WHAT to build, responses are working Python.

Reference code from `{file}`:
```python
{code}
```

Return a JSON array of objects with "instruction" and "response" fields.
TPL,
            ],
            [
                'category' => 'debug',
                'prompt' => <<<'TPL'
Based on this Python code, generate {n} instruction-response pairs about debugging.
Create realistic bugs: async issues, type errors, ORM mistakes, import problems.

Code context from `{file}`:
```python
{code}
```

Return a JSON array of objects with "instruction" and "response" fields.
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
Generate {n} instruction-response pairs about Django/Flask/FastAPI usage.
Cover: routing, models, serialization, middleware, authentication, database queries.

Return a JSON array of objects with "instruction" and "response" fields.
Responses should include working code examples.
TPL,
            ],
            [
                'category' => 'patterns',
                'prompt' => <<<'TPL'
Generate {n} instruction-response pairs about modern Python patterns.
Cover: dataclasses, protocols, match statements, async/await, type hints,
context managers, generators, decorators.

Return a JSON array of objects with "instruction" and "response" fields.
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
        return '(?:def|async\s+def)\s+(\w+)\s*\(';
    }

    public function getMetadataExtractor(): string
    {
        return 'python';
    }
}
