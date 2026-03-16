<?php

declare(strict_types=1);

namespace HalfBaked\Distillery\Steps;

use HalfBaked\Pipeline\BakedStepInterface;

class GenerateDatasetStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        $samplesFile = $input['samples_file'] ?? throw new \RuntimeException('Missing samples_file');
        $profileClass = $input['profile_class'] ?? throw new \RuntimeException('Missing profile_class');
        $workDir = $input['work_dir'] ?? throw new \RuntimeException('Missing work_dir');
        $language = $input['language'] ?? 'unknown';
        $apiKey = $input['api_key'] ?? getenv('ANTHROPIC_API_KEY');
        $targetExamples = $input['target_examples'] ?? null;

        if (!$apiKey) {
            throw new \RuntimeException('Missing API key. Set ANTHROPIC_API_KEY or pass api_key in input.');
        }

        $dataDir = "{$workDir}/data";
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $datasetFile = "{$dataDir}/dataset.jsonl";
        $configFile = "{$workDir}/distill_config.json";

        /** @var \HalfBaked\Distillery\LanguageProfile $profile */
        $profile = new $profileClass();
        $config = $profile->toDistillConfig($samplesFile, $datasetFile, $apiKey);

        if ($targetExamples !== null) {
            $config['target_examples'] = (int) $targetExamples;
        }

        // Add training system prompt for output formatting
        $config['training_system_prompt'] = $profile->getTrainingSystemPrompt();

        // LLM provider settings (anthropic or openai-compatible)
        $provider = $input['distill_provider'] ?? 'anthropic';
        $distillModel = $input['distill_model'] ?? '';
        $apiBase = $input['api_base'] ?? '';
        $openaiApiKey = $input['openai_api_key'] ?? '';

        $config['provider'] = $provider;
        if ($distillModel) {
            $config['model'] = $distillModel;
        }
        if ($provider === 'openai') {
            if ($apiBase) {
                $config['api_base'] = $apiBase;
            }
            if ($openaiApiKey) {
                $config['api_key'] = $openaiApiKey;
            }
        }

        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $halfbakedRoot = dirname(__DIR__, 3);
        $script = "{$halfbakedRoot}/training/distill.py";

        if (!file_exists($script)) {
            throw new \RuntimeException("Distill script not found: {$script}");
        }

        // Check if we should resume a previous run
        $resumeFlag = '';
        $progressFile = "{$dataDir}/.generate_progress.json";
        if (file_exists($progressFile) && file_exists($datasetFile)) {
            $existingPairs = count(file($datasetFile, FILE_SKIP_EMPTY_LINES));
            if ($existingPairs > 0) {
                $resumeFlag = ' --resume';
            }
        }

        // Long-running — stream output
        $logFile = "{$workDir}/distill.log";
        $result = $this->runProcessWithLogging(
            sprintf('%s %s --config %s%s', escapeshellarg(\HalfBaked\Distillery\LanguageProfile::pythonBin()), escapeshellarg($script), escapeshellarg($configFile), $resumeFlag),
            $workDir,
            $logFile
        );

        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException("Distill failed (exit {$result['exit_code']}). See {$logFile}");
        }

        // Count dataset entries
        $datasetCount = 0;
        if (file_exists($datasetFile)) {
            $datasetCount = count(file($datasetFile, FILE_SKIP_EMPTY_LINES));
        }

        return array_merge($input, [
            'dataset_file' => $datasetFile,
            'dataset_count' => $datasetCount,
            'work_dir' => $workDir,
            'language' => $language,
            'profile_class' => $profileClass,
        ]);
    }

    private function runProcessWithLogging(string $command, ?string $cwd, string $logFile): array
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

        $logFh = fopen($logFile, 'w');
        $stdout = '';
        $stderr = '';

        // Read both streams
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $read = [];
            if (is_resource($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (is_resource($pipes[2])) {
                $read[] = $pipes[2];
            }
            if (empty($read)) {
                break;
            }

            $write = $except = null;
            $changed = @stream_select($read, $write, $except, 1);

            if ($changed === false) {
                break;
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        if ($stream === $pipes[1]) {
                            fclose($pipes[1]);
                            $pipes[1] = null;
                        } else {
                            fclose($pipes[2]);
                            $pipes[2] = null;
                        }
                    }
                    continue;
                }
                if ($stream === ($pipes[1] ?? null)) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
                if ($logFh) {
                    fwrite($logFh, $chunk);
                    fflush($logFh);
                }
            }

            // Check if both pipes are closed
            if (!is_resource($pipes[1] ?? null) && !is_resource($pipes[2] ?? null)) {
                break;
            }
        }

        if (is_resource($pipes[1] ?? null)) {
            fclose($pipes[1]);
        }
        if (is_resource($pipes[2] ?? null)) {
            fclose($pipes[2]);
        }
        if ($logFh) {
            fclose($logFh);
        }

        $exitCode = proc_close($process);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode];
    }
}
