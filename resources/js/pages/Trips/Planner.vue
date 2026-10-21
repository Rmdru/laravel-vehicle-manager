<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import MapLayerToggles from '@/components/trips/MapLayerToggles.vue';
import PlanningProgress from '@/components/trips/PlanningProgress.vue';
import RouteCosts from '@/components/trips/RouteCosts.vue';
import RouteList from '@/components/trips/RouteList.vue';
import StopList from '@/components/trips/StopList.vue';
import TrafficList from '@/components/trips/TrafficList.vue';
import TravelTips from '@/components/trips/TravelTips.vue';
import TripForm from '@/components/trips/TripForm.vue';
import TripMap from '@/components/trips/TripMap.vue';
import TripNoticeList from '@/components/trips/TripNoticeList.vue';
import { Button } from '@/components/ui/button';
import { useT } from '@/composables/useT';
import { POI_GROUPS, TRAFFIC_GROUPS, trafficOf } from '@/lib/trips';
import type { ShieldStyles } from '@/lib/map-theme';
import type {
    Poi,
    TripFormData,
    TripPlan,
    TripProgress,
    TripTips,
} from '@/types/trips';

const props = defineProps<{
    form: TripFormData;
    trip: {
        id: string;
        name: string;
        status: 'planning' | 'routed' | 'planned' | 'failed';
        progress: TripProgress | null;
        error: string | null;
        selectedRoute: number;
        plan: TripPlan | null;
        // When the fuel prices and the traffic were last asked for.
        plannedAt: string | null;
        updateUrl: string;
        destroyUrl: string;
        selectRouteUrl: string;
        refreshUrl: string;
        pinned: number[];
        excluded: number;
        tips: TripTips | null;
        stopsUrl: string;
        tipsUrl: string;
    } | null;
    pois: Poi[];
    // The vehicle the trip is for: the one chosen at the top left for a new trip.
    vehicle: string | null;
    vehicleId: string | null;
    shields: ShieldStyles;
    storeUrl: string;
    indexUrl: string;
    geocodeUrl: string;
    reverseGeocodeUrl: string;
    logoUrl: string;
    timezone: string;
}>();

const { t } = useT();

const form = useForm<TripFormData>({ ...props.form });

const selected = ref(props.trip?.selectedRoute ?? 0);
const routes = computed(() =>
    props.trip && ['routed', 'planned'].includes(props.trip.status)
        ? (props.trip.plan?.routes ?? [])
        : [],
);
const route = computed(() => routes.value[selected.value]);
// The routes are known, the stops along them not yet.
const loadingStops = computed(() => props.trip?.status === 'routed');
const planning = computed(
    () => props.trip?.status === 'planning' || props.trip?.status === 'routed',
);
// For an electric vehicle the time of the stops matters: charging takes long.
const electric = computed(() => !!props.trip?.plan?.vehicle.electric);

watch(
    () => props.trip?.selectedRoute,
    (value) => (selected.value = value ?? 0),
);

const layers = ref<Record<string, boolean>>({
    ...Object.fromEntries(
        Object.keys(POI_GROUPS).map((group) => [group, true]),
    ),
    ...Object.fromEntries(
        Object.keys(TRAFFIC_GROUPS).map((group) => [`traffic:${group}`, true]),
    ),
});

// The traffic on the route: incidents, and where it is usually busy.
const traffic = computed(() => trafficOf(route.value));

// The kinds of traffic on the route, each to be shown or hidden on the map.
const trafficGroups = computed(() =>
    Object.entries(TRAFFIC_GROUPS)
        .map(([key, group]) => ({
            key: `traffic:${key}`,
            ...group,
            count: traffic.value.filter((incident) =>
                group.categories.includes(incident.category),
            ).length,
        }))
        .filter((group) => group.count > 0),
);

// The driver chooses to stop at a place, or not to: planned again around it.
function chooseStop(
    action: 'pin' | 'unpin' | 'exclude' | 'reset',
    poi?: number,
) {
    if (!props.trip) {
        return;
    }

    router.patch(
        props.trip.stopsUrl,
        { action, poi },
        { preserveScroll: true, preserveState: true, only: ['trip', 'pois'] },
    );
}

const generatingTips = ref(false);

function generateTips() {
    if (!props.trip) {
        return;
    }

    router.post(
        props.trip.tipsUrl,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['trip'],
            onStart: () => (generatingTips.value = true),
            onFinish: () => (generatingTips.value = false),
        },
    );
}

const refreshing = ref(false);

// The trip planned again as it is, with the fuel prices and the traffic of now.
function refresh() {
    if (!props.trip) {
        return;
    }

    router.post(
        props.trip.refreshUrl,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['trip', 'pois'],
            onStart: () => (refreshing.value = true),
            onFinish: () => (refreshing.value = false),
        },
    );
}

// Whether the surroundings are shown is remembered per vehicle, in this browser.
const surroundingsKey = computed(() =>
    props.vehicleId ? `trip-planner:surroundings:${props.vehicleId}` : null,
);

function storedSurroundings(): boolean {
    try {
        return surroundingsKey.value
            ? localStorage.getItem(surroundingsKey.value) === '1'
            : false;
    } catch {
        return false;
    }
}

const surroundings = ref(storedSurroundings());

watch(surroundings, (shown) => {
    try {
        if (surroundingsKey.value) {
            localStorage.setItem(surroundingsKey.value, shown ? '1' : '0');
        }
    } catch {
        // Without storage it is not remembered.
    }
});

