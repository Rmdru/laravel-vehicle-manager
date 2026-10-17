<?php

declare(strict_types=1);

return [
    'crawl_url' => env('ANWB_FUEL_PRICES_CRAWL_URL', 'https://www.anwb.nl/vakantie/reisvoorbereiding/brandstofprijzen-europa'),
    // Only what the other sources lack: by country, our fuel type => the fuel type code of the ANWB.
    'countries' => [
        'netherlands' => [
            'iso3_code' => 'NLD',
            'fuel_types' => [
                'Super Plus (E5)' => 'EURO98',
            ],
        ],
        // Tankerkönig only has E10, E5 and diesel.
        'germany' => [
            'iso3_code' => 'DEU',
            'fuel_types' => [
                'Super Plus (E5)' => 'EURO98',
                'LPG' => 'AUTOGAS',
            ],
        ],
    ],
];
