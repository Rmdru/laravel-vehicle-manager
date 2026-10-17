<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * General suggestions for a vehicle, written by an AI model from everything
 * that is known about it: its state, its records and its costs.
 */
class VehicleAdvice extends Model
{
    use HasUuids;
    use MassPrunable;

    protected $table = 'vehicle_advice';

    public const CATEGORIES = ['maintenance', 'season', 'known_issue', 'costs', 'value', 'legal', 'usage'];

    public const PRIORITIES = ['high', 'medium', 'low'];

    /** Where a suggestion comes from: the records in the app, or the general knowledge of the model. */
    public const BASES = ['app_data', 'general_knowledge'];

    /** What the driver can do about a suggestion, in the app. */
    public const ACTIONS = ['add_maintenance', 'add_reconditioning', 'view_insurances', 'view_taxes', 'view_vehicle', 'none'];

    /**
     * The icons the model can choose from, with what each shows, by subject:
     * only icons that are in resources/icons can be shown. Add one with
     * `php artisan icons:add`; a test checks that every one is there.
     */
    public const ICONS = [
        // Maintenance and repairs.
        'mdi:car-wrench' => 'maintenance or a repair in general',
        'mdi:wrench' => 'a small repair or adjustment',
        'mdi:hammer-wrench' => 'a big repair',
        'mdi:tools' => 'doing it yourself, tools',
        'mdi:car-cog' => 'the technical state of the vehicle',
        'mdi:car-settings' => 'adjusting or programming the vehicle',
        'mdi:car-info' => 'information about the vehicle',
        'mdi:garage-alert' => 'a visit to the garage',
        'mdi:clipboard-check-outline' => 'a check or inspection to have done',
        'mdi:clipboard-list-outline' => 'a list of things to check',
        // The engine and the drivetrain.
        'mdi:engine' => 'the engine',
        'mdi:engine-outline' => 'the engine warning light, engine management',
        'mdi:car-turbocharger' => 'the turbo',
        'mdi:oil' => 'engine oil, an oil change',
        'mdi:oil-level' => 'the oil level',
        'mdi:oil-temperature' => 'the oil temperature',
        'mdi:car-shift-pattern' => 'the gearbox',
        'mdi:car-clutch' => 'the clutch',
        'mdi:water-pump' => 'the water pump',
        'mdi:fan' => 'the radiator fan, ventilation',
        'mdi:car-coolant-level' => 'coolant',
        'mdi:coolant-temperature' => 'overheating, the cooling system',
        'mdi:air-filter' => 'the air filter or cabin filter',
        'mdi:filter-outline' => 'the fuel filter or another filter',
        'mdi:molecule-co2' => 'exhaust gases, emissions, the particle filter',
        // Brakes, tires and steering.
        'mdi:car-brake-alert' => 'the brakes',
        'mdi:car-brake-worn-linings' => 'brake pads or discs',
        'mdi:car-brake-fluid-level' => 'brake fluid',
        'mdi:car-brake-temperature' => 'brakes that get hot',
        'mdi:car-brake-abs' => 'ABS',
        'mdi:car-brake-parking' => 'the parking brake',
        'mdi:car-esp' => 'ESP, stability control',
        'mdi:car-traction-control' => 'traction control, grip',
        'mdi:tire' => 'tires',
        'mdi:car-tire-alert' => 'tire pressure, tread depth',
        'ic:round-tire-repair' => 'a flat tire, tire repair',
        'mdi:steering' => 'the steering, wheel alignment',
        // Battery and electric driving.
        'mdi:car-battery' => 'the battery (12 volt)',
        'mdi:battery-alert' => 'a weak or empty battery',
        'mdi:battery-charging' => 'charging, the battery of an electric vehicle',
        'mdi:car-electric' => 'an electric vehicle',
        'mdi:ev-station' => 'a charging station',
        'mdi:ev-plug-type2' => 'the charging cable, the charging plug',
        'mdi:lightning-bolt' => 'electricity, the electrical system',
        'mdi:solar-power' => 'charging with solar power',
        // Lights, windows and the interior.
        'mdi:car-light-high' => 'the headlights',
        'mdi:car-light-dimmed' => 'the dipped lights',
        'mdi:car-light-fog' => 'the fog lights',
        'mdi:car-parking-lights' => 'the parking lights',
        'mdi:car-light-alert' => 'a broken light',
        'mdi:hazard-lights' => 'the hazard lights, a warning on the road',
        'mdi:wiper' => 'wipers or windshield washer fluid',
        'mdi:car-windshield-outline' => 'the windshield, a chip or crack',
        'mdi:car-defrost-front' => 'defrosting the windshield',
        'mdi:car-defrost-rear' => 'the rear window heating',
        'mdi:air-conditioner' => 'the air conditioning',
        'mdi:car-seat' => 'the seats, the interior',
        'mdi:car-seat-heater' => 'seat heating',
        'mdi:car-child-seat' => 'a child seat',
        'mdi:seatbelt' => 'safety, seatbelts or airbags',
        'mdi:car-door' => 'the doors, hinges and seals',
        'mdi:car-door-lock' => 'the locks, central locking',
        'mdi:car-key' => 'the key, the remote',
        'mdi:shield-lock' => 'theft, security',
        'mdi:car-cruise-control' => 'cruise control, driver assistance',
        'mdi:car-speed-limiter' => 'the speed limiter',
        'mdi:navigation-variant' => 'navigation',
        'mdi:bluetooth' => 'infotainment, software updates',
        // Washing and the bodywork.
        'mdi:car-wash' => 'washing',
        'mdi:spray' => 'protecting the paint, wax or coating',
        'mdi:spray-bottle' => 'cleaning products',
        'mdi:vacuum' => 'cleaning the interior',
        'mdi:format-paint' => 'the paintwork, scratches, rust',
        'mdi:car-side' => 'the bodywork',
        'mdi:car-back' => 'the rear, the tow bar',
        'mdi:dog-side' => 'transporting pets',
        // Fuel and consumption.
        'mdi:fuel' => 'fuel, fuel consumption',
        'ic:round-local-gas-station' => 'refueling',
        'mdi:gas-station-outline' => 'a gas station',
        'mdi:gas-cylinder' => 'LPG or CNG',
        'mdi:hydrogen-station' => 'hydrogen',
        'mdi:gauge' => 'consumption or a gauge',
        'mdi:gauge-low' => 'a low level, an almost empty tank',
        // The season and the weather.
        'mdi:snowflake' => 'winter',
        'mdi:snowflake-alert' => 'frost, slippery roads',
        'mdi:thermometer-low' => 'cold, low temperatures',
        'mdi:thermometer' => 'the temperature',
        'mdi:weather-snowy' => 'snow',
        'mdi:weather-pouring' => 'rain',
        'mdi:weather-fog' => 'fog',
        'mdi:weather-sunny' => 'sun, spring',
        'mdi:heat-wave' => 'summer, heat',
        'material-symbols:sunny-snowing' => 'the change of season, winter or summer tires',
        // Rules, inspections and documents.
        'ic:baseline-security' => 'the MOT',
        'mdi:clipboard-alert' => 'a recall, a defect found at an inspection',
        'mdi:alert-decagram-outline' => 'a known weak spot of the model',
        'mdi:shield-car' => 'insurance',
        'mdi:shield-check' => 'cover, warranty',
        'mdi:highway' => 'road tax',
        'mdi:sticker-text' => 'a vignette',
        'mdi:sticker-check-outline' => 'a sticker or registration that is valid',
        'fa7-solid:leaf' => 'an environmental sticker, low emission zones',
        'mdi:file-document-outline' => 'documents, the registration certificate',
        'mdi:file-certificate-outline' => 'a certificate, a report',
        'mdi:passport' => 'travel documents, the green card',
        'mdi:police-badge' => 'the police, a fine',
        'mdi:scale-balance' => 'the law, rules',
        // Money.
        'mdi:hand-coin-outline' => 'saving money',
        'mdi:piggy-bank-outline' => 'saving up for a cost to come',
        'mdi:cash-multiple' => 'costs',
        'mdi:receipt-text-outline' => 'an invoice, a receipt',
        'mdi:chart-line' => 'a trend in costs or consumption',
        'mdi:trending-down' => 'depreciation, costs going down',
        'mdi:tag-outline' => 'the resale value',
        'mdi:sale' => 'selling the vehicle',
        'mdi:handshake-outline' => 'trading in, a deal',
        'mdi:car-search' => 'looking for another vehicle',
        // Use and trips.
        'mdi:speedometer' => 'the mileage, the odometer',
        'mdi:speedometer-slow' => 'driving little',
        'ic:baseline-route' => 'driving, trips',
        'mdi:map-marker-path' => 'a long trip',
        'mdi:road-variant' => 'the road, road conditions',
        'mdi:earth' => 'driving abroad',
        'mdi:ferry' => 'a ferry',
        'mdi:parking' => 'parking',
        'mdi:car-off' => 'a vehicle that stands still for a long time',
        'mdi:truck-trailer' => 'towing a trailer',
        'mdi:caravan' => 'a caravan',
        'mdi:rv-truck' => 'a camper',
        'mdi:bike' => 'a bike rack',
        'mdi:account-group' => 'passengers, a full vehicle',
        'mdi:car-emergency' => 'a breakdown, roadside assistance',
        'mdi:recycle' => 'scrapping, recycling',
        // Time.
        'ic:round-calendar-month' => 'a date or deadline',
        'mdi:calendar-alert' => 'a deadline that is near',
        'mdi:calendar-check' => 'something to plan',
        'mdi:clock-alert-outline' => 'something overdue',
        'mdi:timer-sand' => 'waiting, later',
        'mdi:history' => 'the history of the vehicle',
        // Otherwise.
        'mdi:alert-circle-outline' => 'a warning',
        'mdi:bell-ring-outline' => 'a reminder',
        'mdi:lightbulb-on-outline' => 'anything else',
    ];

    protected $fillable = [
        'vehicle_id',
        'locale',
        'suggestions',
        'facts',
        'facts_hash',
        'model',
    ];

    protected $casts = [
        'suggestions' => 'array',
        'facts' => 'array',
    ];

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Old advices are of no use anymore.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subMonths(3));
    }
}
