<?php

/**
 * Example 2: OpenAPI Response Parsing — The "Dough" Version
 *
 * Prototyping an API integration by having the LLM parse and transform
 * API responses into your app's internal format. Once it stabilizes,
 * bake it into a deterministic parser.
 *
 * Scenario: You're integrating the Petstore API and need to transform
 * its responses into your internal inventory format.
 *
 * Usage: php examples/02-openapi-petstore-dough.php
 */

require __DIR__ . '/../vendor/autoload.php';

use HalfBaked\Pipeline\Pipeline;

// --- Build the pipeline ---

$pipeline = Pipeline::create('petstore_integration')
    ->step('parse_pet')
        ->using('qwen3:8b')
        ->system('You transform Petstore API responses into our internal inventory format.')
        ->prompt(<<<PROMPT
            Transform this Petstore API response into our internal product format.

            API Response:
            {{api_response}}

            Rules:
            - Map "name" to "product_name"
            - Map "status" to "availability" (available→in_stock, pending→backorder, sold→discontinued)
            - Map "category.name" to "department"
            - Map "tags[].name" to "labels" array
            - Generate a URL-safe "slug" from the name
            - Set "source" to "petstore_api"
            PROMPT)
        ->output([
            'product_name'  => 'string',
            'slug'          => 'string',
            'availability'  => ['type' => 'string', 'enum' => ['in_stock', 'backorder', 'discontinued']],
            'department'    => 'string',
            'labels'        => 'array',
            'source'        => 'string',
        ])
    ->step('validate_inventory')
        ->using('qwen3:8b')
        ->prompt(<<<PROMPT
            Validate this inventory item for our system.

            Item:
            {{product_name}} ({{availability}})
            Department: {{department}}
            Labels: {{labels}}

            Check:
            1. product_name is not empty and under 100 chars
            2. slug is URL-safe (lowercase, hyphens, no special chars)
            3. department exists in our known departments: Dogs, Cats, Birds, Fish, Reptiles, Small Animals, Accessories
            4. If department is unknown, suggest the closest match
            PROMPT)
        ->output([
            'valid'              => 'boolean',
            'corrected_department' => 'string',
            'issues'             => 'array',
        ])
    ->build();

// --- Simulate API responses ---

$apiResponses = [
    [
        'id' => 1,
        'name' => 'Max',
        'status' => 'available',
        'category' => ['id' => 1, 'name' => 'Dogs'],
        'tags' => [['id' => 1, 'name' => 'friendly'], ['id' => 2, 'name' => 'trained']],
    ],
    [
        'id' => 2,
        'name' => 'Whiskers McFluffington III',
        'status' => 'pending',
        'category' => ['id' => 2, 'name' => 'Cats'],
        'tags' => [['id' => 3, 'name' => 'indoor'], ['id' => 4, 'name' => 'senior']],
    ],
    [
        'id' => 3,
        'name' => 'Bubbles',
        'status' => 'sold',
        'category' => ['id' => 4, 'name' => 'Tropical Fish'],  // Unmapped department
        'tags' => [],
    ],
    [
        'id' => 4,
        'name' => 'Slither',
        'status' => 'available',
        'category' => ['id' => 5, 'name' => 'Snakes & Lizards'],  // Unmapped department
        'tags' => [['id' => 5, 'name' => 'exotic']],
    ],
];

foreach ($apiResponses as $response) {
    echo "--- Pet #{$response['id']}: {$response['name']} ({$response['status']}) ---\n";

    $result = $pipeline->run(['api_response' => json_encode($response)]);

    if ($result->success) {
        // Show the parsed result
        $parsed = $result->steps[0]->data;
        echo "  → {$parsed['product_name']} [{$parsed['availability']}]\n";
        echo "    slug: {$parsed['slug']}, dept: {$parsed['department']}\n";
        echo "    labels: " . implode(', ', $parsed['labels'] ?: ['none']) . "\n";

        // Show validation
        $validation = $result->finalData;
        echo "    valid: " . ($validation['valid'] ? 'yes' : 'NO') . "\n";
        if (!empty($validation['issues'])) {
            foreach ($validation['issues'] as $issue) {
                echo "    ⚠ {$issue}\n";
            }
        }
        if ($validation['corrected_department'] !== ($parsed['department'] ?? '')) {
            echo "    dept corrected → {$validation['corrected_department']}\n";
        }
    } else {
        echo "  FAILED at {$result->failedStep}\n";
    }
    echo "\n";
}

echo $pipeline->stats('parse_pet')['runs'] . " runs logged for parse_pet\n";
echo $pipeline->stats('validate_inventory')['runs'] . " runs logged for validate_inventory\n";
