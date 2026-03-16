<?php

/**
 * Example 4: Shipping Rate Calculator — Mixed Pipeline
 *
 * Real-world scenario: a pipeline where some steps are already baked
 * and others are still dough. This is the typical state during development.
 *
 * Step 1: normalize_address  → BAKED (proven logic, no LLM needed)
 * Step 2: lookup_rate        → BAKED (just an API call + math, deterministic)
 * Step 3: apply_discounts    → DOUGH (business rules still evolving, LLM prototyping)
 * Step 4: format_quote       → DOUGH (output format still being designed)
 *
 * Over time, steps 3 and 4 stabilize and get baked too.
 *
 * Usage: php examples/04-shipping-rate-mixed.php
 */

require __DIR__ . '/../vendor/autoload.php';

use HalfBaked\Pipeline\Pipeline;
use HalfBaked\Pipeline\BakedStepInterface;

// ============================================================
// BAKED: Address normalization (already proven in Example 03)
// ============================================================

class ShippingAddressNormalizer implements BakedStepInterface
{
    public function execute(array $input): array
    {
        $state = strtoupper(trim($input['state'] ?? ''));
        $zip = preg_replace('/[^0-9]/', '', $input['zip'] ?? '');

        return [
            'street'   => ucwords(strtolower(trim($input['street'] ?? ''))),
            'city'     => ucwords(strtolower(trim($input['city'] ?? ''))),
            'state'    => $state,
            'zip'      => $zip,
            'valid'    => strlen($zip) === 5 && strlen($state) === 2,
            // Pass through order details for downstream steps
            'weight_lbs'   => (float) ($input['weight_lbs'] ?? 0),
            'package_type' => $input['package_type'] ?? 'standard',
            'service'      => $input['service'] ?? 'ground',
        ];
    }
}

// ============================================================
// BAKED: Rate lookup (deterministic table + math)
// ============================================================

class RateLookup implements BakedStepInterface
{
    // Simplified rate table: zone → base rate per lb
    private const RATES = [
        'ground' => [
            2 => 0.45, 3 => 0.52, 4 => 0.61,
            5 => 0.75, 6 => 0.89, 7 => 1.05, 8 => 1.35,
        ],
        '2day' => [
            2 => 1.20, 3 => 1.45, 4 => 1.80,
            5 => 2.10, 6 => 2.50, 7 => 2.95, 8 => 3.80,
        ],
        'overnight' => [
            2 => 3.50, 3 => 4.20, 4 => 5.10,
            5 => 6.00, 6 => 7.25, 7 => 8.50, 8 => 12.00,
        ],
    ];

    private const SURCHARGES = [
        'oversized'   => 15.00,
        'hazmat'      => 25.00,
        'residential' => 4.50,
    ];

    private const MINIMUMS = ['ground' => 8.99, '2day' => 15.99, 'overnight' => 29.99];

    public function execute(array $input): array
    {
        $service = $input['service'] ?? 'ground';
        $weight = max(1.0, (float) ($input['weight_lbs'] ?? 1));
        $zone = $this->zipToZone($input['zip'] ?? '90210');
        $packageType = $input['package_type'] ?? 'standard';

        $ratePerLb = self::RATES[$service][$zone] ?? self::RATES['ground'][6];
        $baseRate = $ratePerLb * $weight;

        // Apply surcharges
        $surcharge = 0;
        $surchargeReasons = [];
        if ($packageType === 'oversized') {
            $surcharge += self::SURCHARGES['oversized'];
            $surchargeReasons[] = 'oversized';
        }

        // Enforce minimum
        $minimum = self::MINIMUMS[$service] ?? 8.99;
        $total = max($minimum, $baseRate + $surcharge);

        return [
            'base_rate'    => round($baseRate, 2),
            'surcharge'    => round($surcharge, 2),
            'total'        => round($total, 2),
            'zone'         => $zone,
            'service'      => $service,
            'weight_lbs'   => $weight,
            'surcharge_reasons' => $surchargeReasons,
            // Pass through for downstream
            'city'  => $input['city'] ?? '',
            'state' => $input['state'] ?? '',
        ];
    }

