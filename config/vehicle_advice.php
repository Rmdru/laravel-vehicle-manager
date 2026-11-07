<?php

declare(strict_types=1);

return [
    // The same free tier of the Gemini API as the fuel advice, and its keys.
    'provider' => env('VEHICLE_ADVICE_PROVIDER', env('FUEL_ADVICE_PROVIDER', 'gemini')),
    // Flash rather than flash-lite: tested on the facts of production, lite
    // repeated tips that were done or kept, flash did not. Flash is often
    // overloaded on the free tier, so lite is asked when it is, with every key.
    'model' => env('VEHICLE_ADVICE_MODEL', 'gemini-3.5-flash'),
    'fallback_model' => env('VEHICLE_ADVICE_FALLBACK_MODEL', 'gemini-3.1-flash-lite'),

    // Seconds to wait for an answer.
    'timeout' => 90,

    // Minutes before the advice of a vehicle can be generated again by hand.
    'regenerate_after' => 10,

    // Days after which the daily run generates the advice again, even when the
    // facts did not change: the season, and what the model makes of it, do.
    'refresh_after' => 7,

    // Seconds between two advices of the daily run, to stay within the
    // requests per minute of the free tier.
    'pause_between' => 6,

    // Days a handled tip keeps the model from making a tip about the same
    // subject again: after that it can be relevant again, such as a seasonal
    // one the next year. Not relevant counts longer than done or kept.
    'handled_for_days' => [
        'done' => 180,
        'kept' => 180,
        'not_relevant' => 365,
    ],

    // Seconds a vehicle is locked while its advice is generated, so that two
    // runs at the same time ask only once.
    'lock_for' => 300,

    // Suggestions shown on the dashboard, at most.
    'suggestions' => 5,

    // Days ahead a suggestion of high priority is due within, to be shown
    // under "Needs attention" as well.
    'attention_within' => 30,
];
