<script setup lang="ts">
import type { FeatureCollection } from 'geojson';
import maplibregl from 'maplibre-gl';
import type {
    GeoJSONSource,
    MapLayerMouseEvent,
    Map as MapLibreMap,
} from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import StationCard from '@/components/stations/StationCard.vue';
import { useAppearance } from '@/composables/useAppearance';
import { iconImage } from '@/lib/icons';
import { PRICE_TIERS, badgeImage, loadImage } from '@/lib/stations';
import type { PriceTier } from '@/lib/stations';
import { MAP_THEMES, themedStyle } from '@/lib/map-theme';
import type { ShieldStyles } from '@/lib/map-theme';
import type {
    Station,
    StationAverages,
    StationComparison,
    StationCluster,
    StationFilters,
    StationKind,
} from '@/types/stations';

const props = defineProps<{
    filters: StationFilters;
    // The fuel whose price is shown beside a gas station on the map.
    labelFuel: string | null;
    fuelLabels: Record<string, string>;
    dataUrl: string;
    pricesUrl: string;
    logoUrl: string;
    shields: ShieldStyles;
    stationsFrom: number;
    livePricesFrom: number;
    // Where to open, rather than where the map was left.
    focus?: { latitude: number; longitude: number } | null;
}>();

export type StationMapState = {
    mode: 'clusters' | 'stations';
    loading: boolean;
    fetching: boolean;
    truncated: boolean;
    count: number;
    failed: boolean;
    // The gas stations in view with a price of the primary fuel: the colors compare those.
    priced: number;
};

const emit = defineEmits<{ state: [state: StationMapState] }>();

const { resolvedAppearance } = useAppearance();

// Free vector tiles, without a key: the base style, in the theme of the app.
const STYLE = 'https://tiles.openfreemap.org/styles/liberty';
// Where the map was last: it opens there again.
const VIEW_KEY = 'station-map:view';

const KINDS: Record<StationKind, { icon: string; color: string }> = {
    // Neutral: a gas station has the color of its price, see PRICE_TIERS.
    fuel: { icon: 'ic:round-local-gas-station', color: '#475569' },
    fast: { icon: 'mdi:ev-station', color: '#0891b2' },
    slow: { icon: 'mdi:ev-plug-type2', color: '#0d9488' },
};

const container = ref<HTMLElement>();
let map: MapLibreMap | undefined;
let request: AbortController | undefined;
let pricesRequest: AbortController | undefined;
let timer: ReturnType<typeof setTimeout> | undefined;
let retry: ReturnType<typeof setTimeout> | undefined;

const stations = shallowRef<Station[]>([]);
// The prices asked per area so far, by station: they stay while the map moves,
// rather than the badges going grey until they are asked again.
const livePrices = new Map<number, Station['prices']>();
// What they were asked for: other fuels, other prices.
let livePricesFor = '';
const clusters = shallowRef<StationCluster[]>([]);
const averages = shallowRef<StationAverages>({});
const comparison = shallowRef<StationComparison>(null);
const loadingPrices = ref(false);

const popupElement = shallowRef<HTMLElement | null>(null);
const popupStation = shallowRef<Station | null>(null);
let popup: maplibregl.Popup | undefined;

function applyStyle() {
    const theme =
        MAP_THEMES[resolvedAppearance.value === 'dark' ? 'dark' : 'light'];

    map?.setStyle(STYLE, {
        transformStyle: (previous, next) =>
            themedStyle(next, theme, props.shields),
    });
}

// The price of the primary fuel at a gas station; null for a charging station
// or without one. Without a primary fuel its cheapest.
function priceOf(station: Station): number | null {
    if (station.kind !== 'fuel') {
        return null;
    }

    const price = props.labelFuel
        ? station.prices.find((item) => item.fuel_type === props.labelFuel)
        : station.prices[0];

    return price?.price ?? null;
}

// In the badge of a gas station its price of the primary fuel; none without one.
function labelOf(price: number | null): string {
    return price === null ? '' : price.toFixed(3).replace('.', ',');
}

// Cheap, in between or expensive: by how many of the prices in view are lower.
function tierOf(price: number | null, prices: number[]): PriceTier {
    if (price === null) {
        return 'none';
    }

    if (prices.length < 2 || prices[0] === prices[prices.length - 1]) {
        return 'mid';
    }

    const rank =
        prices.filter((other) => other < price).length / (prices.length - 1);

    return rank <= 1 / 3 ? 'cheap' : rank >= 2 / 3 ? 'expensive' : 'mid';
}

