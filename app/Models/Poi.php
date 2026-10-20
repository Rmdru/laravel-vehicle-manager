<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A place from OpenStreetMap along a route: a service area, a rest area, a gas
 * station, a toll booth or an amenity at a service area.
 */
class Poi extends Model
{
    public const SERVICE_AREA = 'service_area';

    public const REST_AREA = 'rest_area';

    public const GAS_STATION = 'gas_station';

    public const RESTAURANT = 'restaurant';

    public const FAST_FOOD = 'fast_food';

    public const CAFE = 'cafe';

    public const TOILETS = 'toilets';

    public const TOLL_BOOTH = 'toll_booth';

    public const HOTEL = 'hotel';

    /** A fast charger for electric cars. */
    public const CHARGER = 'charging_station';

    /** Another facility at a service area, such as a shop or a car wash: not shown on its own. */
    public const FACILITY = 'facility';

    /** An exit of a motorway: not shown, but used to tell how far a gas station off the motorway is. */
    public const JUNCTION = 'junction';

    /** What a stop can offer. */
    public const FEATURES = ['hotel', 'fuel', 'charge', 'coffee', 'meal', 'toilet', 'parking'];

    protected $fillable = [
        'osm_type',
        'osm_id',
        'kind',
        'name',
        'brand',
        'latitude',
        'longitude',
        'tags',
        'tile',
    ];

    protected $casts = [
        'osm_id' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'tags' => 'array',
    ];

    /**
     * The kind of place of the tags of an OpenStreetMap element, if it is one
     * the trip planner uses.
     *
     * @param  array<string, string>  $tags
     */
    public static function kindOf(array $tags): ?string
    {
        return match (true) {
            ($tags['highway'] ?? null) === 'services' => self::SERVICE_AREA,
            ($tags['highway'] ?? null) === 'rest_area' => self::REST_AREA,
            ($tags['amenity'] ?? null) === 'fuel' => self::GAS_STATION,
            ($tags['amenity'] ?? null) === 'restaurant' => self::RESTAURANT,
            ($tags['amenity'] ?? null) === 'fast_food' => self::FAST_FOOD,
            ($tags['amenity'] ?? null) === 'cafe' => self::CAFE,
            ($tags['amenity'] ?? null) === 'toilets' => self::TOILETS,
            ($tags['barrier'] ?? null) === 'toll_booth' => self::TOLL_BOOTH,
            in_array($tags['tourism'] ?? null, ['hotel', 'motel'], true) => self::HOTEL,
            ($tags['amenity'] ?? null) === 'charging_station' && (isset($tags['socket:type2_combo']) || isset($tags['socket:chademo'])) => self::CHARGER,
            ($tags['highway'] ?? null) === 'motorway_junction' => self::JUNCTION,
            in_array($tags['amenity'] ?? null, ['charging_station', 'car_wash', 'atm', 'shower', 'drinking_water', 'compressed_air'], true),
            isset($tags['shop']) && $tags['shop'] !== 'no',
            ($tags['leisure'] ?? null) === 'playground',
            ($tags['tourism'] ?? null) === 'picnic_site' => self::FACILITY,
            default => null,
        };
    }

    /** The fuel types of the app, by the tag OpenStreetMap tells a gas station sells them with. */
    private const FUELS = [
        'fuel:e10' => 'Unleaded 95 (E10)',
        'fuel:octane_95' => 'Unleaded 95 (E5)',
        'fuel:octane_98' => 'Super Plus (E5)',
        'fuel:octane_100' => 'V-Power 100',
        'fuel:diesel' => 'Diesel',
        'fuel:lpg' => 'LPG',
        'fuel:cng' => 'CNG',
        'fuel:e85' => 'E85',
        'fuel:adblue' => 'Adblue',
    ];

    /** The tag OpenStreetMap tells a gas station sells a fuel type of the app with. */
    private const TAGS = [
        'Unleaded 95 (E10)' => 'fuel:e10',
        'Unleaded 95 (E5)' => 'fuel:octane_95',
        'Super Plus (E5)' => 'fuel:octane_98',
        'Super Plus (E10)' => 'fuel:octane_98',
        'V-Power 100' => 'fuel:octane_100',
        'Ultimate 102' => 'fuel:octane_102',
        'Diesel' => 'fuel:diesel',
        'Premium diesel' => 'fuel:diesel',
        'LPG' => 'fuel:lpg',
        'CNG' => 'fuel:cng',
        'E85' => 'fuel:e85',
    ];

    /**
     * Whether a gas station sells one of the fuel types of a vehicle. Most gas
     * stations do not tell what they sell: only one that tells its fuels
     * without any of them, or that it has none of them, does not.
     *
     * @param  list<string>|null  $fuelTypes  The fuel types of the vehicle; none to take any.
     */
    public function sells(?array $fuelTypes): bool
    {
        $accepted = array_values(array_unique(array_filter(array_map(fn (string $type) => self::TAGS[$type] ?? null, (array) $fuelTypes))));

        if ($accepted === []) {
            return true;
        }

        $tags = $this->tags ?? [];
        $yes = fn (string $key) => in_array($tags[$key] ?? null, ['yes', 'customers'], true);

        if (array_filter($accepted, $yes) !== []) {
            return true;
        }

        $tellsFuels = array_filter([...array_keys(self::FUELS), ...array_values(self::TAGS)], $yes) !== [];
        $hasNone = array_filter($accepted, fn (string $key) => ($tags[$key] ?? null) === 'no') === $accepted;

        return ! $tellsFuels && ! $hasNone;
    }