    private function zipToZone(string $zip): int
    {
        $prefix = (int) substr($zip, 0, 3);
        return match (true) {
            $prefix >= 900 && $prefix <= 935 => 2,
            $prefix >= 850 && $prefix <= 899 => 4,
            $prefix >= 936 && $prefix <= 999 => 5,
            $prefix >= 700 && $prefix <= 799 => 6,
            $prefix >= 100 && $prefix <= 399 => 7,
            $prefix >= 400 && $prefix <= 699 => 6,
            default => 6,
        };
    }
}

// ============================================================
// BUILD THE MIXED PIPELINE
// ============================================================

$pipeline = Pipeline::create('shipping_quote')

    // Step 1: BAKED — address normalization
    ->step('normalize_address')
        ->baked(ShippingAddressNormalizer::class)

    // Step 2: BAKED — rate lookup
    ->step('lookup_rate')
        ->baked(RateLookup::class)

    // Step 3: DOUGH — discount logic still evolving
    ->step('apply_discounts')
        ->using('qwen3:8b')
        ->system('You are a shipping discount engine for an e-commerce platform.')
        ->prompt(<<<PROMPT
            Apply any applicable discounts to this shipping quote.

            Current quote:
            - Service: {{service}}
            - Base rate: ${{base_rate}}
            - Total before discounts: ${{total}}
            - Zone: {{zone}}
            - Destination: {{city}}, {{state}}

            Discount rules:
            - Orders over $50 base rate get 10% off
            - Zone 2 (local) gets free shipping if under $15
            - If the total exceeds $100, cap at $89.99
            - Holiday season (Nov-Dec) adds $2 handling fee
            PROMPT)
        ->output([
            'original_total' => 'number',
            'discount_amount' => 'number',
            'final_total' => 'number',
            'discounts_applied' => 'array',
            'service' => 'string',
        ])

    // Step 4: DOUGH — quote format still being designed
    ->step('format_quote')
        ->using('qwen3:8b')
        ->prompt(<<<PROMPT
            Format this shipping quote for display to the customer.

            Service: {{service}}
            Original: ${{original_total}}
            Discounts: ${{discount_amount}}
            Final: ${{final_total}}
            Discounts applied: {{discounts_applied}}

            Generate a short, friendly one-line summary and a detailed breakdown.
            PROMPT)
        ->output([
            'summary'   => 'string',
            'breakdown' => 'array',
            'final_price' => 'number',
        ])

    ->build();

// --- Run it ---

echo "=== Mixed Pipeline: Shipping Quote Calculator ===\n\n";

$orders = [
    [
        'street' => '456 Oak Ave', 'city' => 'Santa Monica', 'state' => 'CA', 'zip' => '90401',
        'weight_lbs' => 5, 'package_type' => 'standard', 'service' => 'ground',
    ],
    [
        'street' => '789 Broadway', 'city' => 'New York', 'state' => 'NY', 'zip' => '10003',
        'weight_lbs' => 25, 'package_type' => 'oversized', 'service' => '2day',
    ],
    [
        'street' => '100 Main St', 'city' => 'Anchorage', 'state' => 'AK', 'zip' => '99501',
        'weight_lbs' => 3, 'package_type' => 'standard', 'service' => 'overnight',
    ],
];

foreach ($orders as $order) {
    echo "Order: {$order['weight_lbs']}lbs {$order['service']} → {$order['city']}, {$order['state']}\n";

    $result = $pipeline->run($order);
    echo $result->summary() . "\n\n";
}

echo "--- Step Modes ---\n";
foreach ($pipeline->describe() as $step) {
    $icon = $step['mode'] === 'baked' ? 'BAKED' : 'DOUGH';
    echo "  [{$icon}] {$step['name']}";
    if ($step['model']) {
        echo " (model: {$step['model']})";
    }
    echo "\n";
}

echo "\n--- Next Steps ---\n";
echo "  1. Run this pipeline with real orders until discount patterns stabilize\n";
echo "  2. Bake 'apply_discounts': \$pipeline->bake('apply_discounts', './src/Baked')\n";
echo "  3. Bake 'format_quote': \$pipeline->bake('format_quote', './src/Baked')\n";
echo "  4. Rebuild pipeline with all baked steps → zero LLM dependency\n";
echo "  5. Ship it.\n";
