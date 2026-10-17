<?php

declare(strict_types=1);

return [
    // The same free tier of the Gemini API as the fuel advice, and its keys.
    'provider' => env('VEHICLE_ADVICE_PROVIDER', env('FUEL_ADVICE_PROVIDER', 'gemini')),
    'model' => env('VEHICLE_ADVICE_MODEL', env('FUEL_ADVICE_MODEL', 'gemini-3.1-flash-lite')),

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

    // Suggestions shown on the dashboard, at most.
    'suggestions' => 5,

    // Days ahead a suggestion of high priority is due within, to be shown
    // under "Needs attention" as well.
    'attention_within' => 30,
];
