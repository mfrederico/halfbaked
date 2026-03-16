<?php

/**
 * Example 3: The "Baked" Versions
 *
 * After running examples 01 and 02 enough times, the Baker analyzes
 * the logged input/output pairs and generates PHP classes like these.
 *
 * These classes replace the LLM calls entirely:
 * - Zero inference cost
 * - Deterministic results
 * - Microsecond execution (vs seconds for LLM)
 * - Version-controllable, testable, reviewable
 *
 * Usage: php examples/03-baked-examples.php
 */

require __DIR__ . '/../vendor/autoload.php';

use HalfBaked\Pipeline\Pipeline;

// ============================================================
// BAKED CLASS 1: Address Normalizer
// (This is what Baker would generate from Example 01 logs)
// ============================================================

class NormalizeAddress implements \HalfBaked\Pipeline\BakedStepInterface
{
    private const STREET_ABBREVIATIONS = [
        'St'    => 'Street',
        'Ave'   => 'Avenue',
        'Blvd'  => 'Boulevard',
        'Dr'    => 'Drive',
        'Ln'    => 'Lane',
        'Ct'    => 'Court',
        'Pl'    => 'Place',
        'Rd'    => 'Road',
        'Cir'   => 'Circle',
        'Ter'   => 'Terrace',
        'Hwy'   => 'Highway',
        'Pkwy'  => 'Parkway',
    ];

    private const UNIT_ABBREVIATIONS = [
        'Apt'   => 'Apt',
        'Ste'   => 'Suite',
        'Unit'  => 'Unit',
        'Fl'    => 'Floor',
        '#'     => 'Apt',
    ];

    private const VALID_STATES = [
        'AL','AK','AZ','AR','CA','CO','CT','DE','DC','FL','GA','HI','ID','IL','IN',
        'IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH',
        'NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT',
        'VT','VA','WA','WV','WI','WY',
    ];

    public function execute(array $input): array
    {
        $street = $this->normalizeStreet($input['street'] ?? '');
        $city = $this->normalizeCity($input['city'] ?? '');
        $state = strtoupper(trim($input['state'] ?? ''));
        $zip = preg_replace('/[^0-9-]/', '', $input['zip'] ?? '');

        $valid = !empty($street)
            && !empty($city)
            && in_array($state, self::VALID_STATES, true)
            && preg_match('/^\d{5}(-\d{4})?$/', $zip);

        $zipParts = explode('-', $zip);

        return [
            'street'     => $street,
            'city'       => $city,
            'state'      => $state,
            'zip'        => $zipParts[0] ?? $zip,
            'zip_plus4'  => $zipParts[1] ?? null,
            'valid'      => $valid,
            'confidence' => $valid ? 0.95 : 0.3,
        ];
    }

    private function normalizeStreet(string $street): string
    {
        $street = trim($street);

        // Expand street type abbreviations
        foreach (self::STREET_ABBREVIATIONS as $abbr => $full) {
            $street = preg_replace('/\b' . preg_quote($abbr, '/') . '\b\.?/i', $full, $street);
        }

        // Normalize unit designators
        foreach (self::UNIT_ABBREVIATIONS as $abbr => $full) {
            $street = preg_replace('/\b' . preg_quote($abbr, '/') . '\.?\s*/i', $full . ' ', $street);
        }

        return ucwords(strtolower(trim($street)));
    }

    private function normalizeCity(string $city): string
    {
        return ucwords(strtolower(trim($city)));
    }
}

// ============================================================
// BAKED CLASS 2: Zone Classifier
// (This is what Baker would generate from Example 01 logs)
// ============================================================

class ClassifyZone implements \HalfBaked\Pipeline\BakedStepInterface
{
    // Zone lookup: first 3 digits of ZIP → zone from origin 902xx
    private const ZONE_MAP = [
        '900' => 2, '901' => 2, '902' => 2, '903' => 2, '904' => 2,  // Local CA
        '905' => 3, '906' => 3, '910' => 3, '911' => 3, '912' => 3,  // Regional CA
        '913' => 3, '920' => 3, '921' => 3, '930' => 3, '931' => 3,
        '850' => 4, '851' => 4, '852' => 4, '870' => 4, '871' => 4,  // AZ/NM
        '890' => 4, '891' => 4,                                        // NV
        '970' => 5, '971' => 5, '972' => 5, '973' => 5,               // OR/WA
        '980' => 5, '981' => 5, '982' => 5,
        '750' => 6, '751' => 6, '752' => 6, '760' => 6,               // TX
        '600' => 6, '601' => 6, '602' => 6, '606' => 6, '627' => 6,  // IL
        '100' => 7, '101' => 7, '102' => 7, '103' => 7, '104' => 7,  // NY
        '200' => 7, '201' => 7, '202' => 7, '203' => 7, '205' => 7,  // DC/VA
        '300' => 7, '301' => 7, '303' => 7,                           // GA
        '331' => 7, '332' => 7, '333' => 7,                           // FL
        '996' => 8, '997' => 8, '998' => 8, '999' => 8,               // AK
        '967' => 8, '968' => 8,                                        // HI
    ];

    private const REGION_MAP = [
        2 => 'Local',
        3 => 'Regional (West)',
        4 => 'Regional (Southwest)',
        5 => 'Cross-Country (Northwest)',
        6 => 'Cross-Country (Central)',
        7 => 'Cross-Country (East)',
        8 => 'Remote',
    ];

    private const DAYS_MAP = [2 => 1, 3 => 2, 4 => 3, 5 => 4, 6 => 5, 7 => 5, 8 => 7];

