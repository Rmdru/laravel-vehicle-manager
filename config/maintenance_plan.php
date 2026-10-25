<?php

declare(strict_types=1);

return [
    // The same free tier of the Gemini API as the other advices, and its keys.
    'provider' => env('MAINTENANCE_PLAN_PROVIDER', env('FUEL_ADVICE_PROVIDER', 'gemini')),
    'model' => env('MAINTENANCE_PLAN_MODEL', env('FUEL_ADVICE_MODEL', 'gemini-3.1-flash-lite')),

    // Seconds to wait for an answer.
    'timeout' => 90,

    // Tasks of the maintenance history the model sorts out at once, at most.
    'classify_at_most' => 200,

    // Days and kilometers ahead a component is due soon, and due.
    'soon_days' => 90,
    'soon_km' => 2000,
    'due_days' => 30,
    'due_km' => 500,

    /*
     * The components, by key: the label, the icon, the usual interval in
     * kilometers and in months (null: none), and the vehicles they apply to:
     *
     * - all: every vehicle, electric ones too: they need maintenance as well.
     * - engine: a vehicle with a combustion engine, hybrids included.
     * - spark_ignition: a gasoline engine (LPG and CNG included).
     *
     * A component with 'active' => false is not in the plan until the model
     * or the owner adds it: one that only some engines need.
     *
     * When it is not known when a component was done last, it is counted from
     * new (the construction date, at 0 km). Where that makes it overdue, it is
     * to be checked; 'critical' => true makes that red rather than orange: a
     * component that is expensive or dangerous to miss.
     *
     * These intervals are a starting point only: the AI model proposes the
     * ones of the make, model and engine, and the owner can change them.
     */
    'components' => [
        'engine_oil' => ['label' => 'Engine oil', 'icon' => 'mdi:oil', 'km' => 15000, 'months' => 12, 'for' => 'engine'],
        'oil_filter' => ['label' => 'Oil filter', 'icon' => 'iconoven:oil-filter', 'km' => 15000, 'months' => 12, 'for' => 'engine'],
        'air_filter' => ['label' => 'Air filter', 'icon' => 'mdi:air-filter', 'km' => 30000, 'months' => 24, 'for' => 'engine'],
        'fuel_filter' => ['label' => 'Fuel filter', 'icon' => 'iconoven:fuel-filter', 'km' => 60000, 'months' => 48, 'for' => 'engine'],
        'spark_plugs' => ['label' => 'Spark plugs', 'icon' => 'mdi:lightning-bolt', 'km' => 60000, 'months' => 48, 'for' => 'spark_ignition'],
        'timing_belt' => ['label' => 'Timing belt', 'icon' => 'carbon:timing-belt', 'km' => 120000, 'months' => 72, 'for' => 'engine', 'critical' => true],
        // Carbon deposits on the intake valves: only direct injection engines get them, so off until the model or the owner switches it on.
        // An injector cleaner in the fuel does not count: the fuel never reaches the intake valves of such an engine.
        'intake_cleaning' => ['label' => 'Intake cleaning', 'icon' => 'material-symbols:valve-rounded', 'km' => 80000, 'months' => null, 'for' => 'engine', 'active' => false],
        'auxiliary_belt' => ['label' => 'Auxiliary belt', 'icon' => 'mdi:engine-outline', 'km' => 120000, 'months' => 72, 'for' => 'engine'],
        // A part that wears: its usual life, which the model adjusts to the gearbox.
        'clutch' => ['label' => 'Clutch', 'icon' => 'mdi:car-clutch', 'km' => 150000, 'months' => null, 'for' => 'engine'],
        'gearbox_oil' => ['label' => 'Gearbox oil', 'icon' => 'mdi:car-shift-pattern', 'km' => 60000, 'months' => null, 'for' => 'all'],
        'cabin_filter' => ['label' => 'Cabin filter', 'icon' => 'mdi:flower-pollen', 'km' => 15000, 'months' => 12, 'for' => 'all'],
        'brake_fluid' => ['label' => 'Brake fluid', 'icon' => 'mdi:car-brake-fluid-level', 'km' => null, 'months' => 24, 'for' => 'all', 'critical' => true],
        'coolant' => ['label' => 'Coolant', 'icon' => 'mdi:car-coolant-level', 'km' => null, 'months' => 60, 'for' => 'all'],
        'front_brake_pads' => ['label' => 'Front brake pads', 'icon' => 'mdi:car-brake-worn-linings', 'km' => 40000, 'months' => null, 'for' => 'all'],
        'rear_brake_pads' => ['label' => 'Rear brake pads', 'icon' => 'mdi:car-brake-worn-linings', 'km' => 60000, 'months' => null, 'for' => 'all'],
        // With drum brakes at the rear, the rear pads are the brake shoes and the rear discs the drums.
        'front_brake_discs' => ['label' => 'Front brake discs', 'icon' => 'mdi:car-brake-alert', 'km' => 80000, 'months' => null, 'for' => 'all'],
        'rear_brake_discs' => ['label' => 'Rear brake discs', 'icon' => 'mdi:car-brake-alert', 'km' => 100000, 'months' => null, 'for' => 'all'],
        'front_tires' => ['label' => 'Front tires', 'icon' => 'mdi:tire', 'km' => 40000, 'months' => 72, 'for' => 'all', 'critical' => true],
        'rear_tires' => ['label' => 'Rear tires', 'icon' => 'mdi:tire', 'km' => 50000, 'months' => 72, 'for' => 'all', 'critical' => true],
        // Checked and adjusted by the garage: sooner after new tires, or a hit against a curb or a pothole.
        'wheel_alignment' => ['label' => 'Wheel alignment', 'icon' => 'mdi:steering', 'km' => 30000, 'months' => 24, 'for' => 'all'],
        'battery_12v' => ['label' => '12 volt battery', 'icon' => 'mdi:car-battery', 'km' => null, 'months' => 60, 'for' => 'all'],
        // Checks rather than replacements, ticked off at once: recorded as a maintenance of their own
        // type ('check'), and due within days rather than weeks (see soon_days and due_days).
        'tire_pressure' => ['label' => 'Tire pressure check', 'icon' => 'mdi:car-tire-alert', 'km' => null, 'months' => 2, 'for' => 'all', 'check' => 'tire_pressure_checked', 'soon_days' => 14, 'due_days' => 7],
        'fluid_levels' => ['label' => 'Fluid levels check', 'icon' => 'mdi:oil', 'km' => null, 'months' => 2, 'for' => 'all', 'check' => 'liquids_checked', 'soon_days' => 14, 'due_days' => 7],
        // Topped up rather than replaced: checked twice a year, with winter fluid before the frost.
        'washer_fluid' => ['label' => 'Washer fluid', 'icon' => 'mdi:wiper-wash', 'km' => null, 'months' => 6, 'for' => 'all'],
        'wiper_blades' => ['label' => 'Wiper blades', 'icon' => 'mdi:wiper', 'km' => null, 'months' => 12, 'for' => 'all'],
        'airco_service' => ['label' => 'Air conditioning service', 'icon' => 'mdi:air-conditioner', 'km' => null, 'months' => 24, 'for' => 'all'],
    ],
];
