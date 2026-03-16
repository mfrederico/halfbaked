<?php

declare(strict_types=1);

namespace HalfBaked\Distillery\Steps;

use HalfBaked\Distillery\LanguageDetector;
use HalfBaked\Distillery\LanguageProfile;
use HalfBaked\Pipeline\BakedStepInterface;

class DetectLanguageStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        $repoPath = $input['repo_path'] ?? throw new \RuntimeException('Missing repo_path');
        $workDir = $input['work_dir'] ?? throw new \RuntimeException('Missing work_dir');
        $languageOverride = $input['language'] ?? null;

        $detector = new LanguageDetector();
        $result = $detector->detect($repoPath);

        $language = $languageOverride ?: $result['language'];
        $profile = LanguageProfile::forLanguage($language);

        return array_merge($input, [
            'repo_path' => $repoPath,
            'language' => $language,
            'frameworks' => $result['frameworks'],
            'file_count' => $result['files_by_language'][$language] ?? $result['file_count'],
            'profile_class' => get_class($profile),
            'work_dir' => $workDir,
        ]);
    }
}
