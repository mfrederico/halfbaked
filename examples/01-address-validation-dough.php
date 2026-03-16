<?php

/**
 * Example 1: Address Validation — The "Dough" Version
 *
 * This pipeline uses an LLM to validate and normalize US shipping addresses.
 * Run it a few times with real data, then bake it into deterministic PHP.
 *
 * Usage: php examples/01-address-validation-dough.php
 */

require __DIR__ . '/../vendor/autoload.php';

use HalfBaked\Pipeline\Pipeline;

// --- Build the pipeline (dough = LLM-powered) ---

$pipeline = Pipeline::create('address_validation')
    ->step('normalize')
        ->using('qwen3:8b')
        ->system('You are a US address normalization engine.')
        ->prompt(<<<PROMPT
            Normalize this US shipping address into standard USPS format.

            Street: {{street}}
            City: {{city}}
            State: {{state}}
            Zip: {{zip}}

            Normalize abbreviations (St→Street, Ave→Avenue, Apt→Apt).
            Validate the state code. Infer the ZIP+4 if possible.
            PROMPT)
        ->input([
            'street' => 'string',
            'city'   => 'string',
            'state'  => 'string',
            'zip'    => 'string',
        ])
        ->output([
            'street'     => 'string',
            'city'       => 'string',
            'state'      => 'string',
            'zip'        => 'string',
            'zip_plus4'  => ['type' => 'string', 'required' => false],
            'valid'      => 'boolean',
            'confidence' => 'number',
        ])
    ->step('classify_zone')
        ->using('qwen3:8b')
        ->prompt(<<<PROMPT
            Given this normalized address, determine the UPS shipping zone
            relative to origin ZIP 90210.

            Destination ZIP: {{zip}}
            Destination State: {{state}}

            Zones: 2 (local), 3-4 (regional), 5-6 (cross-country), 7-8 (remote).
            PROMPT)
        ->output([
            'zone'     => 'integer',
            'region'   => 'string',
            'estimate_days' => 'integer',
        ])
    ->build();

// --- Run it with test addresses ---

$testAddresses = [
    ['street' => '123 Main St Apt 4B', 'city' => 'Los Angeles', 'state' => 'CA', 'zip' => '90012'],
    ['street' => '742 Evergreen Ter', 'city' => 'Springfield', 'state' => 'IL', 'zip' => '62704'],
    ['street' => '1600 Pennsylvania Ave NW', 'city' => 'Washington', 'state' => 'DC', 'zip' => '20500'],
    ['street' => '350 5th Ave', 'city' => 'New York', 'state' => 'NY', 'zip' => '10118'],
];

foreach ($testAddresses as $address) {
    echo "--- Input: {$address['street']}, {$address['city']} {$address['state']} ---\n";

    $result = $pipeline->run($address);

    echo $result->summary() . "\n";

    if ($result->success) {
        echo "  Normalized: {$result->finalData['zone']} (Zone {$result->finalData['zone']}, ~{$result->finalData['estimate_days']} days)\n";
    }
    echo "\n";
}

// --- Check baking readiness ---

echo "=== Baking Status ===\n";
$stats = $pipeline->stats('normalize');
echo "  normalize: {$stats['runs']} runs, avg {$stats['avg_duration_ms']}ms";
echo $stats['ready_to_bake'] ? " ✓ READY TO BAKE\n" : " (need " . (3 - $stats['runs']) . " more runs)\n";

$stats = $pipeline->stats('classify_zone');
echo "  classify_zone: {$stats['runs']} runs, avg {$stats['avg_duration_ms']}ms";
echo $stats['ready_to_bake'] ? " ✓ READY TO BAKE\n" : " (need " . (3 - $stats['runs']) . " more runs)\n";

// --- When ready, bake it! ---
// Uncomment after running 3+ times:
//
// $result = $pipeline->bake('normalize', __DIR__ . '/Baked');
// echo $result['message'] . "\n";
// echo "Generated: {$result['file']}\n";
//
// Then rebuild the pipeline with the baked step:
// ->step('normalize')
//     ->baked(\App\Pipeline\Baked\Normalize::class)
