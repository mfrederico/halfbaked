<?php

declare(strict_types=1);

namespace HalfBaked\Distillery\Steps;

use HalfBaked\Distillery\RepoManager;
use HalfBaked\Pipeline\BakedStepInterface;

class CloneRepoStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        $workDir = $input['work_dir'] ?? throw new \RuntimeException('Missing work_dir');

        // Support multi-source: sources array or legacy single source
        $sources = $input['sources'] ?? [];
        if (empty($sources)) {
            $source = $input['source'] ?? throw new \RuntimeException('Missing source');
            $sources = [$source];
        }
        $sourceType = $input['source_type'] ?? 'git';

        $repo = new RepoManager();
        $repoPaths = [];

        if (count($sources) === 1) {
            // Single source — keep original flat layout for backwards compat
            $repoPath = "{$workDir}/repo";

            if ($sourceType === 'local') {
                if (!$repo->isLocalPath($sources[0])) {
                    throw new \RuntimeException("Local path not found: {$sources[0]}");
                }
                if (!file_exists($repoPath)) {
                    symlink(realpath($sources[0]), $repoPath);
                }
            } else {
                if (!is_dir($repoPath)) {
                    $repo->clone($sources[0], $repoPath);
                }
            }

            if (!is_dir($repoPath) && !is_link($repoPath)) {
                throw new \RuntimeException("Repository not found at: {$repoPath}");
            }

            $repoPaths[] = realpath($repoPath) ?: $repoPath;
        } else {
            // Multiple sources — each in repos/{basename}/
            $reposDir = "{$workDir}/repos";
            if (!is_dir($reposDir)) {
                mkdir($reposDir, 0755, true);
            }

            foreach ($sources as $src) {
                $basename = basename(rtrim($src, '/'));
                if (preg_match('/([^\/]+?)(?:\.git)?$/', $src, $m)) {
                    $basename = $m[1];
                }
                $repoPath = "{$reposDir}/{$basename}";

                $srcType = $sourceType;
                if (!preg_match('#^(https?://|git@|[\w.-]+\.\w+:)#', $src)) {
                    $srcType = 'local';
                }

                if ($srcType === 'local') {
                    if (!$repo->isLocalPath($src)) {
                        throw new \RuntimeException("Local path not found: {$src}");
                    }
                    if (!file_exists($repoPath)) {
                        symlink(realpath($src), $repoPath);
                    }
                } else {
                    if (!is_dir($repoPath)) {
                        $repo->clone($src, $repoPath);
                    }
                }

                if (!is_dir($repoPath) && !is_link($repoPath)) {
                    throw new \RuntimeException("Repository not found at: {$repoPath}");
                }

                $repoPaths[] = realpath($repoPath) ?: $repoPath;
            }
        }

        return array_merge($input, [
            'repo_path' => $repoPaths[0],
            'repo_paths' => $repoPaths,
            'source' => $sources[0],
            'sources' => $sources,
            'source_type' => $sourceType,
            'work_dir' => $workDir,
        ]);
    }
}
