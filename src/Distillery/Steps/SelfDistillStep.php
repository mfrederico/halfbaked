<?php

declare(strict_types=1);

namespace HalfBaked\Distillery\Steps;

use HalfBaked\Pipeline\BakedStepInterface;

/**
 * Simple Self-Distillation (SSD) step.
 *
 * Runs the trained model at elevated temperature to generate new training data
 * from its own outputs, then re-trains via SFT. Based on:
 * "Embarrassingly Simple Self-Distillation Improves Code Generation"
 * (Zhang et al., 2025) — https://arxiv.org/abs/2604.01193
 */
class SelfDistillStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        // Skip if SSD not enabled (step is always in the pipeline but only runs when requested)
        if (empty($input['ssd_enabled'])) {
            echo "SSD not enabled — skipping self-distillation (use --ssd to enable)\n";
            return $input;
        }

        $mergedModelPath = $input['merged_model_path'] ?? throw new \RuntimeException('Missing merged_model_path');
        $datasetFile = $input['dataset_file'] ?? throw new \RuntimeException('Missing dataset_file');
        $workDir = $input['work_dir'] ?? throw new \RuntimeException('Missing work_dir');

        // SSD config (defaults can be overridden via $input from CLI flags)
        $ssdTemperature = $input['ssd_temperature'] ?? 1.5;
        $ssdRounds = $input['ssd_rounds'] ?? 1;
        $ssdMaxPrompts = $input['ssd_max_prompts'] ?? 500;
        $ssdLr = $input['ssd_lr'] ?? 5e-5;
        $batchSize = $input['batch_size'] ?? 2;
        $maxSeqLength = $input['max_seq_length'] ?? 4096;
        $loraRank = $input['lora_rank'] ?? 64;

        $isLargeModel = preg_match('/\b(9[bB]|14[bB])\b/', $input['base_model'] ?? '')
            || preg_match('/Qwen3\.5/', $input['base_model'] ?? '');

        if ($isLargeModel) {
            $batchSize = 1;
            $loraRank = min($loraRank, 16);
            $maxSeqLength = min($maxSeqLength, 512);
        }

        $outputDir = "{$workDir}/output";
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $halfbakedRoot = dirname(__DIR__, 3);
        $script = "{$halfbakedRoot}/training/ssd.py";

        if (!file_exists($script)) {
            throw new \RuntimeException("SSD script not found: {$script}");
        }

        $python = \HalfBaked\Distillery\LanguageProfile::pythonBin();
        $envPrefix = 'PYTORCH_CUDA_ALLOC_CONF=expandable_segments:True ';
        if ($isLargeModel) {
            $envPrefix .= 'TORCHDYNAMO_DISABLE=1 ';
        }

        $cmd = $envPrefix . sprintf(
            '%s %s --model %s --dataset %s --output-dir %s --temperature %s --rounds %d --max-prompts %d --lr %s --batch-size %d --max-seq-length %d --lora-rank %d --gradient-accumulation-steps %d',
            escapeshellarg($python),
            escapeshellarg($script),
            escapeshellarg($mergedModelPath),
            escapeshellarg($datasetFile),
            escapeshellarg($outputDir),
            $ssdTemperature,
            $ssdRounds,
            $ssdMaxPrompts,
            $ssdLr,
            $batchSize,
            $maxSeqLength,
            $loraRank,
            $isLargeModel ? 8 : 4
        );

        $logFile = "{$workDir}/ssd.log";
        $result = $this->runProcessWithLogging($cmd, $workDir, $logFile);

        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException("Self-distillation failed (exit {$result['exit_code']}). See {$logFile}");
        }

        // Parse SSD metrics from output
        $ssdLoss = 0.0;
        if (preg_match('/SSD_LOSS:\s*([\d.]+)/', $result['stdout'], $m)) {
            $ssdLoss = (float) $m[1];
        }
        $ssdExamples = 0;
        if (preg_match('/SSD_EXAMPLES:\s*(\d+)/', $result['stdout'], $m)) {
            $ssdExamples = (int) $m[1];
        }

        // SSD overwrites merged_model in the output dir
        if (!is_dir($mergedModelPath)) {
            throw new \RuntimeException("SSD merged model not found at: {$mergedModelPath}");
        }

        return array_merge($input, [
            'merged_model_path' => $mergedModelPath,
            'ssd_loss' => $ssdLoss,
            'ssd_examples' => $ssdExamples,
            'ssd_temperature' => $ssdTemperature,
            'ssd_rounds' => $ssdRounds,
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
