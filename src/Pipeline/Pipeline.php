<?php

declare(strict_types=1);

namespace HalfBaked\Pipeline;

use HalfBaked\Baking\Baker;
use HalfBaked\Baking\ExecutionLog;
use HalfBaked\Ollama\OllamaClient;
use HalfBaked\Runner\BakedRunner;
use HalfBaked\Runner\LlmRunner;

/**
 * The pipeline: a chain of steps that transform data.
 *
 * Each step starts "half-baked" (powered by an LLM) and can be
 * "fully baked" into deterministic PHP code once it stabilizes.
 *
 * Usage:
 *   $pipeline = Pipeline::create('shipping_quote')
 *       ->step('validate_address')
 *           ->using('voidlux-php')
 *           ->input(['address' => 'string', 'city' => 'string', 'zip' => 'string'])
 *           ->output(['valid' => 'bool', 'normalized' => 'object'])
 *           ->prompt('Validate and normalize this US address')
 *       ->step('get_rate')
 *           ->using('gorilla')
 *           ->prompt('Get UPS ground shipping rate')
 *       ->step('store_quote')
 *           ->baked(QuoteRepository::class)   // already hardened
 *       ->build();
 *
 *   $result = $pipeline->run(['address' => '123 Main St', ...]);
 */
class Pipeline
{
    /** @var Step[] */
    private array $steps = [];

    private ExecutionLog $log;
    private OllamaClient $ollama;
    private ?Baker $baker = null;
    private string $logDir;

    private function __construct(
        private string $name,
        ?OllamaClient $ollama = null,
        string $logDir = '',
    ) {
        $this->ollama = $ollama ?? new OllamaClient();
        $this->logDir = $logDir ?: sys_get_temp_dir() . '/halfbaked/' . $this->name;
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
        $this->log = new ExecutionLog($this->logDir);
    }

    /**
     * Start building a new pipeline.
     */
    public static function create(string $name, ?OllamaClient $ollama = null, string $logDir = ''): PipelineBuilder
    {
        return new PipelineBuilder($name, $ollama, $logDir);
    }

    /**
     * @internal Called by PipelineBuilder
     */
    public static function fromBuilder(string $name, array $steps, ?OllamaClient $ollama, string $logDir): self
    {
        $pipeline = new self($name, $ollama, $logDir);
        $pipeline->steps = $steps;
        return $pipeline;
    }

    /**
     * Run the pipeline with the given input.
     *
     * @param array $input Initial data for the first step
     * @return PipelineResult
     */
    public function run(array $input): PipelineResult
    {
        $data = $input;
        $stepResults = [];
        $startTime = microtime(true);

        foreach ($this->steps as $step) {
            $stepStart = microtime(true);

            try {
                if ($step->isBaked()) {
                    $runner = new BakedRunner($step);
                } else {
                    $runner = new LlmRunner($step, $this->ollama);
                }

                $result = $runner->execute($data);

                // Validate output against schema if defined
                if ($step->hasOutputSchema() && !$step->validateOutput($result)) {
                    // Retry once with error feedback
                    if (!$step->isBaked()) {
                        $result = $runner->executeWithFeedback(
                            $data,
                            'Output did not match expected schema. Expected: ' . json_encode($step->getOutputSchema())
                        );
                    }
                }

                $stepDuration = microtime(true) - $stepStart;

                $stepResult = new StepResult(
                    step: $step->getName(),
                    success: true,
                    data: $result,
                    duration: $stepDuration,
                    mode: $step->isBaked() ? 'baked' : 'llm',
                    model: $step->getModel(),
                );

                // Log for baking analysis
                $this->log->record($step->getName(), $data, $result, $stepDuration);

            } catch (\Throwable $e) {
                $stepResult = new StepResult(
                    step: $step->getName(),
                    success: false,
                    data: ['error' => $e->getMessage()],
                    duration: microtime(true) - $stepStart,
                    mode: $step->isBaked() ? 'baked' : 'llm',
                    model: $step->getModel(),
                );

                $stepResults[] = $stepResult;

                return new PipelineResult(
                    pipeline: $this->name,
                    success: false,
                    steps: $stepResults,
                    finalData: null,
                    totalDuration: microtime(true) - $startTime,
                    failedStep: $step->getName(),
                );
            }

            $stepResults[] = $stepResult;
            $data = $result;
        }

        return new PipelineResult(
            pipeline: $this->name,
            success: true,
            steps: $stepResults,
            finalData: $data,
            totalDuration: microtime(true) - $startTime,
        );
    }

