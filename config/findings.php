<?php

declare(strict_types=1);

return [
    // The same free tier of the Gemini API as the other advices, and its keys.
    'provider' => env('FINDINGS_PROVIDER', env('FUEL_ADVICE_PROVIDER', 'gemini')),
    'model' => env('FINDINGS_MODEL', env('FUEL_ADVICE_MODEL', 'gemini-3.1-flash-lite')),

    // Seconds to wait for an answer.
    'timeout' => 90,

    // Minutes before a complaint can be diagnosed again.
    'diagnose_again_after' => 10,

    // Months a MOT is imported from the RDW after, at most: the defects of an
    // older one have been dealt with long since.
    'import_mot_within_months' => 12,
];
