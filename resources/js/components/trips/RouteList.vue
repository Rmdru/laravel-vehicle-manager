<script setup lang="ts">
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import CountryFlag from '@/components/CountryFlag.vue';
import SectionCard from '@/components/SectionCard.vue';
import RoadBadge from '@/components/trips/RoadBadge.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/composables/useT';
import { useTripFormat } from '@/composables/useTripFormat';
import type { TripRoute } from '@/types/trips';

const props = defineProps<{
    routes: TripRoute[];
    selected: number;
    // The routes are known, the stops along them not yet.
    loadingStops: boolean;
    electric: boolean;
    timezone: string;
    // When the fuel prices and the traffic were last asked for.
    plannedAt: string | null;
    // Whether they can be asked for again now.
    canRefresh: boolean;
    refreshing: boolean;
}>();

const emit = defineEmits<{
    select: [index: number];
    refresh: [];
}>();

const { t } = useT();
const { clock, moment, day, duration, kilometers, money, countryName } =
    useTripFormat(() => props.timezone);

function stopCount(item: TripRoute): number {
    return (item.stops ?? []).filter((stop) => stop.type === 'stop').length;
}

// The lowest of the costs that are known, to compare the routes with.
const cheapest = computed(() => {
    const totals = props.routes
        .map((item) => item.costs?.total)
        .filter((total): total is number => total != null);

    return totals.length > 1 ? Math.min(...totals) : null;
});

function moreThanCheapest(item: TripRoute): number | null {
    const total = item.costs?.total;

    return total != null && cheapest.value != null
        ? Math.round(total - cheapest.value)
        : null;
}
</script>

<template>
    <SectionCard :title="t('Routes')" icon="mdi:road-variant" flush>
        <template #actions>
            <Button
                size="sm"
                variant="ghost"
                :disabled="!canRefresh || refreshing"
                :title="t('Ask for the fuel prices and the traffic again')"
                @click="emit('refresh')"
            >
                <Spinner v-if="refreshing" class="size-3.5" />
                <AppIcon v-else name="mdi:refresh" class="size-4" />
                {{ t('Refresh') }}
            </Button>
        </template>
        <p
            v-if="plannedAt"
            class="border-b px-4 py-2 text-xs text-muted-foreground"
        >
            {{
                t('Fuel prices and traffic of :time', {
                    time: `${day(plannedAt)} ${clock(plannedAt)}`,
                })
            }}
        </p>
        <ul class="divide-y">
            <li v-for="(item, index) in routes" :key="index">
                <button
                    type="button"
                    class="flex w-full flex-col gap-1.5 p-4 text-left"
                    :class="
                        index === selected ? 'bg-accent' : 'hover:bg-accent/50'
                    "
                    @click="emit('select', index)"
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
                        <span class="flex items-center gap-1.5 tabular-nums">
                            <span class="text-lg font-semibold">{{
                                duration(item.drive_seconds)
                            }}</span>
                            <span
                                class="rounded-md bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground"
                                >{{ kilometers(item.distance) }}</span
                            >
                        </span>
                    </div>
                    <div
                        class="flex items-center gap-1.5 text-sm text-muted-foreground tabular-nums"
                    >
                        <AppIcon name="mdi:clock-outline" class="size-3.5" />
                        {{
                            t('Total travel time :duration', {
                                duration: duration(
                                    item.drive_seconds + item.stop_seconds,
                                ),
                            })
                        }}
                        <Spinner v-if="loadingStops" class="size-3.5" />
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
                                      duration: duration(item.stop_seconds),
                                  })
                                : stopCount(item) === 1
                                  ? t('1 stop')
                                  : t(':count stops', {
                                        count: stopCount(item),
                                    })
                        }}</span>
                        <span class="tabular-nums">
                            {{ clock(item.departure_at) }} –
                            {{ moment(item.arrival_at, item.departure_at) }}
                        </span>
                    </div>
                    <div
                        v-if="item.costs?.total != null"
                        class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"
                    >
                        <AppIcon
                            name="mdi:cash-multiple"
                            class="size-3.5 text-muted-foreground"
                        />
                        <span class="font-medium tabular-nums">
                            {{
                                item.costs.complete
                                    ? t('about :amount', {
                                          amount: money(item.costs.total),
                                      })
                                    : t('at least :amount', {
                                          amount: money(item.costs.total),
                                      })
                            }}
                        </span>
                        <span
                            v-if="
                                item.costs.tolls
                                    ? item.costs.tolls.some(
                                          (toll) => toll.price == null,
                                      )
                                    : item.toll_distance > 0
                            "
                            class="text-xs text-muted-foreground"
                        >
                            {{ t('plus toll') }}
                        </span>
                        <span
                            v-if="(moreThanCheapest(item) ?? 0) >= 1"
                            class="text-xs text-muted-foreground tabular-nums"
                        >
                            {{
                                t(':amount more than the cheapest', {
                                    amount: money(moreThanCheapest(item) ?? 0),
                                })
                            }}
                        </span>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-sm">
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
                            <AppIcon name="mdi:boom-gate" class="size-3.5" />
                            {{
                                t('Toll :distance', {
                                    distance: kilometers(item.toll_distance),
                                })
                            }}
                        </span>
                        <span
                            v-if="item.vignettes.length"
                            class="inline-flex items-center gap-1 rounded-md bg-sky-100 px-2 py-0.5 text-xs text-sky-900 dark:bg-sky-900/40 dark:text-sky-100"
                        >
                            <AppIcon name="mdi:sticker-text" class="size-3.5" />
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
                                    duration: duration(item.traffic_delay),
                                })
                            }}
                        </span>
                    </div>
                </button>
            </li>
        </ul>
    </SectionCard>
</template>
