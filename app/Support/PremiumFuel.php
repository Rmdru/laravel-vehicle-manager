<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What counts as the premium variant of the fuel of a powertrain, and what it
 * is a share of. Fuels without a premium variant (LPG, CNG, AdBlue, E85) and
 * the other energy of a hybrid count for neither.
 */
class PremiumFuel
{
    public const REGULAR_GASOLINE = ['Unleaded 95 (E10)', 'Unleaded 95 (E5)'];

    public const PREMIUM_GASOLINE = ['Super Plus (E5)', 'Super Plus (E10)', 'V-Power 100', 'Ultimate 102'];

    /**
     * The same order as the title of the ratio on the dashboard: diesel, then
     * electric, else gasoline. For an electric vehicle fast (DC) charging is
     * the "premium" one.
     *
     * @return array{premium: list<string>, all: list<string>}
     */
    public static function for(?string $powertrain): array
    {
        $powertrain = (string) $powertrain;

        return match (true) {
            str_contains($powertrain, 'diesel') => ['premium' => ['Premium diesel'], 'all' => ['Diesel', 'Premium diesel']],
            str_contains($powertrain, 'electric') => ['premium' => ['Electricity DC'], 'all' => ['Electricity AC', 'Electricity DC']],
            default => ['premium' => self::PREMIUM_GASOLINE, 'all' => [...self::REGULAR_GASOLINE, ...self::PREMIUM_GASOLINE]],
        };
    }
}
