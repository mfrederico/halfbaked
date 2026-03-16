<?php

declare(strict_types=1);

namespace HalfBaked\Distillery\Steps;

use HalfBaked\Pipeline\BakedStepInterface;

class ExtractCodeStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        $profileClass = $input['profile_class'] ?? throw new \RuntimeException('Missing profile_class');
        $workDir = $input['work_dir'] ?? throw new \RuntimeException('Missing work_dir');
        $language = $input['language'] ?? 'unknown';

        // Support multi-repo: repo_paths array or legacy single repo_path
        $repoPaths = $input['repo_paths'] ?? [];
        if (empty($repoPaths)) {
            $repoPath = $input['repo_path'] ?? throw new \RuntimeException('Missing repo_path');
            $repoPaths = [$repoPath];
        }

        $dataDir = "{$workDir}/data";
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $samplesFile = "{$dataDir}/code_samples.jsonl";
        $halfbakedRoot = dirname(__DIR__, 3);
        $script = "{$halfbakedRoot}/training/extract.py";

        if (!file_exists($script)) {
            throw new \RuntimeException("Extract script not found: {$script}");
        }

        /** @var \HalfBaked\Distillery\LanguageProfile $profile */
        $profile = new $profileClass();

        if (count($repoPaths) === 1) {
            // Single repo — original behavior
            $configFile = "{$workDir}/extract_config.json";
            $config = $profile->toExtractConfig($repoPaths[0], $samplesFile);
            file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $result = $this->runProcess(
                sprintf('%s %s --config %s', escapeshellarg(\HalfBaked\Distillery\LanguageProfile::pythonBin()), escapeshellarg($script), escapeshellarg($configFile)),
                $workDir
            );

            if ($result['exit_code'] !== 0) {
                throw new \RuntimeException("Extract failed (exit {$result['exit_code']}): {$result['stderr']}");
            }
        } else {
            // Multiple repos — extract each into temp file, then merge
            $tempFiles = [];
            foreach ($repoPaths as $i => $repoPath) {
                $tempOutput = "{$dataDir}/code_samples_" . basename($repoPath) . ".jsonl";
                $configFile = "{$workDir}/extract_config_{$i}.json";
                $config = $profile->toExtractConfig($repoPath, $tempOutput);
                file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                $result = $this->runProcess(
                    sprintf('%s %s --config %s', escapeshellarg(\HalfBaked\Distillery\LanguageProfile::pythonBin()), escapeshellarg($script), escapeshellarg($configFile)),
                    $workDir
                );

                if ($result['exit_code'] !== 0) {
                    throw new \RuntimeException("Extract failed for " . basename($repoPath) . " (exit {$result['exit_code']}): {$result['stderr']}");
                }

                if (file_exists($tempOutput)) {
                    $tempFiles[] = $tempOutput;
                }
            }

            // Merge all temp JSONL files into one
            $fh = fopen($samplesFile, 'w');
            foreach ($tempFiles as $tempFile) {
                $contents = file_get_contents($tempFile);
                if ($contents) {
                    fwrite($fh, $contents);
                    if (!str_ends_with($contents, "\n")) {
                        fwrite($fh, "\n");
                    }
                }
            }
            fclose($fh);
        }

        // Count samples
        $samplesCount = 0;
        if (file_exists($samplesFile)) {
            $samplesCount = count(file($samplesFile, FILE_SKIP_EMPTY_LINES));
        }

        if ($samplesCount === 0) {
            throw new \RuntimeException("No code samples extracted. Check repo paths and language.");
        }

        return array_merge($input, [
            'samples_file' => $samplesFile,
            'samples_count' => $samplesCount,
            'work_dir' => $workDir,
            'language' => $language,
            'profile_class' => $profileClass,
        ]);
    }

    private function runProcess(string $command, ?string $cwd = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to start: {$command}");
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode];
    }
}
