<?php

declare(strict_types=1);

namespace HalfBaked\Distillery\Steps;

use HalfBaked\Pipeline\BakedStepInterface;

class ExportGgufStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        $mergedModelPath = $input['merged_model_path'] ?? throw new \RuntimeException('Missing merged_model_path');
        $profileClass = $input['profile_class'] ?? throw new \RuntimeException('Missing profile_class');
        $workDir = $input['work_dir'] ?? throw new \RuntimeException('Missing work_dir');

        /** @var \HalfBaked\Distillery\LanguageProfile $profile */
        $profile = new $profileClass();

        $modelName = $input['model_name'] ?? basename($workDir);
        $quantization = $input['quantization'] ?? 'Q8_0';
        $systemPrompt = $profile->getTrainingSystemPrompt();

        $ggufDir = "{$workDir}/output/gguf";
        if (!is_dir($ggufDir)) {
            mkdir($ggufDir, 0755, true);
        }

        $halfbakedRoot = dirname(__DIR__, 3);
        $script = "{$halfbakedRoot}/training/export.sh";

        if (!file_exists($script)) {
            throw new \RuntimeException("Export script not found: {$script}");
        }

        $cmd = sprintf(
            'bash %s %s %s %s %s %s',
            escapeshellarg($script),
            escapeshellarg($modelName),
            escapeshellarg($mergedModelPath),
            escapeshellarg($ggufDir),
            escapeshellarg($quantization),
            escapeshellarg($systemPrompt)
        );

        $logFile = "{$workDir}/export.log";
        $result = $this->runProcessWithLogging($cmd, $workDir, $logFile);

        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException("Export failed (exit {$result['exit_code']}). See {$logFile}");
        }

        // Parse GGUF path and size from output
        $ggufPath = '';
        $ggufSize = 0;

        if (preg_match('/GGUF_PATH:\s*(.+)/', $result['stdout'], $m)) {
            $ggufPath = trim($m[1]);
        }
        if (preg_match('/GGUF_SIZE:\s*(\d+)/', $result['stdout'], $m)) {
            $ggufSize = (int) $m[1];
        }

        // Fallback: find the GGUF file
        if (!$ggufPath || !file_exists($ggufPath)) {
            $pattern = "{$ggufDir}/{$modelName}-{$quantization}.gguf";
            if (file_exists($pattern)) {
                $ggufPath = $pattern;
                $ggufSize = (int) filesize($pattern);
            }
        }

        if (!$ggufPath) {
            throw new \RuntimeException("GGUF file not found after export");
        }

        return array_merge($input, [
            'gguf_path' => $ggufPath,
            'gguf_size' => $ggufSize,
            'model_name' => $modelName,
            'registered' => true,
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
            @stream_select($read, $write, $except, 1);

            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        fclose($stream);
                        if ($stream === $pipes[1]) {
                            $pipes[1] = null;
                        } else {
                            $pipes[2] = null;
                        }
                    }
                    continue;
                }
                $stdout .= $chunk;
                if ($logFh) {
                    fwrite($logFh, $chunk);
                    fflush($logFh);
                }
            }

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

        return ['stdout' => $stdout, 'exit_code' => $exitCode];
    }
}
