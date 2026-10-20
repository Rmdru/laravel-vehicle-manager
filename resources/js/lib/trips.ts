import type { TrafficIncident, TripRoute } from '@/types/trips';

// The groups of places the trip map can show, with their color and icon.
export const POI_GROUPS: Record<
    string,
    { kinds: string[]; color: string; icon: string; label: string }
> = {
    gas_stations: {
        kinds: ['gas_station'],
        color: '#dc2626',
        icon: 'ic:round-local-gas-station',
        label: 'Gas stations',
    },
    service_areas: {
        kinds: ['service_area'],
        color: '#2563eb',
        icon: 'maki:highway-rest-area',
        label: 'Service areas',
    },
    rest_areas: {
        kinds: ['rest_area'],
        color: '#16a34a',
        icon: 'mdi:parking',
        label: 'Rest areas',
    },
    food: {
        kinds: ['restaurant', 'fast_food', 'cafe'],
        color: '#d97706',
        icon: 'mdi:silverware-variant',
        label: 'Restaurants',
    },
    chargers: {
        kinds: ['charging_station'],
        color: '#0891b2',
        icon: 'mdi:ev-station',
        label: 'Charging stations',
    },
    hotels: {
        kinds: ['hotel'],
        color: '#db2777',
        icon: 'mdi:bed',
        label: 'Hotels',
    },
    toilets: {
        kinds: ['toilets'],
        color: '#7c3aed',
        icon: 'mdi:toilet',
        label: 'Toilets',
    },
    toll_booths: {
        kinds: ['toll_booth'],
        color: '#475569',
        icon: 'mdi:boom-gate',
        label: 'Toll booths',
    },
};

export function groupOf(kind: string): string | undefined {
    return Object.entries(POI_GROUPS).find(([, group]) =>
        group.kinds.includes(kind),
    )?.[0];
}

// The interface strings of the kinds of places and what they offer; translated with t().
export const KIND_LABELS: Record<string, string> = {
    gas_station: 'Gas station',
    service_area: 'Service area',
    rest_area: 'Rest area',
    restaurant: 'Restaurant',
    fast_food: 'Fast food',
    cafe: 'Café',
    toilets: 'Toilets',
    toll_booth: 'Toll booth',
    hotel: 'Hotel',
    charging_station: 'Charging station',
};

export const FEATURE_LABELS: Record<string, string> = {
    hotel: 'Hotel',
    fuel: 'Fuel',
    charge: 'Fast charger',
    coffee: 'Coffee',
    meal: 'Restaurant',
    toilet: 'Toilet',
    parking: 'Parking',
};

export const FEATURE_ICONS: Record<string, string> = {
    hotel: 'mdi:bed',
    fuel: 'ic:round-local-gas-station',
    charge: 'mdi:ev-station',
    coffee: 'mdi:coffee',
    meal: 'mdi:silverware-variant',
    toilet: 'mdi:toilet',
    parking: 'mdi:parking',
};

// The other facilities of a place, with their icon; the labels are translated with t().
export const SERVICES: Record<string, { icon: string; label: string }> = {
    shop: { icon: 'mdi:shopping', label: 'Shop' },
    charging: { icon: 'mdi:ev-station', label: 'Charging station' },
    car_wash: { icon: 'mdi:car-wash', label: 'Car wash' },
    atm: { icon: 'mdi:atm', label: 'ATM' },
    shower: { icon: 'mdi:shower', label: 'Shower' },
    playground: { icon: 'mdi:slide', label: 'Playground' },
    picnic: { icon: 'mdi:table-picnic', label: 'Picnic area' },
    drinking_water: { icon: 'mdi:water', label: 'Drinking water' },
    air: { icon: 'mdi:tire', label: 'Tyre air' },
    wifi: { icon: 'mdi:wifi', label: 'Wi-Fi' },
};

export const NEED_ICONS: Record<string, string> = {
    hotel: 'mdi:bed',
    break: 'mdi:coffee',
    meal: 'mdi:silverware-variant',
    toilet: 'mdi:toilet',
    fuel: 'ic:round-local-gas-station',
};