function stationsData(): FeatureCollection {
    const priced = stations.value.map((station) => ({
        station,
        price: priceOf(station),
    }));
    const prices = priced
        .map((item) => item.price)
        .filter((price): price is number => price !== null)
        .sort((a, b) => a - b);

    return {
        type: 'FeatureCollection',
        features: priced.map(({ station, price }) => ({
            type: 'Feature',
            properties: {
                id: station.id,
                // A gas station without a price, or a charging station: a round icon of its kind.
                icon: station.kind === 'fuel' ? 'fuel-none' : station.kind,
                // A gas station with a price: a badge in the color of its price, with the logo
                // of its brand where that is known, and the price.
                badge: badgeName(
                    tierOf(price, prices),
                    station,
                    labelOf(price),
                ),
                label: labelOf(price),
                // The cheapest on top, where badges overlap.
                order: price === null ? 0 : -price,
            },
            geometry: {
                type: 'Point',
                coordinates: [station.longitude, station.latitude],
            },
        })),
    };
}

function clustersData(): FeatureCollection {
    return {
        type: 'FeatureCollection',
        features: clusters.value.map((cluster) => ({
            type: 'Feature',
            properties: {
                count: cluster.count,
                label:
                    cluster.count >= 1000
                        ? `${Math.round(cluster.count / 100) / 10}k`
                        : String(cluster.count),
                // The color of the kind most of them are.
                kind: (Object.entries(cluster.counts).sort(
                    (a, b) => (b[1] ?? 0) - (a[1] ?? 0),
                )[0]?.[0] ?? 'fuel') as StationKind,
            },
            geometry: {
                type: 'Point',
                coordinates: [cluster.longitude, cluster.latitude],
            },
        })),
    };
}

function setSource(id: string, data: FeatureCollection) {
    const source = map?.getSource(id) as GeoJSONSource | undefined;

    if (source) {
        source.setData(data);
    } else {
        map?.addSource(id, { type: 'geojson', data });
    }
}

function updateSources() {
    setSource('stations', stationsData());
    setSource('clusters', clustersData());
    emitState({
        priced: stations.value.filter((station) => priceOf(station) !== null)
            .length,
    });
}

async function addLayers() {
    if (!map) {
        return;
    }

    const images: [string, string, string][] = [
        ...Object.entries(KINDS).map(
            ([kind, { icon, color }]) =>
                [kind, icon, color] as [string, string, string],
        ),
        // A gas station without a price: grey.
        ['fuel-none', KINDS.fuel.icon, PRICE_TIERS.none.color],
    ];

    await Promise.all(
        images.map(([name, icon, color]) =>
            iconImage(icon, color).then((data) => {
                if (map && !map.hasImage(`station-${name}`)) {
                    map.addImage(`station-${name}`, data, { pixelRatio: 2 });
                }
            }),
        ),
    );

    updateSources();

    map.addLayer({
        id: 'clusters',
        type: 'circle',
        source: 'clusters',
        paint: {
            'circle-color': [
                'match',
                ['get', 'kind'],
                'fast',
                KINDS.fast.color,
                'slow',
                KINDS.slow.color,
                KINDS.fuel.color,
            ],
            'circle-opacity': 0.85,
            'circle-stroke-color': '#ffffff',
            'circle-stroke-width': 2,
            'circle-radius': [
                'interpolate',
                ['linear'],
                ['get', 'count'],
                1,
                12,
                100,
                18,
                1000,
                26,
                10000,
                34,
            ],
        },
    });
    map.addLayer({
        id: 'cluster-counts',
        type: 'symbol',
        source: 'clusters',
        layout: {
            'text-field': ['get', 'label'],
            'text-size': 12,
            'text-font': ['Noto Sans Bold'],
            'text-allow-overlap': true,
        },
        paint: { 'text-color': '#ffffff' },
    });
    // Without a price: a round icon of its kind.
    map.addLayer({
        id: 'stations',
        type: 'symbol',
        source: 'stations',
        filter: ['==', ['get', 'label'], ''],
        layout: {
            'icon-image': ['concat', 'station-', ['get', 'icon']],
            'icon-allow-overlap': true,
        },
    });
    // With a price: a badge with the price on it, in the color of how cheap it is.
    map.addLayer({
        id: 'stations-priced',
        type: 'symbol',
        source: 'stations',
        filter: ['!=', ['get', 'label'], ''],
        layout: {
            'icon-image': ['get', 'badge'],
            'icon-allow-overlap': true,
            // The cheapest on top, as a whole: the price is in the image.
            'symbol-sort-key': ['get', 'order'],
        },
    });
}

// The name of the image of a badge: what is on it. JSON, as a brand can have any character.
const BADGE = 'badge:';

function badgeName(tier: PriceTier, station: Station, label: string): string {
    return (
        BADGE +
        JSON.stringify([tier, station.brand ?? station.name ?? '', label])
    );
}

// The logos asked so far, by brand: null for one that is not known.
const logos = new Map<string, Promise<HTMLImageElement | null>>();
// The badges being made: the map asks for one until it is there.
const making = new Set<string>();
let refresh: ReturnType<typeof setTimeout> | undefined;

