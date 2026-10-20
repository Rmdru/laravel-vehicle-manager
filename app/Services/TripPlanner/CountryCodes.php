<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

/**
 * The ISO 2 codes of the countries in and around Europe by their ISO 3 code,
 * which TomTom uses.
 */
class CountryCodes
{
    private const ISO2 = [
        'ALB' => 'AL', 'AND' => 'AD', 'AUT' => 'AT', 'BEL' => 'BE', 'BGR' => 'BG', 'BIH' => 'BA', 'BLR' => 'BY',
        'CHE' => 'CH', 'CYP' => 'CY', 'CZE' => 'CZ', 'DEU' => 'DE', 'DNK' => 'DK', 'ESP' => 'ES', 'EST' => 'EE',
        'FIN' => 'FI', 'FRA' => 'FR', 'GBR' => 'GB', 'GIB' => 'GI', 'GRC' => 'GR', 'HRV' => 'HR', 'HUN' => 'HU',
        'IRL' => 'IE', 'ISL' => 'IS', 'ITA' => 'IT', 'XKX' => 'XK', 'LIE' => 'LI', 'LTU' => 'LT', 'LUX' => 'LU',
        'LVA' => 'LV', 'MCO' => 'MC', 'MDA' => 'MD', 'MKD' => 'MK', 'MLT' => 'MT', 'MNE' => 'ME', 'NLD' => 'NL',
        'NOR' => 'NO', 'POL' => 'PL', 'PRT' => 'PT', 'ROU' => 'RO', 'SMR' => 'SM', 'SRB' => 'RS', 'SVK' => 'SK',
        'SVN' => 'SI', 'SWE' => 'SE', 'TUR' => 'TR', 'UKR' => 'UA', 'VAT' => 'VA',
    ];

    public static function iso2(string $code): string
    {
        return strlen($code) === 2 ? strtoupper($code) : (self::ISO2[$code] ?? substr($code, 0, 2));
    }

    public static function iso3(string $iso2): ?string
    {
        return array_search(strtoupper($iso2), self::ISO2, true) ?: null;
    }
}
