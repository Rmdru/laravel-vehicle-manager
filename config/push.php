<?php

declare(strict_types=1);

return [
    // The key pair the push services know the app by: generate it once with
    // `php artisan push:vapid`. Changed, every device has to subscribe again.
    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        // How the push services can reach whoever runs the app: mailto: or https:.
        'subject' => env('VAPID_SUBJECT') ?: env('APP_URL', 'http://localhost'),
    ],

    // What the owner gets of a vehicle, until they change it in its form.
    'defaults' => [
        'critical' => true,
        'warning' => true,
        'info' => true,
        'tips' => true,
        'summary' => true,
        // Empty: the moment the owner usually uses the app.
        'moment' => null,
        // Nothing is sent in between, not even when it is critical.
        'quiet_from' => '21:00',
        'quiet_until' => '08:00',
    ],

    // The moment of the owner while too little is known of when they use the app.
    'moment' => '18:00',

    // The records the moment is derived from at least, and how far back.
    'moment_records' => 5,
    'moment_months' => 12,

    // As many messages at once for a vehicle, or more, go as one.
    'bundle_from' => 4,

    // Seconds a push service keeps trying to deliver a message to a device that is off.
    'ttl' => 60 * 60 * 24,

    // The time zone of an owner whose country has none of its own.
    'timezone' => 'Europe/Amsterdam',
];
