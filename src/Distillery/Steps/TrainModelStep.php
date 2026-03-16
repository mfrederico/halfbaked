<?php

declare(strict_types=1);

namespace HalfBaked\Distillery\Steps;

use HalfBaked\Pipeline\BakedStepInterface;

class TrainModelStep implements BakedStepInterface
{
    public function execute(array $input): array
    {
        $datasetFile = $input['dataset_file'] ?? throw new \RuntimeException('Missing dataset_file');
        $profileClass = $input['profile_class'] ?? throw new \RuntimeException('Missing profile_class');
        $workDir = $input['work_dir'] ?? throw new \RuntimeException('Missing work_dir');
        $language = $input['language'] ?? 'unknown';

        /** @var \HalfBaked\Distillery\LanguageProfile $profile */
        $profile = new $profileClass();

        $baseModel = $input['base_model'] ?? $profile->getBaseModel();

        // Auto-detect large models and reduce memory settings
        $isLargeModel = preg_match('/\b(9[bB]|14[bB])\b/', $baseModel)
            || preg_match('/Qwen3\.5/', $baseModel);

        $epochs = $input['epochs'] ?? 3;
        $batchSize = $input['batch_size'] ?? ($isLargeModel ? 1 : 2);
        $lr = $input['lr'] ?? 2e-4;
        $loraRank = $input['lora_rank'] ?? ($isLargeModel ? 16 : 64);
        $maxSeqLength = $input['max_seq_length'] ?? ($isLargeModel ? 512 : 4096);

        $outputDir = "{$workDir}/output";
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $halfbakedRoot = dirname(__DIR__, 3);
        $script = "{$halfbakedRoot}/training/train.py";

        if (!file_exists($script)) {
            throw new \RuntimeException("Train script not found: {$script}");
        }

        $python = \HalfBaked\Distillery\LanguageProfile::pythonBin();
        // Help PyTorch manage VRAM fragmentation; disable torch.compile for large models
        // (torch inductor allocates extra VRAM that causes OOM on 24GB GPUs)
        $envPrefix = 'PYTORCH_CUDA_ALLOC_CONF=expandable_segments:True ';
        if ($isLargeModel) {
            $envPrefix .= 'TORCHDYNAMO_DISABLE=1 ';
        }
        $cmd = $envPrefix . sprintf(
            '%s %s --dataset %s --output-dir %s --model-id %s --epochs %d --batch-size %d --lr %s --lora-rank %d --max-seq-length %d',
            escapeshellarg($python),
            escapeshellarg($script),
            escapeshellarg($datasetFile),
            escapeshellarg($outputDir),
            escapeshellarg($baseModel),
            $epochs,
            $batchSize,
            $lr,
            $loraRank,
            $maxSeqLength
        );
        if ($isLargeModel) {
            $cmd .= ' --no-packing --gradient-accumulation-steps 8';
        }

        $logFile = "{$workDir}/train.log";
        $result = $this->runProcessWithLogging($cmd, $workDir, $logFile);

        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException("Training failed (exit {$result['exit_code']}). See {$logFile}");
        }

        // Parse training loss from output
        $trainingLoss = 0.0;
        if (preg_match('/TRAINING_LOSS:\s*([\d.]+)/', $result['stdout'], $m)) {
            $trainingLoss = (float) $m[1];
        } elseif (preg_match('/Loss:\s*([\d.]+)/', $result['stdout'], $m)) {
            $trainingLoss = (float) $m[1];
        }

        $mergedModelPath = "{$outputDir}/merged_model";
        if (!is_dir($mergedModelPath)) {
            throw new \RuntimeException("Merged model not found at: {$mergedModelPath}");
        }

        return array_merge($input, [
            'merged_model_path' => $mergedModelPath,
            'training_loss' => $trainingLoss,
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
