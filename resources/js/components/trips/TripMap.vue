<script setup lang="ts">
import type { FeatureCollection } from 'geojson';
import maplibregl from 'maplibre-gl';
import type { GeoJSONSource, Map as MapLibreMap } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    shallowRef,
    watch,
} from 'vue';
import PoiCard from '@/components/trips/PoiCard.vue';
import TrafficCard from '@/components/trips/TrafficCard.vue';
import { useAppearance } from '@/composables/useAppearance';
import { iconImage, iconMarkup } from '@/lib/icons';
import { MAP_THEMES, themedStyle } from '@/lib/map-theme';
import type { ShieldStyles } from '@/lib/map-theme';
import { decodePolyline } from '@/lib/polyline';
import {
    groupOf,
    needIcon,
    POI_GROUPS,
    TRAFFIC,
    TRAFFIC_GROUPS,
    trafficOf,
} from '@/lib/trips';
import type {
    Place,
    Poi,
    TrafficIncident,
    TripCandidate,
    TripRoute,
    TripStop,
} from '@/types/trips';

const props = defineProps<{
    routes: TripRoute[];
    selected: number;
    pois: Poi[];
    waypoints: (Place | null)[];
    // Which groups of places are shown, by group.
    layers: Record<string, boolean>;
    // Whether the places near the route that are no option for a stop are shown too.
    surroundings: boolean;
    // The road numbers as the road signs of each country show them.
    shields: ShieldStyles;
    logoUrl: string;
    // Whether the vehicle is charged rather than refueled.
    electric: boolean;
    // Whether the driver can choose where to stop: once the trip is planned.
    canChoose: boolean;
    timezone: string;
}>();

const emit = defineEmits<{
    select: [index: number];
    // The driver chooses to stop at a place, or not to.
    choose: [action: 'pin' | 'unpin' | 'exclude', poi: number];
}>();

const { resolvedAppearance } = useAppearance();

// Free vector tiles, without a key: the base style, in the theme of the app.
const STYLE = 'https://tiles.openfreemap.org/styles/liberty';

function applyStyle() {
    const theme =
        MAP_THEMES[resolvedAppearance.value === 'dark' ? 'dark' : 'light'];

    map?.setStyle(STYLE, {
        transformStyle: (previous, next) =>
            themedStyle(next, theme, props.shields),
    });
}

const container = ref<HTMLElement>();
let map: MapLibreMap | undefined;
let markers: maplibregl.Marker[] = [];
let fitted = '';

const route = computed(() => props.routes[props.selected]);
// The traffic on the route, as the list on the page has it: the index of a marker points into it.
const incidents = computed(() => trafficOf(route.value));
const poisById = computed(
    () => new Map(props.pois.map((poi) => [poi.id, poi])),
);
const candidates = computed(
    () =>
        new Map(
            (route.value?.candidates ?? []).map((candidate) => [
                candidate.id,
                candidate,
            ]),
        ),
);

// The place the popup is open for.
const popupElement = shallowRef<HTMLElement | null>(null);
const popupPoi = shallowRef<Poi | null>(null);
const popupIncident = shallowRef<TrafficIncident | null>(null);
let popup: maplibregl.Popup | undefined;

function routeData(): FeatureCollection {
    return {
        type: 'FeatureCollection',
        features: props.routes.map((item, index) => ({
            type: 'Feature',
            properties: { index, selected: index === props.selected },
            geometry: {
                type: 'LineString',
                coordinates: decodePolyline(item.geometry),
            },
        })),
    };
}

function pointsOf(ids: number[]): FeatureCollection {
    return {
        type: 'FeatureCollection',
        features: ids
            .map((id) => poisById.value.get(id))
            .filter((poi): poi is Poi => !!poi && !!groupOf(poi.kind))
            .map((poi) => ({
                type: 'Feature',
                properties: { id: poi.id, group: groupOf(poi.kind) },
                geometry: {
                    type: 'Point',
                    coordinates: [poi.longitude, poi.latitude],
                },
            })),
    };
}

// The places a stop could be made at, and the toll booths passed through;
// the stops themselves have markers of their own.
function placesData(): FeatureCollection {
    const stops = new Set(
        (route.value?.stops ?? []).map((stop) => stop.poi_id),
    );

    return pointsOf(
        [
            ...new Set([
                ...candidates.value.keys(),
                ...(route.value?.toll_booths ?? []),
            ]),
        ].filter((id) => !stops.has(id)),
    );
}

