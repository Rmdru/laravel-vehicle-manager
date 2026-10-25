<?php

declare(strict_types=1);

return [
    'base_url' => env('OPEN_METEO_ARCHIVE_API_BASE_URL', 'https://archive-api.open-meteo.com/v1/archive/'),
    'geocoding_url' => env('OPEN_METEO_GEOCODING_API_URL', 'https://geocoding-api.open-meteo.com/v1/search'),
    'forecast_url' => env('OPEN_METEO_FORECAST_API_URL', 'https://api.open-meteo.com/v1/forecast'),
];
