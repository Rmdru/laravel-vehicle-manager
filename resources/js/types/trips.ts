export type Place = {
    label: string;
    latitude: number | null;
    longitude: number | null;
    stayMinutes?: number;
};

// What the driver enters to plan a trip.
export type TripFormData = {
    name: string;
    timeMode: 'departure' | 'arrival';
    timeAt: string;
    fuelLevel: number;
    waypoints: (Place | null)[];
};

export type TripNeed = 'hotel' | 'break' | 'meal' | 'toilet' | 'fuel';

// A road number with the classes of its sign.
export type RoadBadge = { label: string; classes: string };

export type FuelPrice = {
    price: number;
    // Whether it is the price of the gas station itself, or the average of the country.
    source: 'station' | 'average';
    updated_at: string | null;
    // The name of the fuel, in the language of the user.
    fuel_type: string | null;
};

export type TripStop = {
    type: 'stop' | 'waypoint';
    poi_id?: number;
    name: string | null;
    kind?: string;
    brand?: string | null;
    latitude?: number;
    longitude?: number;
    features?: string[];
    needs: TripNeed[];
    along: number;
    minutes: number;
    fuel_liters?: number | null;
    country?: string | null;
    road?: RoadBadge | null;
    // Chosen by the driver.
    pinned?: boolean;
    exit?: string | null;
    detour_seconds?: number;
    arrive_at: string;
    depart_at: string;
};

// A place a stop could be made at, with what it offers.
export type TripCandidate = {
    id: number;
    features: string[];
    // Other facilities, such as a shop or a car wash.
    services?: string[];
    brand?: string;
    gas_station_id?: number;
    exit?: string;
    detour_seconds: number;
};

export type TripRoute = {
    distance: number;
    drive_seconds: number;
    stop_seconds: number;
    traffic_delay: number;
    departure_at: string;
    arrival_at: string;
    roads: RoadBadge[];
    countries: string[];
    toll_distance: number;
    vignettes: string[];
    geometry: string;
    toll_points: {
        type: 'start' | 'end';
        latitude: number;
        longitude: number;
    }[];
    // Nothing while the places along the route are being fetched.
    stops: TripStop[] | null;
    warnings: string[];
    candidates: TripCandidate[];
    toll_booths: number[];
    poiIds: number[];
    prices: Record<number, FuelPrice>;
    // What the driver should know about the vehicle for this trip.
    notices: { level: 'warning' | 'info'; message: string }[];
    traffic: TrafficIncident[];
    // Where it is usually busy at the time it is driven.
    congestion?: TripCongestion[];
    // What the route costs, as far as it is known; missing in a trip planned before.
    costs?: TripCosts;
};

export type TripCosts = {
    // The fuel, at the prices of the fuel stops or else of those along the route.
    fuel: number | null;
    fuel_price: number | null;
    // Without prices on the route, or for charging, an estimate: at the average
    // prices of its countries, at those of the latest refuelings, or at a usual
    // price. Missing in a trip planned before.
    fuel_estimate?: 'countries' | 'refuelings' | 'typical' | null;
    // The liters, or the kWh, the route takes.
    energy: number | null;
    // A vignette that is owned already costs nothing; one never bought has no known price.
    vignettes: { country: string; price: number | null; owned: boolean }[];
    // The toll roads, at what the vehicle usually paid on a section of the same
    // road; no price when it never recorded one. Missing in a trip planned before.
    tolls?: {
        distance: number;
        label: string | null;
        price: number | null;
        passages: number;
    }[];
    // The costs that are known.
    total: number | null;
    // Whether the total holds everything: not with toll or a vignette of unknown price.
    complete: boolean;
};

// How far the planning is, while it is planned.
export type TripProgress = {
    value: number;
    stage: 'routes' | 'traffic' | 'places' | 'amenities' | 'stops';
    // The requests of the stage done, and in all.
    done?: number;
    total?: number;
};

export type TripCongestion = {
    level: 'busy' | 'jam';
    // The time lost there, in seconds.
    delay: number;
    length: number;
    along: number;
    latitude: number;
    longitude: number;
    road_badge: { label: string; classes: string } | null;
    geometry: string;
};

export type TrafficIncident = {
    category:
        | 'accident'
        | 'danger'
        | 'jam'
        | 'lane_closed'
        | 'closed'
        | 'road_works'
        | 'broken_down'
        | 'typical_busy'
        | 'typical_jam';
    description: string;
    road: string | null;
    road_badge?: { label: string; classes: string } | null;
    // The stretch of road of road works and closures, as [longitude, latitude].
    line?: [number, number][] | null;
    from: string | null;
    to: string | null;
    delay: number | null;
    length: number | null;
    start: string | null;
    end: string | null;
    latitude: number;
    longitude: number;
    along: number;
};

export type TripTips = {
    locale: string;
    generated_at: string;
    model: string;
    items: { category: string; title: string; body: string }[];
};

export type TripPlan = {
    vehicle: {
        consumption: number | null;
        tank: number | null;
        from_refuelings: boolean | null;
        fuel_type: string | null;
        // The name of the fuel, in the language of the user.
        fuel_type_label?: string | null;
        electric?: boolean;
    };
    loading: boolean;
    // The daily limit of TomTom was near: the traffic was left out.
    traffic_skipped?: boolean;
    routes: TripRoute[];
};

export type Poi = {
    id: number;
    kind: string;
    name: string | null;
    brand: string | null;
    latitude: number;
    longitude: number;
    features: string[];
    services: string[];
    details: {
        opening_hours?: string;
        operator?: string;
        fuels?: string[];
        payments?: string[];
        shop?: boolean;
        wheelchair?: boolean;
        website?: string;
        phone?: string;
        ref?: string;
        stars?: number;
    };
};
