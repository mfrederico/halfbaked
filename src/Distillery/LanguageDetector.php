<?php

declare(strict_types=1);

namespace HalfBaked\Distillery;

class LanguageDetector
{
    private const SKIP_DIRS = ['.git', 'vendor', 'node_modules', '__pycache__', 'dist', 'build', '.cache', '.venv', 'venv'];

    private const EXTENSION_MAP = [
        '.php' => 'php',
        '.phtml' => 'php',
        '.py' => 'python',
        '.pyi' => 'python',
        '.js' => 'javascript',
        '.ts' => 'javascript',
        '.jsx' => 'javascript',
        '.tsx' => 'javascript',
        '.mjs' => 'javascript',
        '.css' => 'css',
        '.scss' => 'css',
        '.sass' => 'css',
        '.less' => 'css',
        '.go' => 'go',
        '.rs' => 'rust',
        '.cpp' => 'cpp',
        '.cc' => 'cpp',
        '.cxx' => 'cpp',
        '.h' => 'cpp',
        '.hpp' => 'cpp',
    ];

    private const DEPENDENCY_FILES = [
        'composer.json' => 'php',
        'package.json' => 'javascript',
        'requirements.txt' => 'python',
        'pyproject.toml' => 'python',
        'Pipfile' => 'python',
        'setup.py' => 'python',
        'go.mod' => 'go',
        'Cargo.toml' => 'rust',
        'CMakeLists.txt' => 'cpp',
    ];

    private const FRAMEWORK_PATTERNS = [
        'php' => [
            'flightphp/core' => 'flightphp',
            'mikecao/flight' => 'flightphp',
            'gabordemooij/redbean' => 'redbeanphp',
            'openswoole/openswoole' => 'openswoole',
            'swoole/ide-helper' => 'swoole',
            'laravel/framework' => 'laravel',
            'symfony/symfony' => 'symfony',
            'slim/slim' => 'slim',
        ],
        'javascript' => [
            'react' => 'react',
            'react-dom' => 'react',
            'vue' => 'vue',
            'next' => 'nextjs',
            'nuxt' => 'nuxt',
            '@angular/core' => 'angular',
            'express' => 'express',
            'fastify' => 'fastify',
            'svelte' => 'svelte',
        ],
        'python' => [
            'django' => 'django',
            'flask' => 'flask',
            'fastapi' => 'fastapi',
            'tornado' => 'tornado',
            'aiohttp' => 'aiohttp',
            'starlette' => 'starlette',
        ],
    ];

    public function detect(string $repoPath): array
    {
        $filesByExtension = $this->countFilesByExtension($repoPath);
        $filesByLanguage = $this->groupByLanguage($filesByExtension);
        $depLanguage = $this->detectFromDependencyFiles($repoPath);
        $frameworks = $this->detectFrameworks($repoPath, $depLanguage);

        // Pick primary language: dependency file wins if available, otherwise most files
        if ($depLanguage && isset($filesByLanguage[$depLanguage])) {
            $language = $depLanguage;
        } else {
            arsort($filesByLanguage);
            $language = array_key_first($filesByLanguage) ?? 'unknown';
        }

        $totalFiles = $filesByLanguage[$language] ?? 0;

        return [
            'language' => $language,
            'frameworks' => $frameworks,
            'file_count' => $totalFiles,
            'files_by_extension' => $filesByExtension,
            'files_by_language' => $filesByLanguage,
        ];
    }

    private function countFilesByExtension(string $path): array
    {
        $counts = [];

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $current) {
                    if ($current->isDir()) {
                        return !in_array($current->getFilename(), self::SKIP_DIRS, true);
                    }
                    return true;
                }
            )
        );

        foreach ($iter as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = '.' . strtolower($file->getExtension());
            if (isset(self::EXTENSION_MAP[$ext])) {
                $counts[$ext] = ($counts[$ext] ?? 0) + 1;
            }
        }

        arsort($counts);
        return $counts;
    }

    private function groupByLanguage(array $filesByExtension): array
    {
        $byLang = [];
        foreach ($filesByExtension as $ext => $count) {
            $lang = self::EXTENSION_MAP[$ext] ?? null;
            if ($lang) {
                $byLang[$lang] = ($byLang[$lang] ?? 0) + $count;
            }
        }
        return $byLang;
    }

    private function detectFromDependencyFiles(string $repoPath): ?string
    {
        foreach (self::DEPENDENCY_FILES as $file => $language) {
            if (file_exists("{$repoPath}/{$file}")) {
                return $language;
            }
        }
        return null;
    }

    private function detectFrameworks(string $repoPath, ?string $language): array
    {
        $frameworks = [];

        // PHP: check composer.json
        $composerFile = "{$repoPath}/composer.json";
        if (file_exists($composerFile)) {
            $composer = json_decode(file_get_contents($composerFile), true) ?: [];
            $requires = array_merge(
                array_keys($composer['require'] ?? []),
                array_keys($composer['require-dev'] ?? [])
            );
            foreach (self::FRAMEWORK_PATTERNS['php'] ?? [] as $package => $framework) {
                foreach ($requires as $req) {
                    if (str_contains(strtolower($req), strtolower($package))) {
                        $frameworks[] = $framework;
                    }
                }
            }
        }

        // JS/TS: check package.json
        $packageFile = "{$repoPath}/package.json";
        if (file_exists($packageFile)) {
            $pkg = json_decode(file_get_contents($packageFile), true) ?: [];
            $deps = array_merge(
                array_keys($pkg['dependencies'] ?? []),
                array_keys($pkg['devDependencies'] ?? [])
            );
            foreach (self::FRAMEWORK_PATTERNS['javascript'] ?? [] as $package => $framework) {
                if (in_array($package, $deps, true)) {
                    $frameworks[] = $framework;
                }
            }
        }

        // Python: check requirements.txt and pyproject.toml
        $reqFile = "{$repoPath}/requirements.txt";
        if (file_exists($reqFile)) {
            $content = strtolower(file_get_contents($reqFile));
            foreach (self::FRAMEWORK_PATTERNS['python'] ?? [] as $package => $framework) {
                if (str_contains($content, strtolower($package))) {
                    $frameworks[] = $framework;
                }
            }
        }

        return array_unique($frameworks);
    }
}