// A badge, made the first time the map needs it: with the logo of its brand,
// or without one (and without room for it) when the brand has none.
async function makeBadge(id: string) {
    if (making.has(id)) {
        return;
    }

    making.add(id);

    const [tier, brand, label] = JSON.parse(id.slice(BADGE.length)) as [
        PriceTier,
        string,
        string,
    ];

    if (brand && !logos.has(brand)) {
        logos.set(
            brand,
            loadImage(
                `${props.logoUrl}?brand=${encodeURIComponent(brand)}&known=1`,
            ),
        );
    }

    const badge = await badgeImage(
        PRICE_TIERS[tier]?.color ?? PRICE_TIERS.mid.color,
        KINDS.fuel.icon,
        brand ? await logos.get(brand)! : null,
        label,
    );

    if (map && !map.hasImage(id)) {
        map.addImage(id, badge.data, { pixelRatio: badge.pixelRatio });
    }

    // The badges again, now that the image is there: once for all that came in meanwhile.
    clearTimeout(refresh);
    refresh = setTimeout(() => setSource('stations', stationsData()), 50);
}

function addHandlers() {
    map!.on('styleimagemissing', (event: { id: string }) => {
        if (event.id.startsWith(BADGE)) {
            makeBadge(event.id);
        }
    });

    map!.on('click', 'clusters', (event: MapLayerMouseEvent) => {
        const feature = event.features?.[0];

        if (feature?.geometry.type === 'Point') {
            map!.easeTo({
                center: feature.geometry.coordinates as [number, number],
                zoom: Math.min(map!.getZoom() + 2, props.stationsFrom),
            });
        }
    });
    for (const layer of ['stations', 'stations-priced']) {
        map!.on('click', layer, (event: MapLayerMouseEvent) => {
            const id = event.features?.[0]?.properties?.id;
            const station = stations.value.find((item) => item.id === id);

            if (station) {
                openPopup(station);
            }
        });
    }

    for (const layer of ['clusters', 'stations', 'stations-priced']) {
        map!.on('mouseenter', layer, () => {
            map!.getCanvas().style.cursor = 'pointer';
        });
        map!.on('mouseleave', layer, () => {
            map!.getCanvas().style.cursor = '';
        });
    }

    map!.on('moveend', () => {
        rememberView();
        schedule();
    });
}

function openPopup(station: Station) {
    popup?.remove();

    const element = document.createElement('div');
    popupElement.value = element;
    popupStation.value = station;
    popup = new maplibregl.Popup({
        className: 'trip-popup',
        maxWidth: 'none',
        offset: 14,
    })
        .setLngLat([station.longitude, station.latitude])
        .setDOMContent(element)
        .addTo(map!);

    const opened = popup;
    opened.on('close', () => {
        if (popup === opened) {
            popupElement.value = null;
            popupStation.value = null;
        }
    });
}

function rememberView() {
    try {
        const center = map!.getCenter();
        localStorage.setItem(
            VIEW_KEY,
            JSON.stringify({
                lat: center.lat,
                lng: center.lng,
                zoom: map!.getZoom(),
            }),
        );
    } catch {
        // Without storage it opens at the start.
    }
}

function savedView(): { lat: number; lng: number; zoom: number } | null {
    try {
        const view = JSON.parse(localStorage.getItem(VIEW_KEY) ?? 'null');

        return typeof view?.lat === 'number' ? view : null;
    } catch {
        return null;
    }
}

function query(): URLSearchParams {
    const bounds = map!.getBounds();
    const params = new URLSearchParams({
        south: bounds.getSouth().toFixed(5),
        west: bounds.getWest().toFixed(5),
        north: bounds.getNorth().toFixed(5),
        east: bounds.getEast().toFixed(5),
        zoom: String(Math.floor(map!.getZoom())),
        priced: props.filters.priced ? '1' : '0',
    });

    props.filters.kinds.forEach((kind) => params.append('kinds[]', kind));
    props.filters.fuels.forEach((fuel) => params.append('fuels[]', fuel));
    props.filters.connectors.forEach((connector) =>
        params.append('connectors[]', connector),
    );

    return params;
}

async function getJson<T>(
    url: string,
    controller: AbortController,
): Promise<T> {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        signal: controller.signal,
    });

    if (!response.ok) {
        throw new Error(String(response.status));
    }

    return response.json();
}

// Not on every movement: once the map stands still for a moment.
function schedule() {
    clearTimeout(timer);
    timer = setTimeout(load, 250);
}