    public function execute(array $input): array
    {
        $zip = preg_replace('/[^0-9]/', '', $input['zip'] ?? '');
        $prefix = substr($zip, 0, 3);

        $zone = self::ZONE_MAP[$prefix] ?? $this->estimateZone($zip, $input['state'] ?? '');

        return [
            'zone'          => $zone,
            'region'        => self::REGION_MAP[$zone] ?? 'Unknown',
            'estimate_days' => self::DAYS_MAP[$zone] ?? 5,
        ];
    }

    private function estimateZone(string $zip, string $state): int
    {
        $westStates = ['CA', 'NV', 'AZ', 'UT', 'NM'];
        $midwestStates = ['CO', 'WY', 'MT', 'ID', 'OR', 'WA'];
        $centralStates = ['TX', 'OK', 'KS', 'NE', 'SD', 'ND', 'MN', 'IA', 'MO', 'AR', 'LA', 'IL', 'WI', 'IN', 'MI', 'OH'];
        $eastStates = ['NY', 'NJ', 'PA', 'CT', 'MA', 'RI', 'NH', 'VT', 'ME', 'DC', 'MD', 'VA', 'WV', 'DE', 'NC', 'SC', 'GA', 'FL', 'AL', 'MS', 'TN', 'KY'];

        return match (true) {
            in_array($state, $westStates, true) => 3,
            in_array($state, $midwestStates, true) => 5,
            in_array($state, $centralStates, true) => 6,
            in_array($state, $eastStates, true) => 7,
            in_array($state, ['AK', 'HI'], true) => 8,
            default => 6,
        };
    }
}

// ============================================================
// BAKED CLASS 3: Petstore Response Parser
// (This is what Baker would generate from Example 02 logs)
// ============================================================

class ParsePet implements \HalfBaked\Pipeline\BakedStepInterface
{
    private const STATUS_MAP = [
        'available' => 'in_stock',
        'pending'   => 'backorder',
        'sold'      => 'discontinued',
    ];

    public function execute(array $input): array
    {
        $response = $input['api_response'] ?? '{}';
        $data = is_string($response) ? json_decode($response, true) : $response;

        $name = $data['name'] ?? 'Unknown';

        return [
            'product_name' => $name,
            'slug'         => $this->slugify($name),
            'availability' => self::STATUS_MAP[$data['status'] ?? ''] ?? 'discontinued',
            'department'   => $data['category']['name'] ?? 'Uncategorized',
            'labels'       => array_map(
                fn($tag) => $tag['name'] ?? '',
                $data['tags'] ?? []
            ),
            'source' => 'petstore_api',
        ];
    }

    private function slugify(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        return trim($slug, '-');
    }
}

// ============================================================
// NOW: Rebuild the pipelines with baked steps
// ============================================================

echo "=== Address Validation (Fully Baked) ===\n\n";

$bakedAddressPipeline = Pipeline::create('address_validation_baked')
    ->step('normalize')
        ->baked(NormalizeAddress::class)
    ->step('classify_zone')
        ->baked(ClassifyZone::class)
    ->build();

$addresses = [
    ['street' => '123 Main St Apt 4B', 'city' => 'Los Angeles', 'state' => 'CA', 'zip' => '90012'],
    ['street' => '742 Evergreen Ter', 'city' => 'Springfield', 'state' => 'IL', 'zip' => '62704'],
    ['street' => '1600 Pennsylvania Ave NW', 'city' => 'Washington', 'state' => 'DC', 'zip' => '20500'],
    ['street' => '350 5th Ave', 'city' => 'New York', 'state' => 'NY', 'zip' => '10118'],
];

foreach ($addresses as $addr) {
    $result = $bakedAddressPipeline->run($addr);
    $norm = $result->steps[0]->data;
    $zone = $result->finalData;

    printf("  %-45s → Zone %d (%s, ~%dd) [%s]\n",
        $norm['street'] . ', ' . $norm['city'] . ' ' . $norm['state'] . ' ' . $norm['zip'],
        $zone['zone'],
        $zone['region'],
        $zone['estimate_days'],
        round($result->totalDuration * 1000, 1) . 'ms',
    );
}

echo "\n=== Petstore Parser (Fully Baked) ===\n\n";

$bakedPetstorePipeline = Pipeline::create('petstore_baked')
    ->step('parse_pet')
        ->baked(ParsePet::class)
    ->build();

$pets = [
    '{"id":1,"name":"Max","status":"available","category":{"id":1,"name":"Dogs"},"tags":[{"id":1,"name":"friendly"}]}',
    '{"id":2,"name":"Whiskers McFluffington III","status":"pending","category":{"id":2,"name":"Cats"},"tags":[{"id":3,"name":"indoor"},{"id":4,"name":"senior"}]}',
    '{"id":3,"name":"Bubbles","status":"sold","category":{"id":4,"name":"Tropical Fish"},"tags":[]}',
];

foreach ($pets as $json) {
    $result = $bakedPetstorePipeline->run(['api_response' => $json]);
    $pet = $result->finalData;

    printf("  %-30s [%-12s] dept=%-15s slug=%-30s labels=%s [%s]\n",
        $pet['product_name'],
        $pet['availability'],
        $pet['department'],
        $pet['slug'],
        implode(',', $pet['labels']) ?: '(none)',
        round($result->totalDuration * 1000, 2) . 'ms',
    );
}

echo "\n=== Performance Comparison ===\n";
echo "  LLM (dough): ~2-5 seconds per step (depends on model/hardware)\n";
echo "  PHP (baked): <0.1ms per step — that's 20,000-50,000x faster\n";
echo "\n  The whole point: prototype with LLM, ship with PHP.\n";
