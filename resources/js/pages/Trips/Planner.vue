<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import CountryFlag from '@/components/CountryFlag.vue';
import SectionCard from '@/components/SectionCard.vue';
import PlaceInput from '@/components/trips/PlaceInput.vue';
import RoadBadge from '@/components/trips/RoadBadge.vue';
import StopLogo from '@/components/trips/StopLogo.vue';
import TripMap from '@/components/trips/TripMap.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/composables/useT';
import {
    FEATURE_ICONS,
    FEATURE_LABELS,
    KIND_LABELS,
    needIcon,
    needLabel,
    POI_GROUPS,
    TIP_ICONS,
    TRAFFIC,
    TRAFFIC_GROUPS,
    trafficOf,
} from '@/lib/trips';
import type { ShieldStyles } from '@/lib/map-theme';
import type {
    Place,
    Poi,
    TripPlan,
    TripRoute,
    TripStop,
    TripTips,
} from '@/types/trips';

type TripForm = {
    name: string;
    timeMode: 'departure' | 'arrival';
    timeAt: string;
    fuelLevel: number;
    waypoints: (Place | null)[];
};

const props = defineProps<{
    form: TripForm;
    trip: {
        id: string;
        name: string;
        status: 'planning' | 'routed' | 'planned' | 'failed';
        progress: number | null;
        error: string | null;
        selectedRoute: number;
        plan: TripPlan | null;
        updateUrl: string;
        destroyUrl: string;
        selectRouteUrl: string;
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
const page = usePage();

const form = useForm<TripForm>({ ...props.form });

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
// The part of the planning that is done, in percent.
const progress = computed(() => Math.round((props.trip?.progress ?? 0) * 100));
// For an electric vehicle the time of the stops matters: charging takes long.
const electric = computed(() => !!props.trip?.plan?.vehicle.electric);

function stopCount(item: TripRoute): number {
    return (item.stops ?? []).filter((stop) => stop.type === 'stop').length;
}

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

function trafficTime(iso: string | null): string {
    return iso ? `${day(iso)} ${clock(iso)}` : '';
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

// The errors of the waypoints are keyed by their position.
function waypointError(index: number): boolean {
    const errors = form.errors as Record<string, string | undefined>;

    return !!(
        errors[`waypoints.${index}`] ?? errors[`waypoints.${index}.latitude`]
    );
}

// The current time, in the time zone the times are entered in.
function setNow() {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-GB', {
            timeZone: props.timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        })
            .formatToParts(new Date())
            .map((part) => [part.type, part.value]),
    );

    form.timeAt = `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

function addWaypoint() {
    form.waypoints.splice(form.waypoints.length - 1, 0, null);
}

function removeWaypoint(index: number) {
    form.waypoints.splice(index, 1);
}

function reverse() {
    form.waypoints.reverse();
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

const locale = computed(() => String(page.props.locale ?? 'en'));

function clock(iso: string): string {
    return new Intl.DateTimeFormat(locale.value, {
        timeZone: props.timezone,
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));
}

function day(iso: string): string {
    return new Intl.DateTimeFormat(locale.value, {
        timeZone: props.timezone,
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    }).format(new Date(iso));
}

// A time, with the day when it is not the day of departure.
function moment(iso: string): string {
    return route.value && day(iso) !== day(route.value.departure_at)
        ? `${day(iso)} ${clock(iso)}`
        : clock(iso);
}

function duration(seconds: number): string {
    const minutes = Math.round(seconds / 60);

    return t(':hours h :minutes min', {
        hours: Math.floor(minutes / 60),
        minutes: String(minutes % 60).padStart(2, '0'),
    });
}

function kilometers(meters: number): string {
    return `${new Intl.NumberFormat(locale.value).format(Math.round(meters / 1000))} km`;
}

function euros(value: number): string {
    return new Intl.NumberFormat(locale.value, {
        style: 'currency',
        currency: 'EUR',
        minimumFractionDigits: 3,
    }).format(value);
}

const WARNINGS: Record<string, string> = {
    no_break: 'No place for a break was found in time along this route.',
    no_coffee:
        'At some breaks there is only a rest area: no coffee or toilet is known there.',
    no_meal: 'No restaurant was found in time along this route.',
    no_toilet: 'No toilet was found in time along this route.',
    no_hotel:
        'No hotel was found in time along this route: look for a place to spend the night yourself.',
    no_fuel:
        'No gas station was found in time along this route: refuel before you leave.',
    no_places:
        'The places along this route could not be fetched, so no stops are planned. Please plan again later.',
    some_places:
        'Not all places along this route could be fetched: the stops may not be the best ones.',
};

function stopTitle(stop: TripStop): string {
    return stop.name || stop.brand || t(KIND_LABELS[stop.kind ?? ''] ?? 'Stop');
}

// A country by its name in the language of the user, from its ISO 2 code.
function countryName(code: string): string {
    try {
        return (
            new Intl.DisplayNames([locale.value], { type: 'region' }).of(
                code,
            ) ?? code
        );
    } catch {
        return code;
    }
}

// The price per liter at a stop, as far as it is known.
function priceOf(stop: TripStop): number | null {
    return stop.poi_id
        ? (route.value?.prices[stop.poi_id]?.price ?? null)
        : null;
}

// The fuel refueled at a stop: as priced there, or else the one of the vehicle.
function fuelOf(stop: TripStop): string | null {
    if (electric.value) {
        return null;
    }

    return (
        (stop.poi_id ? route.value?.prices[stop.poi_id]?.fuel_type : null) ??
        vehicleRange.value?.fuel_type_label ??
        null
    );
}

function focus(stop: TripStop) {
    map.value?.showStop(stop);
}

const vehicleRange = computed(() => props.trip?.plan?.vehicle);
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

            <SectionCard :title="t('Trip')" icon="mdi:map-marker-path">
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="flex flex-col gap-2">
                        <div
                            v-for="(waypoint, index) in form.waypoints"
                            :key="index"
                            class="flex flex-col gap-1"
                        >
                            <div class="flex items-start gap-2">
                                <PlaceInput
                                    v-model="form.waypoints[index]"
                                    :geocode-url="geocodeUrl"
                                    :reverse-geocode-url="reverseGeocodeUrl"
                                    :icon="
                                        index === 0
                                            ? 'mdi:map-marker'
                                            : index ===
                                                form.waypoints.length - 1
                                              ? 'mdi:flag-checkered'
                                              : 'mdi:map-marker-radius'
                                    "
                                    :placeholder="
                                        index === 0
                                            ? t('Start')
                                            : index ===
                                                form.waypoints.length - 1
                                              ? t('Destination')
                                              : t('Stop along the way')
                                    "
                                    :invalid="waypointError(index)"
                                />
                                <Button
                                    v-if="
                                        index > 0 &&
                                        index < form.waypoints.length - 1
                                    "
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="t('Remove')"
                                    @click="removeWaypoint(index)"
                                >
                                    <AppIcon name="mdi:close" class="size-4" />
                                </Button>
                                <Button
                                    v-else-if="index === 0"
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="t('Reverse')"
                                    @click="reverse"
                                >
                                    <AppIcon
                                        name="mdi:swap-vertical"
                                        class="size-4"
                                    />
                                </Button>
                                <span v-else class="size-9 shrink-0" />
                            </div>
                            <div
                                v-if="
                                    index > 0 &&
                                    index < form.waypoints.length - 1 &&
                                    waypoint
                                "
                                class="flex items-center gap-2 pl-8 text-sm text-muted-foreground"
                            >
                                <Label :for="`stay-${index}`">{{
                                    t('Stay (minutes)')
                                }}</Label>
                                <Input
                                    :id="`stay-${index}`"
                                    v-model.number="waypoint.stayMinutes"
                                    type="number"
                                    min="0"
                                    max="1440"
                                    class="h-8 w-24"
                                />
                            </div>
                            <p
                                v-if="waypointError(index)"
                                class="pl-8 text-sm text-destructive"
                            >
                                {{ t('Choose a place from the list.') }}
                            </p>
                        </div>
                        <Button
                            v-if="form.waypoints.length < 10"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="self-start"
                            @click="addWaypoint"
                        >
                            <AppIcon name="mdi:plus" class="size-4" />
                            {{ t('Add a stop along the way') }}
                        </Button>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="col-span-2 flex rounded-md border p-0.5">
                            <button
                                v-for="mode in [
                                    'departure',
                                    'arrival',
                                ] as const"
                                :key="mode"
                                type="button"
                                class="flex-1 rounded px-3 py-1.5 text-sm font-medium"
                                :class="
                                    form.timeMode === mode
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground'
                                "
                                @click="form.timeMode = mode"
                            >
                                {{
                                    mode === 'departure'
                                        ? t('Depart at')
                                        : t('Arrive at')
                                }}
                            </button>
                        </div>
                        <div class="col-span-2 flex flex-col gap-1.5">
                            <Label for="time-at">{{
                                form.timeMode === 'departure'
                                    ? t('Depart at')
                                    : t('Arrive at')
                            }}</Label>
                            <div class="flex gap-2">
                                <Input
                                    id="time-at"
                                    v-model="form.timeAt"
                                    type="datetime-local"
                                    class="dark:scheme-dark"
                                    :aria-invalid="
                                        !!form.errors.timeAt || undefined
                                    "
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    @click="setNow"
                                >
                                    {{ t('Now') }}
                                </Button>
                            </div>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="fuel-level">{{
                                t('Tank at departure (%)')
                            }}</Label>
                            <Input
                                id="fuel-level"
                                v-model.number="form.fuelLevel"
                                type="number"
                                min="5"
                                max="100"
                                step="5"
                            />
                        </div>
                        <p
                            v-if="form.errors.timeAt"
                            class="col-span-2 text-sm text-destructive"
                        >
                            {{ form.errors.timeAt }}
                        </p>
                        <div class="flex flex-col gap-1.5">
                            <Label for="name">{{ t('Name (optional)') }}</Label>
                            <Input
                                id="name"
                                v-model="form.name"
                                maxlength="100"
                            />
                        </div>
                        <p
                            v-if="vehicle"
                            class="col-span-2 flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <AppIcon name="mdi:car" class="size-4 shrink-0" />
                            {{ t('For :vehicle', { vehicle }) }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button
                            type="submit"
                            :disabled="form.processing"
                            class="flex-1"
                        >
                            {{ trip ? t('Plan again') : t('Plan trip') }}
                        </Button>
                        <Dialog v-if="trip">
                            <DialogTrigger as-child>
                                <Button type="button" variant="outline">
                                    {{ t('Delete') }}
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>{{
                                        t('Delete this trip?')
                                    }}</DialogTitle>
                                    <DialogDescription>
                                        {{
                                            t(
                                                ':name is deleted with its routes and stops. This cannot be undone.',
                                                { name: trip.name },
                                            )
                                        }}
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter class="gap-2">
                                    <DialogClose as-child>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                        >
                                            {{ t('Cancel') }}
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        :disabled="deleting"
                                        @click="destroy"
                                    >
                                        {{ t('Delete') }}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                </form>
            </SectionCard>

            <div
                v-if="planning"
                class="flex flex-col gap-2 rounded-xl border bg-card p-4 text-sm"
                role="progressbar"
                :aria-valuenow="progress"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div class="flex items-center gap-2">
                    <Spinner class="size-4 shrink-0" />
                    <span class="flex-1">
                        {{
                            trip?.status === 'planning'
                                ? t('Planning the routes…')
                                : t(
                                      'Looking for places to stop along the routes…',
                                  )
                        }}
                    </span>
                    <span class="font-medium tabular-nums"
                        >{{ progress }}%</span
                    >
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full rounded-full bg-primary transition-[width] duration-700"
                        :style="{ width: `${Math.max(progress, 2)}%` }"
                    />
                </div>
                <p
                    v-if="trip?.status === 'routed'"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        t(
                            'The routes are ready. The first time in an area, finding the places to stop can take a few minutes.',
                        )
                    }}
                </p>
            </div>

            <div
                v-else-if="trip?.status === 'failed'"
                class="rounded-xl border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive"
            >
                {{ trip.error }}
            </div>

            <template v-if="routes.length">
                <SectionCard :title="t('Routes')" icon="mdi:road-variant" flush>
                    <ul class="divide-y">
                        <li v-for="(item, index) in routes" :key="index">
                            <button
                                type="button"
                                class="flex w-full flex-col gap-1.5 p-4 text-left"
                                :class="
                                    index === selected
                                        ? 'bg-accent'
                                        : 'hover:bg-accent/50'
                                "
                                @click="selectRoute(index)"
                            >
                                <div class="flex items-baseline gap-2">
                                    <span class="flex-1 font-semibold">
                                        {{
                                            index === 0
                                                ? t('Fastest route')
                                                : t('Alternative :number', {
                                                      number: index,
                                                  })
                                        }}
                                    </span>
                                    <span
                                        class="flex items-center gap-1.5 tabular-nums"
                                    >
                                        <span class="text-lg font-semibold">{{
                                            duration(item.drive_seconds)
                                        }}</span>
                                        <span
                                            class="rounded-md bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground"
                                            >{{
                                                kilometers(item.distance)
                                            }}</span
                                        >
                                    </span>
                                </div>
                                <div
                                    class="flex items-center gap-1.5 text-sm text-muted-foreground tabular-nums"
                                >
                                    <AppIcon
                                        name="mdi:clock-outline"
                                        class="size-3.5"
                                    />
                                    {{
                                        t('Total travel time :duration', {
                                            duration: duration(
                                                item.drive_seconds +
                                                    item.stop_seconds,
                                            ),
                                        })
                                    }}
                                    <Spinner
                                        v-if="loadingStops"
                                        class="size-3.5"
                                    />
                                </div>
                                <div
                                    v-if="item.roads.length"
                                    class="flex flex-wrap items-center gap-1"
                                >
                                    <RoadBadge
                                        v-for="road in item.roads"
                                        :key="road.label"
                                        :road="road"
                                    />
                                </div>
                                <div
                                    class="flex flex-wrap gap-x-3 gap-y-1 text-sm text-muted-foreground"
                                >
                                    <span v-if="!loadingStops">{{
                                        electric
                                            ? t('Stops :duration', {
                                                  duration: duration(
                                                      item.stop_seconds,
                                                  ),
                                              })
                                            : stopCount(item) === 1
                                              ? t('1 stop')
                                              : t(':count stops', {
                                                    count: stopCount(item),
                                                })
                                    }}</span>
                                    <span class="tabular-nums">
                                        {{ clock(item.departure_at) }} –
                                        {{ moment(item.arrival_at) }}
                                    </span>
                                </div>
                                <div
                                    class="flex flex-wrap items-center gap-2 text-sm"
                                >
                                    <span class="flex -space-x-1">
                                        <CountryFlag
                                            v-for="country in item.countries"
                                            :key="country"
                                            :iso-code="country"
                                            class="[&_svg]:size-5"
                                        />
                                    </span>
                                    <span
                                        v-if="item.toll_distance > 0"
                                        class="inline-flex items-center gap-1 rounded-md bg-amber-100 px-2 py-0.5 text-xs text-amber-900 dark:bg-amber-900/40 dark:text-amber-100"
                                    >
                                        <AppIcon
                                            name="mdi:boom-gate"
                                            class="size-3.5"
                                        />
                                        {{
                                            t('Toll :distance', {
                                                distance: kilometers(
                                                    item.toll_distance,
                                                ),
                                            })
                                        }}
                                    </span>
                                    <span
                                        v-if="item.vignettes.length"
                                        class="inline-flex items-center gap-1 rounded-md bg-sky-100 px-2 py-0.5 text-xs text-sky-900 dark:bg-sky-900/40 dark:text-sky-100"
                                    >
                                        <AppIcon
                                            name="mdi:sticker-text"
                                            class="size-3.5"
                                        />
                                        {{
                                            t('Vignette: :countries', {
                                                countries: item.vignettes
                                                    .map(countryName)
                                                    .join(', '),
                                            })
                                        }}
                                    </span>
                                    <span
                                        v-if="item.traffic_delay >= 300"
                                        class="inline-flex items-center gap-1 rounded-md bg-red-100 px-2 py-0.5 text-xs text-red-900 dark:bg-red-900/40 dark:text-red-100"
                                    >
                                        {{
                                            t('Traffic delay :duration', {
                                                duration: duration(
                                                    item.traffic_delay,
                                                ),
                                            })
                                        }}
                                    </span>
                                </div>
                            </button>
                        </li>
                    </ul>
                </SectionCard>

                <SectionCard
                    v-if="route"
                    :title="t('Stops')"
                    icon="maki:highway-rest-area"
                    flush
                >
                    <div
                        v-for="warning in route.warnings"
                        :key="warning"
                        class="flex gap-2 border-b bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-900/20 dark:text-amber-100"
                    >
                        <AppIcon
                            name="mdi:alert-outline"
                            class="mt-0.5 size-4 shrink-0"
                        />
                        {{ t(WARNINGS[warning] ?? warning) }}
                    </div>
                    <ol class="relative flex flex-col py-2">
                        <li class="flex gap-3 px-4 py-2">
                            <span
                                class="w-12 shrink-0 pt-0.5 text-sm font-semibold tabular-nums"
                            >
                                {{ clock(route.departure_at) }}
                            </span>
                            <div class="flex-1">
                                <div class="font-medium">
                                    {{ form.waypoints[0]?.label }}
                                </div>
                                <div class="text-sm text-muted-foreground">
                                    {{ t('Departure') }} ·
                                    {{ day(route.departure_at) }}
                                </div>
                            </div>
                        </li>
                        <li
                            v-for="(stop, index) in route.stops ?? []"
                            :key="index"
                            class="flex gap-3 px-4 py-2"
                        >
                            <span
                                class="w-12 shrink-0 pt-0.5 text-sm font-semibold tabular-nums"
                            >
                                {{ moment(stop.arrive_at) }}
                            </span>
                            <StopLogo
                                v-if="stop.type === 'stop'"
                                :kind="stop.kind"
                                :brand="stop.brand"
                                :logo-url="logoUrl"
                            />
                            <button
                                type="button"
                                class="flex min-w-0 flex-1 flex-col items-start gap-1 text-left"
                                @click="focus(stop)"
                            >
                                <span
                                    class="flex flex-wrap items-center gap-1.5 font-medium"
                                >
                                    <RoadBadge
                                        v-if="stop.road"
                                        :road="stop.road"
                                    />
                                    {{
                                        stop.type === 'waypoint'
                                            ? stop.name
                                            : stopTitle(stop)
                                    }}
                                </span>
                                <span class="text-sm text-muted-foreground">
                                    {{ kilometers(stop.along) }}
                                    <template v-if="stop.minutes">
                                        ·
                                        {{
                                            t(':duration, until :time', {
                                                duration:
                                                    stop.minutes < 60
                                                        ? t(':minutes min', {
                                                              minutes:
                                                                  stop.minutes,
                                                          })
                                                        : duration(
                                                              stop.minutes * 60,
                                                          ),
                                                time: moment(stop.depart_at),
                                            })
                                        }}
                                    </template>
                                    <template
                                        v-if="
                                            stop.type === 'stop' &&
                                            stop.brand &&
                                            stop.brand !== stopTitle(stop)
                                        "
                                    >
                                        · {{ stop.brand }}
                                    </template>
                                </span>
                                <span
                                    v-if="stop.detour_seconds"
                                    class="flex items-center gap-1 text-xs text-muted-foreground"
                                >
                                    <AppIcon
                                        name="mdi:highway"
                                        class="size-3.5"
                                    />
                                    {{
                                        t(
                                            ':minutes min detour, by exit :exit',
                                            {
                                                minutes: Math.round(
                                                    stop.detour_seconds / 60,
                                                ),
                                                exit: stop.exit ?? '',
                                            },
                                        )
                                    }}
                                </span>
                                <span
                                    v-if="stop.needs.length"
                                    class="flex flex-wrap gap-1.5"
                                >
                                    <span
                                        v-for="need in stop.needs"
                                        :key="need"
                                        class="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-0.5 text-xs"
                                    >
                                        <AppIcon
                                            :name="needIcon(need, electric)"
                                            class="size-3.5"
                                        />
                                        {{ t(needLabel(need, electric)) }}
                                        <template
                                            v-if="
                                                need === 'fuel' && fuelOf(stop)
                                            "
                                        >
                                            ({{ fuelOf(stop) }})
                                        </template>
                                    </span>
                                </span>
                                <span
                                    v-if="
                                        stop.needs.includes('fuel') &&
                                        (stop.fuel_liters ||
                                            priceOf(stop) !== null)
                                    "
                                    class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
                                >
                                    <span
                                        v-if="stop.fuel_liters"
                                        class="inline-flex items-center gap-1"
                                    >
                                        <AppIcon
                                            :name="
                                                electric
                                                    ? 'mdi:battery-charging'
                                                    : 'mdi:water-outline'
                                            "
                                            class="size-3.5"
                                        />
                                        {{
                                            electric
                                                ? t('about :kwh kWh', {
                                                      kwh: Math.round(
                                                          stop.fuel_liters,
                                                      ),
                                                  })
                                                : t('about :liters l', {
                                                      liters: Math.round(
                                                          stop.fuel_liters,
                                                      ),
                                                  })
                                        }}
                                    </span>
                                    <span
                                        v-if="priceOf(stop) !== null"
                                        class="inline-flex items-center gap-1"
                                    >
                                        <AppIcon
                                            name="mdi:tag-outline"
                                            class="size-3.5"
                                        />
                                        {{
                                            t(':price per liter', {
                                                price: euros(
                                                    priceOf(stop) ?? 0,
                                                ),
                                            })
                                        }}
                                    </span>
                                </span>
                                <span
                                    v-if="
                                        stop.type === 'stop' &&
                                        stop.features?.length
                                    "
                                    class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                                >
                                    {{ t('Offers:') }}
                                    <AppIcon
                                        v-for="feature in stop.features"
                                        :key="feature"
                                        :name="FEATURE_ICONS[feature]"
                                        :title="
                                            t(
                                                FEATURE_LABELS[feature] ??
                                                    feature,
                                            )
                                        "
                                        class="size-3.5"
                                    />
                                </span>
                            </button>
                        </li>
                        <li class="flex gap-3 px-4 py-2">
                            <span
                                class="w-12 shrink-0 pt-0.5 text-sm font-semibold tabular-nums"
                            >
                                {{ clock(route.arrival_at) }}
                            </span>
                            <div class="flex-1">
                                <div class="font-medium">
                                    {{
                                        form.waypoints[
                                            form.waypoints.length - 1
                                        ]?.label
                                    }}
                                </div>
                                <div class="text-sm text-muted-foreground">
                                    {{ t('Arrival') }} ·
                                    {{ day(route.arrival_at) }} ·
                                    {{ kilometers(route.distance) }}
                                </div>
                            </div>
                        </li>
                    </ol>
                    <div
                        v-if="trip?.excluded"
                        class="flex items-center gap-2 border-t px-4 py-2 text-xs text-muted-foreground"
                    >
                        <AppIcon
                            name="mdi:close-circle-outline"
                            class="size-3.5"
                        />
                        <span class="flex-1">{{
                            t(':count places are left out by you.', {
                                count: trip.excluded,
                            })
                        }}</span>
                        <button
                            type="button"
                            class="font-medium text-primary hover:underline"
                            @click="chooseStop('reset')"
                        >
                            {{ t('Let the planner choose again') }}
                        </button>
                    </div>
                    <p class="border-t px-4 py-3 text-xs text-muted-foreground">
                        <template v-if="vehicleRange?.consumption && electric">
                            {{
                                t(
                                    'Charging stops based on a consumption of :consumption kWh/100 km and a battery of :tank kWh, charging fast chargers up to 80%.',
                                    {
                                        consumption: vehicleRange.consumption,
                                        tank: vehicleRange.tank ?? '',
                                    },
                                )
                            }}
                        </template>
                        <template v-else-if="vehicleRange?.consumption">
                            {{
                                vehicleRange.from_refuelings
                                    ? t(
                                          'Fuel stops based on a consumption of :consumption l/100 km from your refuelings and a tank of :tank l.',
                                          {
                                              consumption:
                                                  vehicleRange.consumption,
                                              tank: vehicleRange.tank ?? '',
                                          },
                                      )
                                    : t(
                                          'Fuel stops based on an estimated consumption of :consumption l/100 km and a tank of :tank l.',
                                          {
                                              consumption:
                                                  vehicleRange.consumption,
                                              tank: vehicleRange.tank ?? '',
                                          },
                                      )
                            }}
                        </template>
                        <template v-else>
                            {{
                                t(
                                    'No fuel stops are planned: the tank capacity or the fuel of this vehicle is unknown.',
                                )
                            }}
                        </template>
                    </p>
                </SectionCard>

                <SectionCard
                    v-if="route?.notices?.length"
                    :title="t('Mind')"
                    icon="mdi:alert-outline"
                    :badge="String(route.notices.length)"
                    flush
                >
                    <ul class="divide-y">
                        <li
                            v-for="(notice, index) in route.notices"
                            :key="index"
                            class="flex gap-2 px-4 py-3 text-sm"
                            :class="
                                notice.level === 'warning'
                                    ? 'bg-amber-50 text-amber-900 dark:bg-amber-900/20 dark:text-amber-100'
                                    : ''
                            "
                        >
                            <AppIcon
                                :name="
                                    notice.level === 'warning'
                                        ? 'mdi:alert-outline'
                                        : 'mdi:lightbulb-on-outline'
                                "
                                class="mt-0.5 size-4 shrink-0"
                            />
                            {{ notice.message }}
                        </li>
                    </ul>
                </SectionCard>

                <SectionCard
                    v-if="route"
                    :title="t('Traffic')"
                    icon="mdi:traffic-cone"
                    :badge="String(traffic.length)"
                    collapsible
                    persist-key="trip-traffic"
                    flush
                >
                    <ul v-if="traffic.length" class="divide-y">
                        <li v-for="(incident, index) in traffic" :key="index">
                            <button
                                type="button"
                                class="flex w-full gap-3 px-4 py-2.5 text-left hover:bg-accent/50"
                                @click="
                                    map?.flyTo(
                                        incident.latitude,
                                        incident.longitude,
                                    )
                                "
                            >
                                <span
                                    class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full text-white"
                                    :style="{
                                        background:
                                            TRAFFIC[incident.category]?.color,
                                    }"
                                >
                                    <AppIcon
                                        :name="
                                            TRAFFIC[incident.category]?.icon ??
                                            'mdi:alert'
                                        "
                                        class="size-3.5"
                                    />
                                </span>
                                <span class="flex min-w-0 flex-col text-sm">
                                    <span class="font-medium">
                                        {{
                                            t(
                                                TRAFFIC[incident.category]
                                                    ?.label ?? 'Traffic',
                                            )
                                        }}
                                        <RoadBadge
                                            v-if="incident.road_badge"
                                            :road="incident.road_badge"
                                            class="ml-1 align-middle"
                                        />
                                        <template v-else-if="incident.road">
                                            · {{ incident.road }}</template
                                        >
                                    </span>
                                    <span class="text-xs text-muted-foreground">
                                        {{ kilometers(incident.along) }}
                                        <template v-if="incident.description">
                                            · {{ incident.description }}
                                        </template>
                                        <template v-if="incident.delay">
                                            ·
                                            {{
                                                t('+:minutes min', {
                                                    minutes: Math.round(
                                                        incident.delay / 60,
                                                    ),
                                                })
                                            }}
                                        </template>
                                    </span>
                                    <span
                                        v-if="incident.start || incident.end"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ trafficTime(incident.start) }} –
                                        {{
                                            incident.end
                                                ? trafficTime(incident.end)
                                                : t('until further notice')
                                        }}
                                    </span>
                                </span>
                            </button>
                        </li>
                    </ul>
                    <p v-else class="p-4 text-sm text-muted-foreground">
                        {{
                            t(
                                'No road works, closures or other traffic are known on this route.',
                            )
                        }}
                    </p>
                </SectionCard>
            </template>

            <SectionCard
                v-if="trip && (trip.status === 'planned' || trip.tips)"
                :title="t('Travel tips')"
                icon="mdi:lightbulb-on-outline"
                flush
            >
                <template #actions>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="generatingTips || trip.status !== 'planned'"
                        @click="generateTips"
                    >
                        <Spinner v-if="generatingTips" class="size-3.5" />
                        {{
                            trip.tips ? t('Generate again') : t('Generate tips')
                        }}
                    </Button>
                </template>
                <ul v-if="trip.tips?.items?.length" class="divide-y">
                    <li
                        v-for="(tip, index) in trip.tips.items"
                        :key="index"
                        class="flex gap-3 px-4 py-3"
                    >
                        <AppIcon
                            :name="
                                TIP_ICONS[tip.category] ??
                                'mdi:lightbulb-on-outline'
                            "
                            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <div class="flex flex-col gap-0.5 text-sm">
                            <span class="font-medium">{{ tip.title }}</span>
                            <span class="text-muted-foreground">{{
                                tip.body
                            }}</span>
                        </div>
                    </li>
                </ul>
                <p v-else class="p-4 text-sm text-muted-foreground">
                    {{
                        t(
                            'Get tips for this trip from AI: rules in the countries on the way, costs, busy days and what to prepare.',
                        )
                    }}
                </p>
            </SectionCard>
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
            <div
                v-if="routes.length"
                class="absolute top-2 left-2 flex max-w-[calc(100%-4rem)] flex-wrap gap-1.5"
            >
                <label
                    v-for="(group, key) in POI_GROUPS"
                    :key="key"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border bg-background/90 py-1 pr-2.5 pl-1 text-xs shadow-sm backdrop-blur"
                    :class="{ 'opacity-60': !layers[key] }"
                >
                    <input
                        v-model="layers[key]"
                        type="checkbox"
                        class="sr-only"
                    />
                    <span
                        class="flex size-5 items-center justify-center rounded-full text-white"
                        :style="{
                            background: layers[key] ? group.color : '#94a3b8',
                        }"
                    >
                        <AppIcon :name="group.icon" class="size-3" />
                    </span>
                    {{ t(group.label) }}
                </label>
                <label
                    v-for="group in trafficGroups"
                    :key="group.key"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border bg-background/90 py-1 pr-2.5 pl-1 text-xs shadow-sm backdrop-blur"
                    :class="{ 'opacity-60': !layers[group.key] }"
                >
                    <input
                        v-model="layers[group.key]"
                        type="checkbox"
                        class="sr-only"
                    />
                    <span
                        class="flex size-5 items-center justify-center rounded-full text-white"
                        :style="{
                            background: layers[group.key]
                                ? group.color
                                : '#94a3b8',
                        }"
                    >
                        <AppIcon :name="group.icon" class="size-3" />
                    </span>
                    {{ t(group.label) }}
                    <span class="text-muted-foreground">{{ group.count }}</span>
                </label>
                <label
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border bg-background/90 px-2.5 py-1 text-xs shadow-sm backdrop-blur"
                >
                    <input
                        v-model="surroundings"
                        type="checkbox"
                        class="size-3.5 accent-primary"
                    />
                    {{ t('Show surroundings') }}
                </label>
                <span
                    v-if="loadingStops"
                    class="inline-flex items-center gap-1.5 rounded-full border bg-background/90 px-2.5 py-1 text-xs shadow-sm backdrop-blur"
                >
                    <Spinner class="size-3" />
                    {{ t('Loading places…') }}
                </span>
            </div>
        </div>
    </div>
</template>