async function load() {
    if (!map) {
        return;
    }

    clearTimeout(retry);
    request?.abort();
    pricesRequest?.abort();
    request = new AbortController();
    const params = query();
    const pricesFor = JSON.stringify([props.filters.fuels, props.labelFuel]);

    // Other fuels: the prices asked so far are of the wrong ones.
    if (pricesFor !== livePricesFor) {
        livePrices.clear();
        livePricesFor = pricesFor;
    }

    emitState({ fetching: true });

    try {
        const data = await getJson<
            | { mode: 'clusters'; clusters: StationCluster[]; loading: boolean }
            | {
                  mode: 'stations';
                  stations: Station[];
                  truncated: boolean;
                  averages: StationAverages | [];
                  comparison?: StationComparison;
                  loading: boolean;
              }
        >(`${props.dataUrl}?${params}`, request);

        if (data.mode === 'clusters') {
            clusters.value = data.clusters;
            stations.value = [];
        } else {
            clusters.value = [];
            stations.value = data.stations.map((station) =>
                station.live && livePrices.has(station.id)
                    ? { ...station, prices: livePrices.get(station.id)! }
                    : station,
            );
            averages.value = Array.isArray(data.averages) ? {} : data.averages;
            comparison.value = data.comparison ?? null;
        }

        updateSources();
        emitState({
            mode: data.mode,
            loading: data.loading,
            fetching: false,
            truncated: data.mode === 'stations' && data.truncated,
            count:
                data.mode === 'stations'
                    ? data.stations.length
                    : data.clusters.reduce((sum, item) => sum + item.count, 0),
            failed: false,
        });

        // An area that is fetched in the background: in a while again.
        if (data.loading) {
            retry = setTimeout(load, 15000);
        }

        if (
            data.mode === 'stations' &&
            map.getZoom() >= props.livePricesFrom &&
            data.stations.some((station) => station.live)
        ) {
            await loadLivePrices(params);
        }
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            emitState({ fetching: false, failed: true });
        }
    }
}

// The prices of a source that is asked per area: after the stations, which show at once.
async function loadLivePrices(params: URLSearchParams) {
    pricesRequest = new AbortController();
    loadingPrices.value = true;
    const query = new URLSearchParams(params);

    // The price in the badges, whatever the fuels filtered on.
    if (props.labelFuel) {
        query.set('primary', props.labelFuel);
    }

    try {
        const data = await getJson<{
            prices: Record<string, Station['prices']>;
        }>(`${props.pricesUrl}?${query}`, pricesRequest);

        for (const [id, prices] of Object.entries(data.prices)) {
            livePrices.set(
                Number(id),
                [...prices].sort((a, b) => a.price - b.price),
            );
        }

        stations.value = stations.value.map((station) =>
            livePrices.has(station.id)
                ? { ...station, prices: livePrices.get(station.id)! }
                : station,
        );
        updateSources();

        if (popupStation.value) {
            popupStation.value =
                stations.value.find(
                    (station) => station.id === popupStation.value?.id,
                ) ?? popupStation.value;
        }
    } catch {
        // Without them the stations show without a price.
    } finally {
        loadingPrices.value = false;
    }
}

let state: StationMapState = {
    mode: 'clusters',
    loading: false,
    fetching: false,
    truncated: false,
    count: 0,
    failed: false,
    priced: 0,
};

function emitState(change: Partial<StationMapState>) {
    state = { ...state, ...change };
    emit('state', state);
}

onMounted(() => {
    const view = props.focus
        ? { lat: props.focus.latitude, lng: props.focus.longitude, zoom: 13 }
        : savedView();

    map = new maplibregl.Map({
        container: container.value!,
        center: view ? [view.lng, view.lat] : [5.3, 52.1],
        zoom: view?.zoom ?? 7,
        attributionControl: { compact: true },
        // The badges change with the data: without fading in and out, which flickers.
        fadeDuration: 0,
    });
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }));
    map.addControl(
        new maplibregl.GeolocateControl({
            positionOptions: { enableHighAccuracy: false },
            fitBoundsOptions: { maxZoom: 13 },
        }),
    );
    addHandlers();
    map.on('style.load', async () => {
        // Another style has none of the images made for the one before.
        making.clear();
        await addLayers();
        load();
    });
    applyStyle();
});

watch(() => props.filters, schedule, { deep: true });
watch(() => props.labelFuel, updateSources);
watch(resolvedAppearance, applyStyle);

onBeforeUnmount(() => {
    clearTimeout(timer);
    clearTimeout(refresh);
    clearTimeout(retry);
    request?.abort();
    pricesRequest?.abort();
    popup?.remove();
    map?.remove();
    map = undefined;
});
</script>

<template>
    <div ref="container" class="size-full min-h-80 text-foreground" />
    <Teleport v-if="popupElement && popupStation" :to="popupElement">
        <StationCard
            :station="popupStation"
            :averages="averages"
            :comparison="comparison"
            :fuel-labels="fuelLabels"
            :logo-url="logoUrl"
            :loading-prices="loadingPrices"
        />
    </Teleport>
</template>
