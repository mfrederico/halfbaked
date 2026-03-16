<?php

declare(strict_types=1);

namespace HalfBaked\Distillery\Profiles;

use HalfBaked\Distillery\LanguageProfile;

class CssProfile extends LanguageProfile
{
    public function getLanguage(): string
    {
        return 'css';
    }

    public function getExtensions(): array
    {
        return ['.css', '.scss', '.sass', '.less', '.html', '.htm', '.php', '.phtml', '.vue', '.svelte'];
    }

    public function getSkipDirs(): array
    {
        return ['node_modules', '.git', 'dist', 'build', '.cache', 'vendor', 'libs', 'bower_components', '.playwright-mcp'];
    }

    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are generating training data for a specialized CSS/UI AI coding assistant.
The assistant will specialize in:
- Bootstrap 5.3 (grid system, components, utilities, dark mode)
- Responsive design (mobile-first, breakpoints, flexbox, CSS Grid)
- SCSS/Sass (variables, mixins, nesting, partials, functions)
- jQuery integration (DOM manipulation, event handling, AJAX, plugins)
- Accessibility (ARIA attributes, semantic HTML, keyboard navigation, screen readers)
- CSS animations and transitions
- Component-based UI architecture
- Form styling and validation feedback

Generate diverse, high-quality instruction-response pairs that teach the model
to write UI code following these patterns. Include both CSS and HTML examples.
PROMPT;
    }

    public function getPromptTemplates(): array
    {
        return [
            [
                'category' => 'explain',
                'prompt' => <<<'TPL'
Given this CSS/HTML code from a real project, generate {n} instruction-response pairs.
Each pair should ask the model to explain the styling approach, layout technique,
or component structure.

Code from `{file}`:
```
{code}
```

Return a JSON array of objects with "instruction" and "response" fields.
Keep responses concise but technically accurate. Reference Bootstrap classes when applicable.
TPL,
            ],
            [
                'category' => 'generate',
                'prompt' => <<<'TPL'
Based on this CSS/HTML pattern, generate {n} instruction-response pairs where
someone asks the model to create similar UI components. Instructions should describe
the desired visual result, responses should be working HTML+CSS code.

Reference code from `{file}`:
```
{code}
```

Return a JSON array of objects with "instruction" and "response" fields.
Responses should use Bootstrap 5.3 classes where appropriate.
TPL,
            ],
            [
                'category' => 'debug',
                'prompt' => <<<'TPL'
Based on this CSS/HTML code, generate {n} instruction-response pairs about debugging
layout and styling issues. Create realistic scenarios: broken flexbox layouts,
responsive breakpoint problems, z-index conflicts, specificity issues, accessibility gaps.

Code context from `{file}`:
```
{code}
```

Return a JSON array of objects with "instruction" and "response" fields.
TPL,
            ],
            [
                'category' => 'architecture',
                'prompt' => <<<'TPL'
Based on this UI code's structure, generate {n} instruction-response pairs about
component architecture. Questions about organizing CSS, naming conventions,
responsive strategies, theme systems, and Bootstrap customization.

Code context from `{file}`:
```
{code}
```

Return a JSON array of objects with "instruction" and "response" fields.
TPL,
            ],
            [
                'category' => 'refactor',
                'prompt' => <<<'TPL'
Based on this CSS/HTML code, generate {n} instruction-response pairs about refactoring.
Modernize to use CSS Grid/Flexbox, convert to SCSS, improve accessibility,
add responsive behavior, adopt Bootstrap 5 patterns.

Code from `{file}`:
```
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
Generate {n} instruction-response pairs about Bootstrap 5.3 components and grid system.
Cover: containers, rows, columns, breakpoints, cards, modals, navbars, forms,
buttons, alerts, toasts, offcanvas, accordions, utility classes, dark mode.

Return a JSON array of objects with "instruction" and "response" fields.
Responses should include working HTML+CSS examples using Bootstrap 5.3 CDN classes.
TPL,
            ],
            [
                'category' => 'framework_knowledge',
                'prompt' => <<<'TPL'
Generate {n} instruction-response pairs about responsive web design patterns.
Cover: mobile-first approach, media queries, flexbox layouts, CSS Grid,
responsive images, viewport units, container queries, fluid typography.

Return a JSON array of objects with "instruction" and "response" fields.
Responses should include working CSS examples.
TPL,
            ],
            [
                'category' => 'framework_knowledge',
                'prompt' => <<<'TPL'
Generate {n} instruction-response pairs about jQuery integration with Bootstrap 5.
Cover: DOM manipulation, event delegation, AJAX calls, dynamic content loading,
form validation, modal control, tooltip initialization, DataTables integration.

Return a JSON array of objects with "instruction" and "response" fields.
Responses should include working JavaScript/jQuery code examples.
TPL,
            ],
            [
                'category' => 'patterns',
                'prompt' => <<<'TPL'
Generate {n} instruction-response pairs about web accessibility (a11y) best practices.
Cover: semantic HTML, ARIA roles/attributes, keyboard navigation, focus management,
screen reader compatibility, color contrast, skip links, form labels.

Return a JSON array of objects with "instruction" and "response" fields.
Responses should include working HTML examples with proper accessibility attributes.
TPL,
            ],
            [
                'category' => 'patterns',
                'prompt' => <<<'TPL'
Generate {n} instruction-response pairs about SCSS/Sass best practices.
Cover: variables, mixins, nesting (avoid deep nesting), partials, @use/@forward,
functions, placeholder selectors, BEM naming, theming with CSS custom properties.

Return a JSON array of objects with "instruction" and "response" fields.
Responses should include working SCSS examples.
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
        // Match CSS rule blocks and media queries
        return '(?:@media\s+[^{]+|[.#][\w-]+(?:\s+[.#>~+\w-]+)*)\s*\{';
    }

    public function getMetadataExtractor(): string
    {
        return 'css';
    }

    public function getTrainingSystemPrompt(): string
    {
        return 'You are a CSS/UI development assistant specializing in Bootstrap 5.3, responsive design, SCSS, jQuery, and web accessibility. You write clean, maintainable UI code.';
    }
}
