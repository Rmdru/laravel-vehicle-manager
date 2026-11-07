import type { Poi } from '@/types/trips';

export type StationKind = 'fuel' | 'fast' | 'slow';

export type StationPrice = {
    // A fuel type of the app, such as Unleaded 95 (E10).
    fuel_type: string;
    price: number;
    // Self service or served, where the source tells (as in Italy).
    service: 'self' | 'served' | null;
    served_price: number | null;
    updated_at: string | null;
};

export type Station = {
    id: number;
    kind: StationKind;
    name: string | null;
    brand: string | null;
    latitude: number;
    longitude: number;
    country: string | null;
    details: Poi['details'];
    services: string[];
    connectors: string[];
    power: number | null;
    prices: StationPrice[];
    // Its prices are asked for the area apart, once zoomed in.
    live: boolean;
    // The last price the owner paid here, by the name of the gas station.
    own: { price: number; fuel_type: string; date: string } | null;
};

export type StationCluster = {
    latitude: number;
    longitude: number;
    count: number;
    counts: Partial<Record<StationKind, number>>;
};

export type StationFilters = {
    kinds: StationKind[];
    fuels: string[];
    connectors: string[];
    priced: boolean;
    // The fuel whose price is beside a gas station, and gives it its color.
    primary: string | null;
};

// The usual gas station of the vehicle, to compare a gas station with.
export type StationComparison = {
    fuel_type: string;
    usual: string;
    price: number;
    tank: number;
    costs_per_km: number;
} | null;

export type StationAverages = Record<
    string,
    { fuel_type: string; price: number }[]
>;
