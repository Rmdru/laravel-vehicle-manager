<?php

declare(strict_types=1);

return [
    // The same free tier of the Gemini API as the other advices, and its keys.
    'provider' => env('POWERTRAIN_COMPARISON_PROVIDER', env('FUEL_ADVICE_PROVIDER', 'gemini')),
    // A stronger model than that of the other advices: the lighter one leaves
    // out most powertrains and ignores the limits of the power. It is asked
    // only when a vehicle changes, or once a year. When it fails (it can be
    // overloaded), the model of the other advices is asked instead.
    'model' => env('POWERTRAIN_COMPARISON_MODEL', 'gemini-3-flash-preview'),
    'fallback_model' => env('FUEL_ADVICE_MODEL', 'gemini-3.1-flash-lite'),

    // Seconds to wait for an answer: it is a long one, with a car per powertrain.
    'timeout' => 120,

    // Minutes before the alternatives of a vehicle can be chosen again, by hand
    // or because the dashboard found none.
    'regenerate_after' => 10,

    // Days after which the daily run chooses the alternatives again: new
    // models come out, and the estimates of the prices age.
    'refresh_after' => 365,

    // Days after which the estimated prices of the energy without a feed
    // (charging, CNG, hydrogen, AdBlue) are asked for again, per country.
    'prices_refresh_after' => 90,

    // Seconds between two vehicles of the daily run, to stay within the
    // requests per minute of the free tier.
    'pause_between' => 6,

    // The kilometers a year when nothing tells how much the vehicle is driven.
    'default_km_per_year' => 15000,

    // The range of an electric vehicle, and the electric range of a plug-in
    // hybrid, as a part of its WLTP range: the worst case, in winter on the
    // motorway.
    'ev_range_factor' => 0.6,

    // Without charging at home, the part of the energy of an electric vehicle
    // that is fast charged, by kilometers a year: the more is driven, the less
    // time there is to wait at a slow charger (a public one near home counts
    // as slow). Interpolated in between, for a range of reference_range_km; a
    // shorter range takes more fast charging, a longer one less.
    'fast_charging' => [
        'by_km_per_year' => [5000 => 0.10, 10000 => 0.15, 20000 => 0.25, 30000 => 0.40, 45000 => 0.55],
        'reference_range_km' => 300,
        'at_least' => 0.05,
        'at_most' => 0.85,
    ],

    // A plug-in hybrid charges at a slow charger only, public or near home, on
    // this part of the days it is driven, and drives electric for as much of
    // the day as its range covers.
    'plugin_hybrid' => [
        'charged_on' => 0.6,
        'driving_days_per_year' => 300,
    ],

    // What a vehicle on LPG or CNG still drives on gasoline (starting, and
    // when the gas runs out), and how much gasoline it takes compared to the
    // gas: liters per liter of LPG, or per kilogram of CNG.
    'gas_vehicles' => [
        'gasoline_share' => ['gasoline_lpg' => 0.05, 'gasoline_lpg_full_hybrid' => 0.05, 'gasoline_cng' => 0.03],
        'gasoline_per_unit' => ['gasoline_lpg' => 0.8, 'gasoline_lpg_full_hybrid' => 0.8, 'gasoline_cng' => 1.4],
    ],

    // Liters of AdBlue per 100 km of a diesel with AdBlue.
    'adblue_per_100km' => 0.12,

    // Per powertrain: the fuel it runs on (a fuel type of the refuelings, or
    // null for none), whether it charges, and whether it takes AdBlue.
    'powertrains' => [
        'gasoline' => ['fuel' => 'Unleaded 95 (E10)'],
        'gasoline_mild_hybrid' => ['fuel' => 'Unleaded 95 (E10)'],
        'gasoline_full_hybrid' => ['fuel' => 'Unleaded 95 (E10)'],
        'gasoline_plugin_hybrid' => ['fuel' => 'Unleaded 95 (E10)', 'charges' => true],
        'gasoline_lpg' => ['fuel' => 'LPG'],
        'gasoline_lpg_full_hybrid' => ['fuel' => 'LPG'],
        'gasoline_cng' => ['fuel' => 'CNG'],
        'diesel' => ['fuel' => 'Diesel'],
        'diesel_adblue' => ['fuel' => 'Diesel', 'adblue' => true],
        'diesel_mild_hybrid' => ['fuel' => 'Diesel'],
        'diesel_full_hybrid' => ['fuel' => 'Diesel'],
        'diesel_plugin_hybrid' => ['fuel' => 'Diesel', 'charges' => true],
        'hydrogen' => ['fuel' => 'Hydrogen'],
        'electricity' => ['fuel' => null, 'charges' => true],
    ],

    // The prices when neither a feed, nor the refuelings of the owner, nor an
    // estimate is there, in euros per liter, kilogram or kWh.
    'fallback_prices' => [
        'Unleaded 95 (E10)' => 2.00,
        'Diesel' => 1.80,
        'LPG' => 1.00,
        'CNG' => 1.60,
        'Hydrogen' => 15.00,
        'Adblue' => 1.00,
        'Electricity AC' => 0.50,
        'Electricity DC' => 0.75,
    ],

    // What a price of the refuelings of the owner, or an estimate, may be at
    // least and at most to be used, by fuel type: a typo or a price of
    // another unit is left out.
    'price_bounds' => [
        'Unleaded 95 (E10)' => [1.00, 3.50],
        'Diesel' => [1.00, 3.50],
        'LPG' => [0.40, 2.00],
        'CNG' => [0.80, 4.00],
        'Hydrogen' => [5.00, 30.00],
        'Adblue' => [0.30, 4.00],
        'Electricity AC' => [0.15, 1.00],
        'Electricity DC' => [0.25, 1.30],
    ],

    // What the estimates of the AI model may be, at least and at most; an
    // alternative with a value outside is left out.
    'bounds' => [
        'build_year' => [1990, null],
        'power_hp' => [40, 800],
        'fuel_per_100km' => [0.3, 25],
        'electricity_kwh_per_100km' => [8, 40],
        'wltp_electric_range_km' => [15, 1000],
        'tank_capacity' => [3, 150],
        'gasoline_tank_l' => [0, 100],
        'price_now_eur' => [500, 250000],
        'depreciation_per_year_eur' => [0, 40000],
        'maintenance_per_year_eur' => [50, 8000],
        'insurance_per_year_eur' => [50, 8000],
        'road_tax_per_year_eur' => [0, 6000],
        // The driving style of the owner against the usual consumption of the
        // model, applied to the alternatives.
        'driving_style' => [0.8, 1.3],
    ],
];
