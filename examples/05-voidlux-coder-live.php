<?php

/**
 * Example 5: Using YOUR voidlux-coder model in a HalfBaked pipeline.
 *
 * This is the real deal — your fine-tuned PHP specialist running
 * through the HalfBaked pipeline engine. No mock data.
 *
 * Usage: php examples/05-voidlux-coder-live.php
 */

require __DIR__ . '/../vendor/autoload.php';

use HalfBaked\Pipeline\Pipeline;
use HalfBaked\Ollama\OllamaClient;

$ollama = new OllamaClient('127.0.0.1', 11434, timeout: 120);

// Verify the model is available
if (!$ollama->isAvailable('voidlux-coder')) {
    die("voidlux-coder not found in Ollama. Run: ollama list\n");
}
echo "voidlux-coder is loaded.\n\n";

// --- Pipeline: Generate a FlightPHP CRUD endpoint ---

$pipeline = Pipeline::create('php_endpoint_generator', $ollama)
    ->step('generate_route')
        ->using('voidlux-coder')
        ->system('You are a PHP code generator. Return ONLY valid JSON, no markdown.')
        ->prompt(<<<PROMPT
            Generate a FlightPHP route for a REST endpoint.

            Resource: {{resource}}
            Method: {{method}}
            Description: {{description}}

            Return the PHP code for this single route using Flight::{{method}}().
            Use RedBeanPHP (R::) for database operations.
            Include input validation and error handling.
            PROMPT)
        ->input([
            'resource' => 'string',
            'method' => 'string',
            'description' => 'string',
        ])
        ->output([
            'code' => 'string',
            'route_path' => 'string',
            'http_method' => 'string',
        ])
        ->retries(2)
        ->temperature(0.3)
    ->build();

// --- Run it ---

$endpoints = [
    [
        'resource' => 'products',
        'method' => 'post',
        'description' => 'Create a new product with name, price, and sku. Validate that price is positive and sku is unique.',
    ],
    [
        'resource' => 'users',
        'method' => 'get',
        'description' => 'List all active users with pagination. Accept page and per_page query parameters.',
    ],
    [
        'resource' => 'orders',
        'method' => 'put',
        'description' => 'Update an order status. Only allow transitions: pending->processing->shipped->delivered.',
    ],
];

foreach ($endpoints as $input) {
    echo "=== {$input['method']} /{$input['resource']} ===\n";

    $result = $pipeline->run($input);

    if ($result->success) {
        $data = $result->finalData;
        echo "Route: {$data['http_method']} {$data['route_path']}\n";
        echo "Code:\n{$data['code']}\n";

        $ms = round($result->totalDuration * 1000);
        echo "\n({$ms}ms)\n";
    } else {
        echo "FAILED at {$result->failedStep}\n";
        echo $result->summary() . "\n";
    }
    echo "\n" . str_repeat('-', 60) . "\n\n";
}

// --- Stats ---
echo "=== Pipeline Stats ===\n";
$stats = $pipeline->stats('generate_route');
echo "Runs logged: {$stats['runs']}\n";
echo "Avg time: {$stats['avg_duration_ms']}ms\n";
echo "Ready to bake: " . ($stats['ready_to_bake'] ? 'YES' : "No (need " . (3 - $stats['runs']) . " more)") . "\n";
