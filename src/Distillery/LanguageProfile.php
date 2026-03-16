<?php

declare(strict_types=1);

namespace HalfBaked\Distillery;

abstract class LanguageProfile
{
    abstract public function getLanguage(): string;
    abstract public function getExtensions(): array;
    abstract public function getSkipDirs(): array;
    abstract public function getSystemPrompt(): string;
    abstract public function getPromptTemplates(): array;
    abstract public function getStandalonePrompts(): array;
    abstract public function getBaseModel(): string;
    abstract public function getFunctionPattern(): string;
    abstract public function getMetadataExtractor(): string;

    public function getTargetExamples(): int
    {
        return 1000;
    }

    public function getBatchSize(): int
    {
        return 5;
    }

    /** Generate config JSON for extract.py */
    public function toExtractConfig(string $repoPath, string $outputFile): array
    {
        return [
            'project_path' => $repoPath,
            'output_file' => $outputFile,
            'language' => $this->getLanguage(),
            'extensions' => $this->getExtensions(),
            'skip_dirs' => $this->getSkipDirs(),
            'function_pattern' => $this->getFunctionPattern(),
            'metadata_extractor' => $this->getMetadataExtractor(),
        ];
    }

    /** Generate config JSON for distill.py */
    public function toDistillConfig(string $samplesFile, string $outputFile, string $apiKey): array
    {
        return [
            'samples_file' => $samplesFile,
            'output_file' => $outputFile,
            'api_key' => $apiKey,
            'system_prompt' => $this->getSystemPrompt(),
            'prompt_templates' => $this->getPromptTemplates(),
            'standalone_prompts' => $this->getStandalonePrompts(),
            'target_examples' => $this->getTargetExamples(),
            'batch_size' => $this->getBatchSize(),
        ];
    }

    /** Get profile by language name */
    public static function forLanguage(string $language): self
    {
        return match (strtolower($language)) {
            'php' => new Profiles\PhpProfile(),
            'css', 'html' => new Profiles\CssProfile(),
            'python' => new Profiles\PythonProfile(),
            'javascript', 'typescript', 'js', 'ts' => new Profiles\JavaScriptProfile(),
            default => throw new \RuntimeException("No profile for language: {$language}"),
        };
    }

    /** Short system prompt for training data formatting */
    public function getTrainingSystemPrompt(): string
    {
        $lang = ucfirst($this->getLanguage());
        return "You are a {$lang} development assistant. You write clean, efficient, production-ready code.";
    }

    /** Resolve Python binary: settings override > env var > venv > system python3 */
    public static function pythonBin(): string
    {
        // Check env var (set by launchDistillation from settings)
        $envPython = getenv('HALFBAKED_PYTHON');
        if ($envPython && file_exists($envPython)) {
            return $envPython;
        }

        $venv = dirname(__DIR__, 2) . '/training/.venv/bin/python3';
        return file_exists($venv) ? $venv : 'python3';
    }
}
