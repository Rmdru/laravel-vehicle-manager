<?php

declare(strict_types=1);

return [
    'base_url' => env('TANKERKOENIG_API_BASE_URL', 'https://creativecommons.tankerkoenig.de/api/v4/'),
    // The prices at the gas stations around a point, for the trip planner: this needs a (free) key.
    'list_url' => env('TANKERKOENIG_LIST_URL', 'https://creativecommons.tankerkoenig.de/json/list.php'),
    'api_key' => env('TANKERKOENIG_API_KEY'),
    'fuel_types' => [
        'Unleaded 95 (E10)' => 'E10',
        'Unleaded 95 (E5)' => 'E5',
        'Diesel' => 'Diesel',
    ],
];
