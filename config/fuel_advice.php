<?php

declare(strict_types=1);

return [
    // The free tier of the Gemini API (no billing) is enough: one advice per
    // vehicle per day, and the odd one asked for from the dashboard.
    'provider' => env('FUEL_ADVICE_PROVIDER', 'gemini'),
    'model' => env('FUEL_ADVICE_MODEL', 'gemini-3.1-flash-lite'),

    // Grounding with Google Search: news on excise changes, oil prices and
    // holidays. Off by default, as the free tier has no quota for it.
    'search' => (bool) env('FUEL_ADVICE_SEARCH', false),

    // Pages the model reads for what the prices do not show yet (URL context,
    // which the free tier does allow): announced official prices and news.
    'sources' => [
        'https://economie.fgov.be/nl/themas/energie/energieprijzen/maximumprijzen/officiele-prijzen-van',
        'https://carbu.com/luxembourg/index.php/prixmaximum',
        'https://www.unitedconsumers.com/tanken/',
        'https://www.anwb.nl/auto/nieuws',
        'https://www.clever-tanken.de/',
        'https://tradingeconomics.com/commodity/brent-crude-oil',
    ],

    // Seconds to wait for an answer.
    'timeout' => 90,

    // Minutes before the advice of a vehicle can be generated again by hand.
    'regenerate_after' => 10,

    // Seconds between two advices of the daily run, to stay within the
    // requests per minute of the free tier.
    'pause_between' => 6,

    // The part of the tank left when a refueling is due.
    'reserve' => 0.15,

    // Kilometers one way that do not count as detour costs while the E10 in the
    // tank is getting old: the vehicle is hardly driven, and a drive does it good.
    'old_fuel_free_km' => 50,

    // How much longer the road is than the straight line to a gas station abroad.
    'road_factor' => 1.3,

    // Places just across the border where people go to refuel, per country, as
    // [latitude, longitude]. The distance from the city of the user to the nearest
    // one tells whether refueling there is worth the detour.
    'border_points' => [
        'belgium' => [
            [51.441, 4.738],  // Meer (A1/E19)
            [51.468, 4.444],  // Essen
            [51.451, 5.045],  // Poppel
            [51.254, 5.457],  // Lommel / Achel
            [51.104, 5.792],  // Maaseik
            [50.893, 5.652],  // Lanaken
            [50.716, 6.012],  // Kelmis
            [51.203, 3.813],  // Zelzate
            [49.684, 5.817],  // Arlon
        ],
        'luxembourg' => [
            [49.713, 6.503],  // Wasserbillig
            [49.663, 5.920],  // Steinfort
            [49.836, 5.744],  // Martelange
            [49.510, 6.190],  // Frisange
            [49.470, 6.365],  // Schengen
            [50.140, 6.070],  // Weiswampach
            [49.812, 6.418],  // Echternach
        ],
        'germany' => [
            [53.187, 7.268],  // Bunde
            [52.433, 7.068],  // Nordhorn
            [52.307, 7.160],  // Bad Bentheim
            [52.212, 7.025],  // Gronau
            [51.838, 6.616],  // Bocholt
            [51.833, 6.247],  // Emmerich
            [51.320, 6.200],  // Kaldenkirchen
            [51.040, 5.893],  // Selfkant
            [50.776, 6.084],  // Aachen
            [49.710, 6.533],  // Wasserliesch
            [49.474, 6.388],  // Perl
        ],
        'netherlands' => [
            [53.183, 7.208],  // Nieuweschans
            [52.221, 6.894],  // Enschede
            [51.370, 6.172],  // Venlo
            [50.851, 5.691],  // Maastricht
            [50.866, 6.062],  // Kerkrade
            [51.502, 4.765],  // Hazeldonk
            [51.437, 4.932],  // Baarle-Nassau
            [51.284, 4.052],  // Hulst
        ],
    ],
];
