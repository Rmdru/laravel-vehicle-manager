<?php

declare(strict_types=1);

return [
    // The time zone the departure and arrival times are entered and shown in.
    'timezone' => env('TRIP_PLANNER_TIMEZONE', 'Europe/Amsterdam'),

    // TomTom: routing and geocoding, within the free tier (2,500 requests a day).
    'tomtom' => [
        'base_url' => env('TOMTOM_API_BASE_URL', 'https://api.tomtom.com/'),
        'key' => env('TOMTOM_API_KEY'),
        'language' => [
            'nl' => 'nl-NL',
            'en' => 'en-GB',
            'de' => 'de-DE',
            'fr' => 'fr-FR',
            'es' => 'es-ES',
            'it' => 'it-IT',
        ],
    ],

    // How many routes are planned next to the fastest one.
    'alternatives' => 2,

    // The public Overpass servers. A request waits half a minute or more in
    // their queue, so the requests are spread over them and sent at once.
    'overpass' => [
        'servers' => [
            'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
            'https://overpass-api.de/api/interpreter',
            'https://overpass.private.coffee/api/interpreter',
            'https://overpass.kumi.systems/api/interpreter',
        ],
        // A request that takes longer is tried again at another server.
        'timeout' => 120,
        // The tiles are fetched in blocks of this many tiles square, one request each.
        'block_size' => 4,
    ],

    'map' => [
        // The colors of the Tailwind classes of the road types of the
        // countries (config/countries.php), for the road numbers on the map.
        'colors' => [
            'bg-red-500' => '#ef4444',
            'bg-yellow-500' => '#eab308',
            'bg-green-600' => '#16a34a',
            'bg-white' => '#ffffff',
            'bg-blue-500' => '#3b82f6',
            'bg-orange-500' => '#f97316',
            'text-white' => '#ffffff',
            'text-black' => '#000000',
            'text-yellow-300' => '#fde047',
            'border-black' => '#000000',
            'border-gray-400' => '#9ca3af',
        ],
    ],

    // The places along a route are cached per tile of this many degrees.
    'tile_size' => 0.25,
    'tile_ttl_days' => 30,

    // How far from the route a place may be, in meters: a service area lies
    // next to the motorway, a gas station along another road may be a little
    // further away.
    'corridor' => [
        'service_area' => 350,
        'gas_station' => 150,
        // Shown on the map, but not planned as a stop.
        'map' => 1000,
        // The places are fetched this far from the route: gas stations near an
        // exit can be cheaper than the ones along the motorway.
        'fetch' => 3000,
    ],

    // Every night the places in these countries are fetched ahead of time, so
    // that a trip through them is planned without waiting for Overpass. The
    // bounding boxes are south, west, north, east.
    'warm' => [
        'countries' => [
            'NL' => [50.75, 3.35, 53.55, 7.25],
            'BE' => [49.5, 2.5, 51.5, 6.4],
            'LU' => [49.45, 5.7, 50.2, 6.55],
            'FR' => [42.3, -4.8, 51.1, 8.25],
            'DE' => [47.25, 5.85, 55.1, 15.05],
            'GB' => [49.9, -6.4, 58.7, 1.8],
            'IT' => [36.6, 6.6, 47.1, 18.55],
            'ES' => [36.0, -9.35, 43.8, 3.35],
            'PL' => [49.0, 14.1, 54.85, 24.15],
            'CH' => [45.8, 5.95, 47.85, 10.5],
            'AT' => [46.35, 9.5, 49.05, 17.2],
        ],
        // At most this many blocks a night: all the countries together are
        // about 600 blocks, so every block is fetched again within a month.
        'blocks' => (int) env('TRIP_PLANNER_WARM_BLOCKS', 120),
        // Tiles are fetched again this many days before they are out of date.
        'refresh_before_days' => 5,
    ],

    // The amenities that belong to a service area: within this many meters of it.
    'service_area_radius' => 250,

    'stops' => [
        // A break with at least a coffee, every 105 to 180 minutes of driving.
        'break_after' => 105,
        'break_before' => 180,
        'break_minutes' => 30,
        // A meal at a restaurant at about breakfast, lunch and dinner time
        // (in the time zone above), within this many minutes of it. A meal
        // just after departure is eaten before leaving.
        'meals' => ['08:00', '13:00', '18:00'],
        'meal_window' => 60,
        'meal_after_departure' => 30,
        'meal_minutes' => 45,
        // A toilet, every 2 to 3 hours.
        'toilet_every' => 180,
        'toilet_minutes' => 10,
        'fuel_minutes' => 10,
        // The part of the tank that is left when arriving at a gas station.
        'fuel_reserve' => 0.15,
        // A stay at a destination along the way of at least this many minutes
        // counts as a break, or as a meal.
        'stay_counts_as_break' => 15,
        'stay_counts_as_meal' => 45,
        'stay_counts_as_night' => 360,
        // A night in a hotel after at most this many minutes of travel,
        // breaks included, and how long the night takes.
        'hotel_every' => 600,
        'night_minutes' => 600,
        // The longest detour to a hotel, in minutes.
        'hotel_max_detour_minutes' => 15,
        // How far a hotel may be from a road that is not a motorway, in meters.
        'hotel_off_road' => 1000,
    ],

    'prices' => [
        'france_url' => 'https://data.economie.gouv.fr/api/explore/v2.1/catalog/datasets/prix-des-carburants-en-france-flux-instantane-v2/records',
        'austria_url' => 'https://api.e-control.at/sprit/1.0/search/gas-stations/by-address',
        'spain_url' => 'https://sedeaplicaciones.minetur.gob.es/ServiciosRESTCarburantes/PreciosCarburantes/EstacionesTerrestres/',
        // The United Kingdom: the open data the retailers publish at the
        // request of the CMA; prices in pence, turned into euros at the rate
        // of the European Central Bank.
        'uk_feeds' => [
            'https://storelocator.asda.com/fuel_prices_data.json',
            'https://fuelprices.esso.co.uk/latestdata.json',
            'https://www.morrisons.com/fuel-prices/fuel.json',
            'https://moto-way.com/fuel-price/fuel_prices.json',
            'https://fuel.motorfuelgroup.com/fuel_prices_data.json',
            'https://www.rontec-servicestations.co.uk/fuel-prices/data/fuel_prices_data.json',
            'https://www.sgnretail.uk/files/data/SGN_daily_fuel_prices.json',
            'https://applegreenstores.com/fuel-prices/data.json',
        ],
        'ecb_rates_url' => 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml',
        // The prices the ANWB shows on its website, for the countries without
        // open data, and after the open data elsewhere. Not open data: its
        // terms forbid reuse without the written approval of the ANWB.
        'anwb' => [
            'enabled' => (bool) env('TRIP_PLANNER_ANWB_PRICES', false),
            'url' => 'https://api.anwb.nl/routing/points-of-interest/v3/all',
            'countries' => ['NL', 'BE', 'LU', 'CH', 'PL', 'IT', 'GB', 'DE', 'FR', 'AT', 'ES'],
        ],
        // How long the prices around a point are kept, in minutes.
        'cache_minutes' => 30,
        // Without the price of the gas station itself, the average price of the
        // country is used: along the motorway with this much on top, per liter.
        'motorway_surcharge' => [
            'NL' => 0.12, 'BE' => 0.05, 'LU' => 0.0, 'DE' => 0.15, 'FR' => 0.12, 'GB' => 0.15,
            'IT' => 0.08, 'ES' => 0.06, 'PL' => 0.10, 'CH' => 0.05, 'AT' => 0.08,
        ],
        'default_motorway_surcharge' => 0.08,
        // What an hour of the time of the driver is worth, in euros: a separate
        // stop for fuel is only worth it when it saves more than it costs.
        'time_value' => 15,
        // A detour to a gas station off the road is only made when fuel along
        // the road costs at least this much more per liter, and takes at most
        // this many minutes.
        'detour_min_saving' => 0.10,
        'max_detour_minutes' => 15,
        // A motorway exit this close to the route belongs to it, in meters.
        'exit_on_route' => 300,
        // How long it takes to leave the motorway and get back onto it, in seconds.
        'exit_seconds' => 120,
    ],

    // Electric vehicles: a stop to charge when the battery would get below the
    // reserve, charging up to the part where charging slows down.
    'ev' => [
        'reserve' => 0.10,
        'charge_to' => 0.80,
        // In kWh/100 km, when the vehicle has no charging sessions yet.
        'default_consumption' => 19.0,
        // The power of a fast charger whose power is unknown, and the most a
        // car takes, in kW; on average it charges at this part of it.
        'default_power' => 50,
        'max_power' => 120,
        'power_factor' => 0.7,
        // Minutes to plug in, pay and get going again.
        'overhead_minutes' => 5,
    ],

    // The consumption in l/100 km when the vehicle has no refuelings yet.
    'default_consumption' => [
        'diesel' => 6.0,
        'gasoline' => 7.0,
    ],
];
