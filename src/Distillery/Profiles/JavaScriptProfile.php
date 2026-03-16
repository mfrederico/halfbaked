<?php

declare(strict_types=1);

namespace HalfBaked\Distillery\Profiles;

use HalfBaked\Distillery\LanguageProfile;

class JavaScriptProfile extends LanguageProfile
{
    public function getLanguage(): string
    {
        return 'javascript';
    }

    public function getExtensions(): array
    {
        return ['.js', '.ts', '.jsx', '.tsx', '.mjs'];
    }

    public function getSkipDirs(): array
    {
        return ['node_modules', '.git', 'dist', 'build', '.next', '.nuxt', 'coverage', '.cache'];
    }

    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are generating training data for a specialized JavaScript/TypeScript AI coding assistant.
The assistant will specialize in:
- React (hooks, components, state management, context, effects)
- Vue 3 (composition API, reactivity, components, Pinia)
- Node.js (Express, Fastify, middleware, async patterns)
- TypeScript (interfaces, generics, utility types, strict mode)
- ES modules, async/await, Promises
- Testing (Jest, Vitest, Testing Library)
- Modern build tools (Vite, esbuild)

Generate diverse, high-quality instruction-response pairs that teach the model
to write JavaScript/TypeScript code following these patterns.
PROMPT;
    }

    public function getPromptTemplates(): array
    {
        return [
            [
                'category' => 'explain',
                'prompt' => <<<'TPL'
Given this JavaScript/TypeScript code from a real project, generate {n} instruction-response
pairs. Each pair should explain the code, patterns, or framework usage.

Code from `{file}`:
```
{code}
```

Return a JSON array of objects with "instruction" and "response" fields.
TPL,
            ],
            [
                'category' => 'generate',
                'prompt' => <<<'TPL'
Based on this JS/TS code pattern, generate {n} instruction-response pairs asking
to write similar code. Instructions describe WHAT to build, responses are working code.

Reference code from `{file}`:
```
{code}
```

Return a JSON array of objects with "instruction" and "response" fields.
TPL,
            ],
            [
                'category' => 'debug',
                'prompt' => <<<'TPL'
Based on this JS/TS code, generate {n} instruction-response pairs about debugging.
Create realistic bugs: async issues, React hook violations, type errors, state management bugs.

Code context from `{file}`:
```
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
Generate {n} instruction-response pairs about React/Vue/Node.js usage.
Cover: component patterns, hooks, routing, state management, API integration, SSR.

Return a JSON array of objects with "instruction" and "response" fields.
Responses should include working code examples.
TPL,
            ],
            [
                'category' => 'patterns',
                'prompt' => <<<'TPL'
Generate {n} instruction-response pairs about TypeScript and modern JS patterns.
Cover: generics, utility types, discriminated unions, async iterators,
Proxy/Reflect, module patterns, error handling.

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
        return '(?:export\s+)?(?:async\s+)?(?:function\s+(\w+)|(?:const|let|var)\s+(\w+)\s*=\s*(?:async\s+)?(?:\([^)]*\)|[\w]+)\s*=>)';
    }

    public function getMetadataExtractor(): string
    {
        return 'javascript';
    }
}
