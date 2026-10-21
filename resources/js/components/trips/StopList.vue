<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue';
import SectionCard from '@/components/SectionCard.vue';
import RoadBadge from '@/components/trips/RoadBadge.vue';
import StopLogo from '@/components/trips/StopLogo.vue';
import { useT } from '@/composables/useT';
import { useTripFormat } from '@/composables/useTripFormat';
import {
    FEATURE_ICONS,
    FEATURE_LABELS,
    KIND_LABELS,
    needIcon,
    needLabel,
} from '@/lib/trips';
import type { TripPlan, TripRoute, TripStop } from '@/types/trips';

const props = defineProps<{
    route: TripRoute;
    start: string | undefined;
    destination: string | undefined;
    // The number of places the driver left out.
    excluded: number;
    electric: boolean;
    vehicle: TripPlan['vehicle'] | undefined;
    logoUrl: string;
    timezone: string;
}>();

const emit = defineEmits<{
    focus: [stop: TripStop];
    reset: [];
}>();

const { t } = useT();
const { clock, day, moment, duration, kilometers, perLiter } = useTripFormat(
    () => props.timezone,
);

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

// A time, with the day when it is not the day of departure.
function at(iso: string): string {
    return moment(iso, props.route.departure_at);
}

// The price per liter at a stop, as far as it is known.
function priceOf(stop: TripStop): number | null {
    return stop.poi_id
        ? (props.route.prices[stop.poi_id]?.price ?? null)
        : null;
}

// The fuel refueled at a stop: as priced there, or else the one of the vehicle.
function fuelOf(stop: TripStop): string | null {
    if (props.electric) {
        return null;
    }

    return (
        (stop.poi_id ? props.route.prices[stop.poi_id]?.fuel_type : null) ??
        props.vehicle?.fuel_type_label ??
        null
    );
}
</script>

<template>
    <SectionCard :title="t('Stops')" icon="maki:highway-rest-area" flush>
        <div
            v-for="warning in route.warnings"
            :key="warning"
            class="flex gap-2 border-b bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-900/20 dark:text-amber-100"
        >
            <AppIcon name="mdi:alert-outline" class="mt-0.5 size-4 shrink-0" />
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
                    <div class="font-medium">{{ start }}</div>
                    <div class="text-sm text-muted-foreground">
                        {{ t('Departure') }} · {{ day(route.departure_at) }}
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
                    {{ at(stop.arrive_at) }}
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
                    @click="emit('focus', stop)"
                >
                    <span
                        class="flex flex-wrap items-center gap-1.5 font-medium"
                    >
                        <RoadBadge v-if="stop.road" :road="stop.road" />
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
                                                  minutes: stop.minutes,
                                              })
                                            : duration(stop.minutes * 60),
                                    time: at(stop.depart_at),
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
                        <AppIcon name="mdi:highway" class="size-3.5" />
                        {{
                            t(':minutes min detour, by exit :exit', {
                                minutes: Math.round(stop.detour_seconds / 60),
                                exit: stop.exit ?? '',
                            })
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
                            <template v-if="need === 'fuel' && fuelOf(stop)">
                                ({{ fuelOf(stop) }})
                            </template>
                        </span>
                    </span>
                    <span
                        v-if="
                            stop.needs.includes('fuel') &&
                            (stop.fuel_liters || priceOf(stop) !== null)
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
                                          kwh: Math.round(stop.fuel_liters),
                                      })
                                    : t('about :liters l', {
                                          liters: Math.round(stop.fuel_liters),
                                      })
                            }}
                        </span>
                        <span
                            v-if="priceOf(stop) !== null"
                            class="inline-flex items-center gap-1"
                        >
                            <AppIcon name="mdi:tag-outline" class="size-3.5" />
                            {{
                                t(':price per liter', {
                                    price: perLiter(priceOf(stop) ?? 0),
                                })
                            }}
                        </span>
                    </span>
                    <span
                        v-if="stop.type === 'stop' && stop.features?.length"
                        class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                    >
                        {{ t('Offers:') }}
                        <AppIcon
                            v-for="feature in stop.features"
                            :key="feature"
                            :name="FEATURE_ICONS[feature]"
                            :title="t(FEATURE_LABELS[feature] ?? feature)"
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
                    <div class="font-medium">{{ destination }}</div>
                    <div class="text-sm text-muted-foreground">
                        {{ t('Arrival') }} · {{ day(route.arrival_at) }} ·
                        {{ kilometers(route.distance) }}
                    </div>
                </div>
            </li>
        </ol>
        <div
            v-if="excluded"
            class="flex items-center gap-2 border-t px-4 py-2 text-xs text-muted-foreground"
        >
            <AppIcon name="mdi:close-circle-outline" class="size-3.5" />
            <span class="flex-1">{{
                t(':count places are left out by you.', { count: excluded })
            }}</span>
            <button
                type="button"
                class="font-medium text-primary hover:underline"
                @click="emit('reset')"
            >
                {{ t('Let the planner choose again') }}
            </button>
        </div>
        <p class="border-t px-4 py-3 text-xs text-muted-foreground">
            <template v-if="vehicle?.consumption && electric">
                {{
                    t(
                        'Charging stops based on a consumption of :consumption kWh/100 km and a battery of :tank kWh, charging fast chargers up to 80%.',
                        {
                            consumption: vehicle.consumption,
                            tank: vehicle.tank ?? '',
                        },
                    )
                }}
            </template>
            <template v-else-if="vehicle?.consumption">
                {{
                    vehicle.from_refuelings
                        ? t(
                              'Fuel stops based on a consumption of :consumption l/100 km from your refuelings and a tank of :tank l.',
                              {
                                  consumption: vehicle.consumption,
                                  tank: vehicle.tank ?? '',
                              },
                          )
                        : t(
                              'Fuel stops based on an estimated consumption of :consumption l/100 km and a tank of :tank l.',
                              {
                                  consumption: vehicle.consumption,
                                  tank: vehicle.tank ?? '',
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
</template>