// Everything else near the route.
function surroundingsData(): FeatureCollection {
    const shown = new Set([
        ...candidates.value.keys(),
        ...(route.value?.toll_booths ?? []),
    ]);

    return pointsOf((route.value?.poiIds ?? []).filter((id) => !shown.has(id)));
}

// Where the toll roads begin and end.
function tollData(): FeatureCollection {
    return {
        type: 'FeatureCollection',
        features: (route.value?.toll_points ?? []).map((point) => ({
            type: 'Feature',
            properties: { type: point.type },
            geometry: {
                type: 'Point',
                coordinates: [point.longitude, point.latitude],
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

// The road works, closures and jams on the route.
function trafficData(): FeatureCollection {
    return {
        type: 'FeatureCollection',
        features: incidents.value.map((incident, index) => ({
            type: 'Feature',
            properties: { index, category: incident.category },
            geometry: {
                type: 'Point',
                coordinates: [incident.longitude, incident.latitude],
            },
        })),
    };
}

// The stretches of road with road works or closed, as lines.
function trafficLinesData(): FeatureCollection {
    return {
        type: 'FeatureCollection',
        features: incidents.value.flatMap((incident, index) =>
            incident.line && incident.line.length > 1
                ? [
                      {
                          type: 'Feature' as const,
                          properties: { index, category: incident.category },
                          geometry: {
                              type: 'LineString' as const,
                              coordinates: incident.line,
                          },
                      },
                  ]
                : [],
        ),
    };
}

// Where it is usually busy, as lines along the route.
function congestionData(): FeatureCollection {
    return {
        type: 'FeatureCollection',
        features: (route.value?.congestion ?? []).map((stretch) => ({
            type: 'Feature',
            properties: {
                category:
                    stretch.level === 'jam' ? 'typical_jam' : 'typical_busy',
            },
            geometry: {
                type: 'LineString',
                coordinates: decodePolyline(stretch.geometry),
            },
        })),
    };
}

function updateSources() {
    setSource('traffic', trafficData());
    setSource('congestion', congestionData());
    setSource('traffic-lines', trafficLinesData());
    setSource('routes', routeData());
    setSource('places', placesData());
    setSource('surroundings', surroundingsData());
    setSource('toll-points', tollData());
}

async function addImages() {
    const add = (name: string, data: ImageData) => {
        if (map && !map.hasImage(name)) {
            map.addImage(name, data, { pixelRatio: 2 });
        }
    };

    await Promise.all([
        ...Object.entries(POI_GROUPS).flatMap(([group, { icon, color }]) => [
            iconImage(icon, color).then((data) => add(`poi-${group}`, data)),
            iconImage(icon, '#94a3b8', 20).then((data) =>
                add(`poi-${group}-muted`, data),
            ),
        ]),
        iconImage('mdi:boom-gate', '#d97706').then((data) =>
            add('toll-start', data),
        ),
        iconImage('mdi:boom-gate-up', '#475569').then((data) =>
            add('toll-end', data),
        ),
        ...Object.entries(TRAFFIC).map(([category, { icon, color }]) =>
            iconImage(icon, color).then((data) =>
                add(`traffic-${category}`, data),
            ),
        ),
    ]);
}

// The sources and layers; again after every change of style.
async function addLayers() {
    if (!map) {
        return;
    }

    await addImages();
    updateSources();

    // A white casing sets the routes apart from the roads of the map.
    map.addLayer({
        id: 'routes-casing',
        type: 'line',
        source: 'routes',
        layout: { 'line-join': 'round', 'line-cap': 'round' },
        paint: { 'line-color': '#ffffff', 'line-width': 9 },
    });
    map.addLayer({
        id: 'routes-alternative',
        type: 'line',
        source: 'routes',
        filter: ['==', ['get', 'selected'], false],
        layout: { 'line-join': 'round', 'line-cap': 'round' },
        paint: { 'line-color': '#64748b', 'line-width': 5 },
    });
    map.addLayer({
        id: 'routes-selected',
        type: 'line',
        source: 'routes',
        filter: ['==', ['get', 'selected'], true],
        layout: { 'line-join': 'round', 'line-cap': 'round' },
        paint: { 'line-color': '#2563eb', 'line-width': 6 },
    });
    // Where it is usually busy: orange, or red for a jam, over the route.
    map.addLayer({
        id: 'congestion',
        type: 'line',
        source: 'congestion',
        layout: { 'line-join': 'round', 'line-cap': 'round' },
        paint: {
            'line-color': [
                'match',
                ['get', 'category'],
                'typical_jam',
                TRAFFIC.typical_jam.color,
                TRAFFIC.typical_busy.color,
            ],
            'line-width': ['interpolate', ['linear'], ['zoom'], 6, 4, 12, 7],
        },
    });
    // Road works in orange and closures in red, over the routes.
    map.addLayer({
        id: 'traffic-lines',
        type: 'line',
        source: 'traffic-lines',
        layout: { 'line-join': 'round', 'line-cap': 'round' },
        paint: {
            'line-color': [
                'match',
                ['get', 'category'],
                'closed',
                TRAFFIC.closed.color,
                TRAFFIC.road_works.color,
            ],
            'line-width': ['interpolate', ['linear'], ['zoom'], 6, 3, 12, 6],
        },
    });

    for (const group of Object.keys(POI_GROUPS)) {
        map.addLayer({
            id: `surroundings-${group}`,
            type: 'symbol',
            source: 'surroundings',
            filter: ['==', ['get', 'group'], group],
            minzoom: 9,
            layout: {
                'icon-image': `poi-${group}-muted`,
                'icon-allow-overlap': true,
            },
        });
        map.addLayer({
            id: `places-${group}`,
            type: 'symbol',
            source: 'places',
            filter: ['==', ['get', 'group'], group],
            layout: {
                'icon-image': `poi-${group}`,
                'icon-size': [
                    'interpolate',
                    ['linear'],
                    ['zoom'],
                    6,
                    0.6,
                    11,
                    1,
                ],
                'icon-allow-overlap': true,
            },
        });
    }

    map.addLayer({
        id: 'toll-points',
        type: 'symbol',
        source: 'toll-points',
        layout: {
            'icon-image': ['concat', 'toll-', ['get', 'type']],
            'icon-size': ['interpolate', ['linear'], ['zoom'], 6, 0.6, 11, 1],
            'icon-allow-overlap': true,
        },
    });
    map.addLayer({
        id: 'traffic',
        type: 'symbol',
        source: 'traffic',
        layout: {
            'icon-image': ['concat', 'traffic-', ['get', 'category']],
            'icon-size': ['interpolate', ['linear'], ['zoom'], 6, 0.7, 11, 1],
            'icon-allow-overlap': true,
        },
    });

    applyLayers();
}

// The handlers of clicks on the layers: once, they outlast a change of style.
function addHandlers() {
    if (!map) {
        return;
    }

    for (const group of Object.keys(POI_GROUPS)) {
        for (const layer of [`places-${group}`, `surroundings-${group}`]) {
            map.on('click', layer, (event) => {
                const poi = poisById.value.get(
                    Number(event.features?.[0]?.properties?.id),
                );

                if (poi) {
                    openPopup(poi);
                }
            });
            map.on(
                'mouseenter',
                layer,
                () => (map!.getCanvas().style.cursor = 'pointer'),
            );
            map.on(
                'mouseleave',
                layer,
                () => (map!.getCanvas().style.cursor = ''),
            );
        }
    }

    map.on('click', 'traffic-lines', (event) => {
        const incident =
            incidents.value[Number(event.features?.[0]?.properties?.index)];

        if (incident) {
            openIncident(incident);
        }
    });
    map.on('click', 'traffic', (event) => {
        const incident =
            incidents.value[Number(event.features?.[0]?.properties?.index)];

        if (incident) {
            openIncident(incident);
        }
    });
    map.on(
        'mouseenter',
        'traffic',
        () => (map!.getCanvas().style.cursor = 'pointer'),
    );
    map.on('mouseleave', 'traffic', () => (map!.getCanvas().style.cursor = ''));

    map.on('click', 'routes-alternative', (event) => {
        const index = event.features?.[0]?.properties?.index;
        // A click on a place or on traffic along the route opens that, and keeps the route.
        const clickable = [
            ...Object.keys(POI_GROUPS).flatMap((group) => [
                `places-${group}`,
                `surroundings-${group}`,
            ]),
            'traffic',
            'traffic-lines',
        ].filter((layer) => map!.getLayer(layer));

        if (
            map!.queryRenderedFeatures(event.point, { layers: clickable })
                .length
        ) {
            return;
        }

        if (index !== undefined) {
            emit('select', Number(index));
        }
    });
}

function openPopup(poi: Poi) {
    showPopup(poi.latitude, poi.longitude);
    popupPoi.value = poi;
}

function openIncident(incident: TrafficIncident) {
    showPopup(incident.latitude, incident.longitude);
    popupIncident.value = incident;
}

function showPopup(latitude: number, longitude: number) {
    popup?.remove();

    const element = document.createElement('div');
    popupElement.value = element;
    popupPoi.value = null;
    popupIncident.value = null;

    popup = new maplibregl.Popup({
        offset: 16,
        maxWidth: '320px',
        className: 'trip-popup',
    })
        .setLngLat([longitude, latitude])
        .setDOMContent(element)
        .addTo(map!);

    const opened = popup;
    opened.on('close', () => {
        if (popup === opened) {
            popupElement.value = null;
            popupPoi.value = null;
            popupIncident.value = null;
        }
    });
}

function choose(action: 'pin' | 'unpin' | 'exclude', poi: number) {
    popup?.remove();
    emit('choose', action, poi);
}

// Shown by default are the stops (markers of their own) and the toll booths
// on the route; the other places a stop could be made at, and everything else
// nearby, only with the surroundings.
function applyLayers() {
    for (const group of Object.keys(POI_GROUPS)) {
        if (map?.getLayer(`places-${group}`)) {
            const onRoute = group === 'toll_booths';

            map.setLayoutProperty(
                `places-${group}`,
                'visibility',
                props.layers[group] && (onRoute || props.surroundings)
                    ? 'visible'
                    : 'none',
            );
            map.setLayoutProperty(
                `surroundings-${group}`,
                'visibility',
                props.layers[group] && props.surroundings ? 'visible' : 'none',
            );
        }
    }

    if (map?.getLayer('traffic')) {
        const shown = Object.entries(TRAFFIC_GROUPS)
            .filter(([group]) => props.layers[`traffic:${group}`])
            .flatMap(([, group]) => group.categories);

        for (const layer of ['traffic', 'traffic-lines', 'congestion']) {
            map.setFilter(layer, [
                'in',
                ['get', 'category'],
                ['literal', shown],
            ]);
        }
    }

    if (map?.getLayer('toll-points')) {
        map.setLayoutProperty(
            'toll-points',
            'visibility',
            props.layers.toll_booths ? 'visible' : 'none',
        );
    }
}

function marker(lngLat: [number, number], element: HTMLElement) {
    return new maplibregl.Marker({ element }).setLngLat(lngLat).addTo(map!);
}

function badge(label: string, className: string, title: string) {
    const element = document.createElement('div');
    element.className = `flex size-7 items-center justify-center rounded-full border-2 border-white text-xs font-bold text-white shadow ${className}`;
    element.textContent = label;
    element.title = title;

    return element;
}

function updateMarkers() {
    markers.forEach((item) => item.remove());
    markers = [];

    if (!map) {
        return;
    }

    const places = props.waypoints.filter(
        (place): place is Place & { latitude: number; longitude: number } =>
            place?.latitude != null && place.longitude != null,
    );

    places.forEach((place, index) => {
        const last = index === places.length - 1 && index > 0;
        markers.push(
            marker(
                [place.longitude, place.latitude],
                badge(
                    index === 0 ? 'A' : last ? 'B' : String(index),
                    last ? 'bg-emerald-600' : 'bg-slate-900',
                    place.label,
                ),
            ),
        );
    });

    // A stop shows what happens there.
    for (const stop of route.value?.stops ?? []) {
        if (
            stop.type !== 'stop' ||
            stop.latitude == null ||
            stop.longitude == null
        ) {
            continue;
        }

        const element = document.createElement('button');
        element.type = 'button';
        element.className =
            'flex items-center gap-1 rounded-full border-2 border-white bg-blue-600 px-1.5 py-1 text-white shadow-md';
        element.title = stop.name ?? '';
        element.innerHTML = stop.needs
            .map((need) =>
                iconMarkup(needIcon(need, props.electric), '#ffffff', 14),
            )
            .join('');

        element.addEventListener('click', (event) => {
            event.stopPropagation();
            openStop(stop);
        });

        markers.push(marker([stop.longitude, stop.latitude], element));
    }
}

function fit() {
    const key = props.routes.map((item) => item.geometry.length).join(':');
    const coordinates = props.routes.length
        ? props.routes.flatMap((item) => decodePolyline(item.geometry))
        : props.waypoints
              .filter((place) => place?.latitude != null)
              .map(
                  (place) =>
                      [place!.longitude!, place!.latitude!] as [number, number],
              );

    if (!map || !coordinates.length || key === fitted) {
        return;
    }

    fitted = key;
    // The container may have changed size since the map was created.
    map.resize();

    const bounds = coordinates.reduce(
        (box, point) => box.extend(point),
        new maplibregl.LngLatBounds(coordinates[0], coordinates[0]),
    );

    map.fitBounds(bounds, { padding: 48, maxZoom: 12, duration: 0 });
}

function flyTo(latitude: number, longitude: number) {
    map?.flyTo({ center: [longitude, latitude], zoom: 13 });
}

// The place of a stop: as it is known, or else from what the stop tells of it.
function poiOf(stop: TripStop): Poi | undefined {
    if (stop.latitude == null || stop.longitude == null) {
        return undefined;
    }

    return (
        (stop.poi_id ? poisById.value.get(stop.poi_id) : undefined) ?? {
            id: stop.poi_id ?? -1,
            kind: stop.kind ?? 'service_area',
            name: stop.name,
            brand: stop.brand ?? null,
            latitude: stop.latitude,
            longitude: stop.longitude,
            features: stop.features ?? [],
            services: [],
            details: {},
        }
    );
}

function openStop(stop: TripStop) {
    const poi = poiOf(stop);

    if (poi) {
        openPopup(poi);
    }
}

// Goes to a stop, and shows what is known of it.
function showStop(stop: TripStop) {
    if (stop.latitude != null && stop.longitude != null) {
        flyTo(stop.latitude, stop.longitude);
        openStop(stop);
    }
}

defineExpose({ flyTo, showStop });

onMounted(() => {
    map = new maplibregl.Map({
        container: container.value!,
        center: [5.3, 52.1],
        zoom: 6,
        attributionControl: { compact: true },
    });
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }));
    addHandlers();
    map.on('style.load', async () => {
        await addLayers();
        fit();
    });
    applyStyle();
    updateMarkers();
});

watch(
    () => [props.routes, props.selected, props.pois],
    () => {
        if (map?.getSource('routes')) {
            updateSources();
            fit();
        }
    },
);

watch(
    () => [props.routes, props.selected, props.waypoints, props.pois],
    updateMarkers,
    { deep: true },
);
watch(() => [props.layers, props.surroundings], applyLayers, { deep: true });

watch(resolvedAppearance, applyStyle);

onBeforeUnmount(() => {
    popup?.remove();
    map?.remove();
    map = undefined;
});

const popupCandidate = computed<TripCandidate | undefined>(() =>
    popupPoi.value ? candidates.value.get(popupPoi.value.id) : undefined,
);
const popupPrice = computed(() =>
    popupPoi.value ? route.value?.prices?.[popupPoi.value.id] : undefined,
);
const popupStop = computed(() =>
    (route.value?.stops ?? []).find(
        (stop) => stop.poi_id === popupPoi.value?.id,
    ),
);
</script>

<template>
    <div ref="container" class="size-full min-h-80 text-foreground" />
    <Teleport v-if="popupElement && popupPoi" :to="popupElement">
        <PoiCard
            :logo-url="logoUrl"
            :poi="popupPoi"
            :candidate="popupCandidate"
            :price="popupPrice"
            :stop="popupStop"
            :electric="electric"
            :can-choose="canChoose"
            @choose="choose"
            :gas-station="
                popupCandidate?.gas_station_id
                    ? poisById.get(popupCandidate.gas_station_id)
                    : undefined
            "
        />
    </Teleport>
    <Teleport v-if="popupElement && popupIncident" :to="popupElement">
        <TrafficCard :incident="popupIncident" :timezone="timezone" />
    </Teleport>
</template>

<style>
.trip-popup .maplibregl-popup-content {
    padding: 0;
    font-family: var(--font-sans);
    border-radius: 0.75rem;
    background: var(--card);
    color: var(--card-foreground);
    box-shadow: 0 10px 25px -5px rgb(0 0 0 / 0.25);
    overflow: hidden;
}

.trip-popup .maplibregl-popup-close-button {
    top: 6px;
    right: 6px;
    color: var(--muted-foreground);
    font-size: 1.25rem;
}

.trip-popup.maplibregl-popup-anchor-bottom .maplibregl-popup-tip {
    border-top-color: var(--card);
}

.trip-popup.maplibregl-popup-anchor-top .maplibregl-popup-tip {
    border-bottom-color: var(--card);
}

.trip-popup.maplibregl-popup-anchor-left .maplibregl-popup-tip {
    border-right-color: var(--card);
}

.trip-popup.maplibregl-popup-anchor-right .maplibregl-popup-tip {
    border-left-color: var(--card);
}
</style>