    /**
     * Bake a step: analyze execution logs and generate deterministic PHP code.
     *
     * @param string $stepName  Which step to bake
     * @param string $outputDir Where to write the generated PHP class
     * @param string $namespace PHP namespace for the generated class
     * @return array{success: bool, file: string, class: string, message: string}
     */
    public function bake(
        string $stepName,
        string $outputDir,
        string $namespace = 'App\\Pipeline\\Baked',
        ?OllamaClient $reasoningModel = null,
    ): array {
        $step = $this->getStep($stepName);
        if (!$step) {
            return ['success' => false, 'file' => '', 'class' => '', 'message' => "Step '{$stepName}' not found"];
        }

        if ($step->isBaked()) {
            return ['success' => false, 'file' => '', 'class' => '', 'message' => "Step '{$stepName}' is already baked"];
        }

        $baker = $this->baker ?? new Baker($this->ollama, $reasoningModel);

        $logs = $this->log->getStepLogs($stepName);
        if (count($logs) < 3) {
            return [
                'success' => false,
                'file' => '',
                'class' => '',
                'message' => "Need at least 3 execution logs to bake (have " . count($logs) . "). Run the pipeline more times first.",
            ];
        }

        return $baker->bake($step, $logs, $outputDir, $namespace);
    }

    /**
     * Get execution statistics for a step.
     */
    public function stats(string $stepName): array
    {
        $logs = $this->log->getStepLogs($stepName);
        if (empty($logs)) {
            return ['runs' => 0];
        }

        $durations = array_column($logs, 'duration');
        return [
            'runs' => count($logs),
            'avg_duration_ms' => round(array_sum($durations) / count($durations) * 1000, 2),
            'min_duration_ms' => round(min($durations) * 1000, 2),
            'max_duration_ms' => round(max($durations) * 1000, 2),
            'ready_to_bake' => count($logs) >= 3,
        ];
    }

    /**
     * Get a step by name.
     */
    public function getStep(string $name): ?Step
    {
        foreach ($this->steps as $step) {
            if ($step->getName() === $name) {
                return $step;
            }
        }
        return null;
    }

    /**
     * List all steps with their current mode.
     */
    public function describe(): array
    {
        return array_map(fn(Step $s) => [
            'name' => $s->getName(),
            'mode' => $s->isBaked() ? 'baked' : 'llm',
            'model' => $s->getModel(),
            'has_input_schema' => $s->hasInputSchema(),
            'has_output_schema' => $s->hasOutputSchema(),
            'runs_logged' => count($this->log->getStepLogs($s->getName())),
        ], $this->steps);
    }

    /**
     * Verify a baked step against all logged execution data.
     *
     * Replays every logged input through the baked class and compares
     * outputs to what the LLM originally produced.
     *
     * @return array{accuracy: float, report: string, perfect: bool}
     */
    public function verify(string $stepName): array
    {
        $step = $this->getStep($stepName);
        if (!$step) {
            return ['accuracy' => 0, 'report' => "Step '{$stepName}' not found", 'perfect' => false];
        }

        if (!$step->isBaked()) {
            return ['accuracy' => 0, 'report' => "Step '{$stepName}' is not baked — nothing to verify", 'perfect' => false];
        }

        $className = $step->getBakedClass();
        if (!class_exists($className)) {
            return ['accuracy' => 0, 'report' => "Class '{$className}' not found", 'perfect' => false];
        }

        $instance = new $className();
        if (!$instance instanceof BakedStepInterface) {
            return ['accuracy' => 0, 'report' => "Class does not implement BakedStepInterface", 'perfect' => false];
        }

        $logs = $this->log->getStepLogs($stepName);
        if (empty($logs)) {
            return ['accuracy' => 0, 'report' => "No execution logs found for '{$stepName}'", 'perfect' => false];
        }

        $verifier = new \HalfBaked\Baking\Verifier();
        $result = $verifier->verify($instance, $logs);

        return [
            'accuracy' => $result->accuracy,
            'report' => $result->report(),
            'perfect' => $result->isPerfect(),
        ];
    }

    /**
     * Run in shadow mode: execute both LLM and baked, compare results.
     *
     * Returns the LLM result (trusted) but logs discrepancies.
     * Use this to gain confidence before fully switching to baked.
     *
     * @param string $stepName  The step to shadow-test
     * @param array  $input     Pipeline input data
     * @return array{llm: array, baked: array, match: bool, diffs: array}
     */
    public function shadow(string $stepName, array $input): array
    {
        $step = $this->getStep($stepName);
        if (!$step || !$step->getBakedClass()) {
            throw new \LogicException("Shadow mode requires a step with a baked class set");
        }

        // Run through LLM
        $llmRunner = new LlmRunner($step, $this->ollama);
        $llmResult = $llmRunner->execute($input);

        // Run through baked
        $bakedRunner = new BakedRunner($step);
        $bakedResult = $bakedRunner->execute($input);

        // Compare
        $verifier = new \HalfBaked\Baking\Verifier();
        $singleLog = [['input' => $input, 'output' => $llmResult]];
        $result = $verifier->verify(new ($step->getBakedClass()), $singleLog);

        return [
            'llm' => $llmResult,
            'baked' => $bakedResult,
            'match' => $result->isPerfect(),
            'diffs' => $result->failures,
        ];
    }

    public function getName(): string
    {
        return $this->name;
    }
}
