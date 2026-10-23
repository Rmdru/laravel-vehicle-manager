<?php

declare(strict_types=1);

return [
    // The same free tier of the Gemini API as the other advices, and its keys.
    'provider' => env('MONTHLY_SUMMARY_PROVIDER', env('FUEL_ADVICE_PROVIDER', 'gemini')),
    'model' => env('MONTHLY_SUMMARY_MODEL', env('FUEL_ADVICE_MODEL', 'gemini-3.1-flash-lite')),

    // Seconds to wait for an answer.
    'timeout' => 60,

    // Minutes before the summary of a vehicle is written again after its
    // facts changed: it is written when the dashboard is opened.
    'regenerate_after' => 10,

    // Up to this day of the month, the summary looks back at the previous
    // month: a few days of the new one say too little.
    'review_until_day' => 7,

    // Days ahead the deadlines are shown within, and how many at most.
    'upcoming_within' => 60,
    'upcoming' => 3,

    // Percentages within which a figure counts as "as usual".
    'as_usual_within' => 10,
];