watch(surroundingsKey, () => (surroundings.value = storedSurroundings()));

const map = ref<InstanceType<typeof TripMap>>();

function submit() {
    if (props.trip) {
        form.put(props.trip.updateUrl, { preserveScroll: true });
    } else {
        form.post(props.storeUrl);
    }
}

function selectRoute(index: number) {
    selected.value = index;

    if (props.trip && index !== props.trip.selectedRoute) {
        router.patch(
            props.trip.selectRouteUrl,
            { route: index },
            { preserveScroll: true, preserveState: true, only: ['trip'] },
        );
    }
}

// Planning takes a while: check until it is done.
let polling: ReturnType<typeof setInterval> | undefined;

const deleting = ref(false);

function destroy() {
    if (!props.trip) {
        return;
    }

    // A check of a trip that is gone would not be found.
    clearInterval(polling);
    router.delete(props.trip.destroyUrl, {
        onStart: () => (deleting.value = true),
        onFinish: () => (deleting.value = false),
    });
}

watch(
    () => props.trip?.status,
    (status) => {
        clearInterval(polling);

        if (status === 'planning' || status === 'routed') {
            polling = setInterval(
                () => router.reload({ only: ['trip', 'pois'] }),
                2000,
            );
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => clearInterval(polling));
</script>

<template>
    <Head :title="trip ? trip.name : t('Plan a trip')" />

    <div
        class="flex flex-1 flex-col lg:h-[calc(100svh-3.5rem)] lg:flex-none lg:flex-row"
    >
        <div
            class="relative flex w-full flex-col gap-4 p-4 *:shrink-0 lg:w-110 lg:shrink-0 lg:overflow-y-auto"
        >
            <div class="flex items-center gap-2">
                <h1 class="flex-1 text-2xl font-bold tracking-tight">
                    {{ trip ? trip.name : t('Plan a trip') }}
                </h1>
                <Button variant="ghost" size="sm" as-child>
                    <Link :href="indexUrl">{{ t('My trips') }}</Link>
                </Button>
            </div>

            <TripForm
                :form="form"
                :trip-name="trip?.name ?? null"
                :vehicle="vehicle"
                :geocode-url="geocodeUrl"
                :reverse-geocode-url="reverseGeocodeUrl"
                :timezone="timezone"
                :deleting="deleting"
                @submit="submit"
                @destroy="destroy"
            />

            <PlanningProgress
                v-if="planning"
                :progress="trip?.progress ?? null"
                :routed="trip?.status === 'routed'"
            />

            <div
                v-else-if="trip?.status === 'failed'"
                class="rounded-xl border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive"
            >
                {{ trip.error }}
            </div>

            <template v-if="routes.length">
                <RouteList
                    :routes="routes"
                    :selected="selected"
                    :loading-stops="loadingStops"
                    :electric="electric"
                    :timezone="timezone"
                    :planned-at="trip?.plannedAt ?? null"
                    :can-refresh="trip?.status === 'planned'"
                    :refreshing="refreshing"
                    @select="selectRoute"
                    @refresh="refresh"
                />

                <StopList
                    v-if="route"
                    :route="route"
                    :start="form.waypoints[0]?.label"
                    :destination="
                        form.waypoints[form.waypoints.length - 1]?.label
                    "
                    :excluded="trip?.excluded ?? 0"
                    :electric="electric"
                    :vehicle="trip?.plan?.vehicle"
                    :logo-url="logoUrl"
                    :timezone="timezone"
                    @focus="(stop) => map?.showStop(stop)"
                    @reset="chooseStop('reset')"
                />

                <RouteCosts
                    v-if="route?.costs"
                    :costs="route.costs"
                    :toll-distance="route.toll_distance"
                    :electric="electric"
                    :loading-stops="loadingStops"
                    :timezone="timezone"
                />

                <TripNoticeList
                    v-if="route?.notices?.length"
                    :notices="route.notices"
                />

                <TrafficList
                    v-if="route"
                    :traffic="traffic"
                    :skipped="!!trip?.plan?.traffic_skipped"
                    :timezone="timezone"
                    @show="
                        (incident) =>
                            map?.flyTo(incident.latitude, incident.longitude)
                    "
                />
            </template>

            <TravelTips
                v-if="trip && (trip.status === 'planned' || trip.tips)"
                :tips="trip.tips"
                :can-generate="trip.status === 'planned'"
                :generating="generatingTips"
                @generate="generateTips"
            />
        </div>

        <div
            class="relative h-[70svh] w-full overflow-hidden border-t lg:h-auto lg:flex-1 lg:border-t-0 lg:border-l"
        >
            <TripMap
                ref="map"
                :routes="routes"
                :selected="selected"
                :pois="pois"
                :waypoints="form.waypoints"
                :layers="layers"
                :surroundings="surroundings"
                :shields="shields"
                :logo-url="logoUrl"
                :electric="electric"
                :can-choose="trip?.status === 'planned'"
                :timezone="timezone"
                @select="selectRoute"
                @choose="chooseStop"
            />
            <MapLayerToggles
                v-if="routes.length"
                v-model:layers="layers"
                v-model:surroundings="surroundings"
                :traffic-groups="trafficGroups"
                :loading-stops="loadingStops"
            />
        </div>
    </div>
</template>