    /** The ways of paying, by their tag. */
    private const PAYMENTS = [
        'payment:cash' => 'Cash',
        'payment:debit_cards' => 'Debit card',
        'payment:credit_cards' => 'Credit card',
        'payment:contactless' => 'Contactless',
    ];

    /**
     * What OpenStreetMap tells about the place besides what it offers, as far
     * as it is filled in.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        $tags = $this->tags ?? [];
        $yes = fn (string $key) => in_array($tags[$key] ?? null, ['yes', 'customers'], true);
        $fuelTypes = trans('fuel_types');

        return array_filter([
            'opening_hours' => $tags['opening_hours'] ?? null,
            'operator' => $tags['operator'] ?? null,
            'fuels' => array_values(array_map(
                fn (string $type) => is_array($fuelTypes) ? ($fuelTypes[$type] ?? $type) : $type,
                array_filter(self::FUELS, fn (string $type, string $key) => $yes($key), ARRAY_FILTER_USE_BOTH),
            )),
            'payments' => array_values(array_filter(self::PAYMENTS, fn (string $label, string $key) => $yes($key), ARRAY_FILTER_USE_BOTH)),
            'shop' => isset($tags['shop']) && $tags['shop'] !== 'no',
            'wheelchair' => $yes('wheelchair'),
            // Only a web address: it is shown as a link.
            'website' => preg_match('#^https?://#i', (string) ($tags['website'] ?? $tags['contact:website'] ?? '')) ? ($tags['website'] ?? $tags['contact:website']) : null,
            'phone' => $tags['phone'] ?? $tags['contact:phone'] ?? null,
            'ref' => $tags['ref'] ?? null,
            'stars' => isset($tags['stars']) && is_numeric($tags['stars']) ? (int) $tags['stars'] : null,
        ]);
    }

    /**
     * The other facilities the place has, by its tags: shown, but not planned with.
     *
     * @return list<string>
     */
    public function services(): array
    {
        $tags = $this->tags ?? [];
        $yes = fn (string $key) => in_array($tags[$key] ?? null, ['yes', 'customers'], true);
        $amenity = $tags['amenity'] ?? null;

        return array_keys(array_filter([
            'shop' => isset($tags['shop']) && $tags['shop'] !== 'no',
            'charging' => $amenity === 'charging_station' || $this->kind === self::CHARGER,
            'car_wash' => $amenity === 'car_wash' || $yes('car_wash'),
            'atm' => $amenity === 'atm' || $yes('atm'),
            'shower' => $amenity === 'shower' || $yes('shower'),
            'playground' => ($tags['leisure'] ?? null) === 'playground',
            'picnic' => ($tags['tourism'] ?? null) === 'picnic_site' || $yes('picnic_table'),
            'drinking_water' => $amenity === 'drinking_water' || $yes('drinking_water'),
            'air' => $amenity === 'compressed_air' || $yes('compressed_air'),
            'wifi' => in_array($tags['internet_access'] ?? null, ['yes', 'wlan', 'wifi'], true),
        ]));
    }

    /**
     * The power of a fast charger in kW, as far as it is tagged.
     */
    public function power(): ?float
    {
        $tags = $this->tags ?? [];
        $power = null;

        foreach (['socket:type2_combo:output', 'socket:chademo:output', 'charging_station:output'] as $key) {
            // Such as "150 kW" or "150".
            if (isset($tags[$key]) && preg_match('/([\d.]+)/', (string) $tags[$key], $match)) {
                $power = max($power ?? 0, (float) $match[1]);
            }
        }

        return $power;
    }

    /**
     * What the place itself offers, by its kind and its tags. A service area
     * can offer more, through the amenities around it.
     *
     * @return list<string>
     */
    public function features(): array
    {
        $tags = $this->tags ?? [];
        $yes = fn (string $key) => in_array($tags[$key] ?? null, ['yes', 'customers'], true);

        $features = match ($this->kind) {
            // A service area along a motorway has at least a coffee and a toilet.
            // A toilet only where it is known: tagged, or with a restaurant or toilets around it.
            self::SERVICE_AREA => ['coffee', 'parking', ...($yes('toilets') ? ['toilet'] : [])],
            self::REST_AREA => ['parking', ...($yes('toilets') ? ['toilet'] : [])],
            self::GAS_STATION => ['fuel', ...(isset($tags['shop']) || $yes('cafe') ? ['coffee'] : []), ...($yes('toilets') ? ['toilet'] : [])],
            self::RESTAURANT, self::FAST_FOOD => ['meal', 'coffee', 'toilet'],
            self::CAFE => ['coffee', 'toilet'],
            self::TOILETS => ['toilet'],
            self::HOTEL => ['hotel', 'toilet', ...($yes('restaurant') ? ['meal'] : [])],
            self::CHARGER => ['charge'],
            default => [],
        };

        if ($yes('fuel')) {
            $features[] = 'fuel';
        }

        if ($yes('restaurant') || $yes('fast_food')) {
            $features[] = 'meal';
        }

        return array_values(array_unique($features));
    }
}
