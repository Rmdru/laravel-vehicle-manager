<?php

declare(strict_types=1);

return [
    'crawl_url' => env('LUXEMBOURG_FUEL_PRICES_CRAWL_URL', 'https://carbu.com/luxembourg/index.php/prixmaximum'),
    'fuel_types' => [
        'Unleaded 95 (E10)' => 'Super 95',
        'Super Plus (E5)' => 'Super 98',
        'Diesel' => 'Diesel',
        'LPG' => 'LPG',
    ],
];