// The kinds of traffic incidents, with their icon and color.
export const TRAFFIC: Record<
    string,
    { icon: string; color: string; label: string }
> = {
    closed: {
        icon: 'mdi:minus-circle',
        color: '#dc2626',
        label: 'Road closed',
    },
    road_works: {
        icon: 'mdi:traffic-cone',
        color: '#ea580c',
        label: 'Road works',
    },
    lane_closed: {
        icon: 'ic:round-remove-road',
        color: '#f59e0b',
        label: 'Lane closed',
    },
    jam: {
        icon: 'mdi:car-brake-alert',
        color: '#b91c1c',
        label: 'Traffic jam',
    },
    accident: {
        icon: 'mdi:car-emergency',
        color: '#dc2626',
        label: 'Accident',
    },
    broken_down: {
        icon: 'mdi:car-cog',
        color: '#f59e0b',
        label: 'Broken down vehicle',
    },
    danger: {
        icon: 'mdi:alert',
        color: '#f59e0b',
        label: 'Dangerous situation',
    },
    typical_busy: {
        icon: 'mdi:car-multiple',
        color: '#f59e0b',
        label: 'Usually busy',
    },
    typical_jam: {
        icon: 'mdi:car-multiple',
        color: '#dc2626',
        label: 'Usually a traffic jam',
    },
};

// The traffic on a route: what is known to happen, and where it is usually
// busy at the time it is driven, in the order they are driven.
export function trafficOf(route: TripRoute | undefined): TrafficIncident[] {
    if (!route) {
        return [];
    }

    return [
        ...(route.traffic ?? []),
        ...(route.congestion ?? []).map((stretch): TrafficIncident => ({
            category: stretch.level === 'jam' ? 'typical_jam' : 'typical_busy',
            description: '',
            road: stretch.road_badge?.label ?? null,
            road_badge: stretch.road_badge,
            from: null,
            to: null,
            delay: stretch.delay,
            length: stretch.length,
            start: null,
            end: null,
            latitude: stretch.latitude,
            longitude: stretch.longitude,
            along: stretch.along,
        })),
    ].sort((a, b) => a.along - b.along);
}

// The kinds of traffic that can be shown or hidden on the map, each on its own.
export const TRAFFIC_GROUPS: Record<
    string,
    { icon: string; color: string; label: string; categories: string[] }
> = {
    road_works: {
        icon: 'mdi:traffic-cone',
        color: '#ea580c',
        label: 'Road works',
        categories: ['road_works', 'lane_closed'],
    },
    closed: {
        icon: 'mdi:minus-circle',
        color: '#dc2626',
        label: 'Closures',
        categories: ['closed'],
    },
    jam: {
        icon: 'mdi:car-brake-alert',
        color: '#b91c1c',
        label: 'Traffic jams',
        categories: ['jam'],
    },
    typical: {
        icon: 'mdi:car-multiple',
        color: '#f59e0b',
        label: 'Usually busy',
        categories: ['typical_busy', 'typical_jam'],
    },
    incidents: {
        icon: 'mdi:car-emergency',
        color: '#f59e0b',
        label: 'Incidents',
        categories: ['accident', 'broken_down', 'danger'],
    },
};

// The categories of the travel tips, with their icon.
export const TIP_ICONS: Record<string, string> = {
    rules: 'mdi:gavel',
    costs: 'mdi:cash-multiple',
    timing: 'mdi:calendar-clock',
    season: 'mdi:weather-snowy',
    vehicle: 'mdi:car-cog',
    comfort: 'mdi:sofa',
};

// What is done at a stop, by need: charging rather than refueling for an electric vehicle.
export function needIcon(need: string, electric: boolean): string {
    return need === 'fuel' && electric ? 'mdi:ev-station' : NEED_ICONS[need];
}

export function needLabel(need: string, electric: boolean): string {
    return need === 'fuel' && electric ? 'Charge' : NEED_LABELS[need];
}

export const NEED_LABELS: Record<string, string> = {
    hotel: 'Overnight stay',
    break: 'Break',
    meal: 'Restaurant',
    toilet: 'Toilet',
    fuel: 'Refuel',
};
