<script setup lang="ts">
import { Deferred, Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus, RefreshCw } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import BrandLogo from '@/components/BrandLogo.vue';
import Checkbox from '@/components/ui/checkbox/Checkbox.vue';
import ColorBadge from '@/components/ColorBadge.vue';
import CostsChart from '@/components/CostsChart.vue';
import CountryFlag from '@/components/CountryFlag.vue';
import FilterChips from '@/components/FilterChips.vue';
import type { FilterChip } from '@/components/FilterChips.vue';
import FilterSheet from '@/components/FilterSheet.vue';
import FuelPricesChart from '@/components/FuelPricesChart.vue';
import LicensePlate from '@/components/LicensePlate.vue';
import PeriodFilter from '@/components/PeriodFilter.vue';
import SectionCard from '@/components/SectionCard.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { useT } from '@/composables/useT';
import { periodLabel as formatPeriod } from '@/lib/period';
import type { DateRange } from '@/lib/period';

type Notification = {
    key: string;
    type: 'critical' | 'warning' | 'info' | 'success';
    text: string;
    textColor: string;
    borderColor: string;
    icon: string;
    typeIcon: string;
    hasModal: boolean;
    modalTitle?: string;
    data: Record<string, unknown>[];
    // Where to do what the notification asks for.
    url?: string;
    urlLabel?: string;
};

type FuelPrice = {
    date: string;
    price: string;
    previousPrice: string | null;
    // 1: higher than the previous price, -1: lower, 0: the same or unknown.
    trend: number;
    detourFuel: number;
    detourAll: number;
};

const props = defineProps<{
    filters: { start: string | null; end: string | null; costTypes: string[] };
    // The cost types of the vehicle, by name, with their translated label.
    costTypeOptions: Record<string, string>;
    usesFuel: boolean;
    refreshFuelPricesUrl: string;
    notifications?: Notification[];
    statuses?: {
        title: string;
        primary: string;
        secondary: string;
        icon: string;
    }[];
    stats?: {
        title: string;
        icon: string;
        value: string;
        latest: string;
        trend: number;
        color: string;
    }[];
    fuelPricesAbroad?: {
        fuelTypes: Record<string, string>;
        countries: {
            key: string;
            name: string;
            isoCode: string | null;
            isHome: boolean;
            prices: Record<string, FuelPrice>;
        }[];
    };
    fuelPricesChart?: {
        home: string | null;
        countries: {
            key: string;
            name: string;
            isoCode: string | null;
            series: {
                key: number;
                label: string;
                points: { date: string; price: number }[];
            }[];
        }[];
    };
    fuelUsageByType?: {
        fuelType: string;
        consumption: string;
        speed: string;
        amount: string;
        count: number;
    }[];
    cheapestGasStations?: {
        gasStation: string;
        visits: number;
        averagePrice: string;
        lowestPrice: string;
    }[];
    averageMonthlyCosts?: {
        key: number;
        label: string;
        amount: string;
        share: number;
        icon: string;
    }[];
    costsChart?: {
        labels: string[];
        series: { key: number; label: string; data: number[] }[];
    };
    latestCosts?: {
        key: string;
        type: string;
        item: string;
        price: string;
        date: string;
        icon: string;
        url: string;
    }[];
}>();

const { t } = useT();
const vehicle = computed(() => usePage().props.currentVehicle!);

// The props that depend on the filters; only these are fetched again.
const filteredProps = [
    'filters',
    'stats',
    'fuelPricesChart',
    'fuelUsageByType',
    'cheapestGasStations',
    'averageMonthlyCosts',
    'costsChart',
    'latestCosts',
];

const filtering = ref(false);
const details = ref<Notification | null>(null);
const refreshing = ref(false);

// Shown on the sections the date range applies to.
const periodLabel = computed(() =>
    formatPeriod(
        usePage().props.locale,
        props.filters.start,
        props.filters.end,
    ),
);

const chips = computed<FilterChip[]>(() => [
    ...(periodLabel.value ? [{ key: 'period', label: periodLabel.value }] : []),
    ...props.filters.costTypes.map((type) => ({
        key: type,
        label: props.costTypeOptions[type] ?? type,
    })),
]);

function applyFilters(filters: {
    start?: string | null;
    end?: string | null;
    costTypes?: string[];
}) {
    const { start, end, costTypes } = { ...props.filters, ...filters };

    router.get(
        '/',
        {
            ...(start ? { dashboard_start: start } : {}),
            ...(end ? { dashboard_end: end } : {}),
            ...(costTypes.length ? { cost_types: costTypes } : {}),
        },
        {
            only: filteredProps,
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (filtering.value = true),
            onFinish: () => (filtering.value = false),
        },
    );
}

const applyPeriod = (range: DateRange) => applyFilters(range);

function toggleCostType(type: string, checked: boolean) {
    const selected = props.filters.costTypes.filter((name) => name !== type);

    applyFilters({ costTypes: checked ? [...selected, type] : selected });
}

function removeChip(key: string) {
    if (key === 'period') {
        applyFilters({ start: null, end: null });
    } else {
        toggleCostType(key, false);
    }
}

const resetFilters = () =>
    applyFilters({ start: null, end: null, costTypes: [] });

function refreshFuelPrices() {
    refreshing.value = true;
    router.post(
        props.refreshFuelPricesUrl,
        {},
        {
            preserveScroll: true,
            onFinish: () => (refreshing.value = false),
        },
    );
}

// Notifications that ask for something to be done, as opposed to "all is well".
const attention = computed(
    () =>
        props.notifications?.filter((item) => item.type !== 'success').length ??
        0,
);

const tones: Record<string, string> = {
    critical: 'text-danger',
    warning: 'text-warning',
    info: 'text-info',
    success: 'text-success',
};

// The date of the price, and the price it went up or down from.
const fuelPriceTitle = (price: FuelPrice): string =>
    price.previousPrice === null
        ? price.date
        : `${price.date} (${t('Previous: € :price/l', { price: price.previousPrice })})`;

const trends: Record<string, string> = {
    success: 'text-success',
    danger: 'text-danger',
    gray: 'text-muted-foreground',
};

const mileage = computed(() =>
    vehicle.value.mileage === null
        ? null
        : new Intl.NumberFormat(usePage().props.locale).format(
              vehicle.value.mileage,
          ),
);

const label = (key: string) => {
    const text = key.replaceAll('_', ' ');

    return t(text.charAt(0).toUpperCase() + text.slice(1));
};
</script>

<template>
    <Head :title="t('Dashboard')" />

    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4">
        <section
            class="flex flex-wrap gap-5 rounded-xl border bg-card p-4 md:p-5"
        >
            <img
                v-if="vehicle.imageUrl"
                :src="vehicle.imageUrl"
                :srcset="vehicle.imageSrcset ?? undefined"
                sizes="(min-width: 640px) 240px, 100vw"
                alt=""
                class="h-40 w-full rounded-lg object-cover sm:h-36 sm:w-60"
            />
            <div
                class="flex min-w-0 flex-1 basis-64 flex-col justify-center gap-3"
            >
                <div class="flex items-center gap-3">
                    <BrandLogo
                        :name="vehicle.brandLogo"
                        class="size-11 shrink-0"
                    />
                    <h1 class="text-2xl leading-tight font-bold tracking-tight">
                        {{ vehicle.name }}
                    </h1>
                </div>
                <div class="flex flex-wrap items-center gap-2.5">
                    <LicensePlate
                        :plate="vehicle.licensePlate"
                        class="text-lg"
                    />
                    <ColorBadge :color="vehicle.statusColor">
                        <AppIcon
                            v-if="vehicle.statusIcon"
                            :name="vehicle.statusIcon"
                            class="size-3.5"
                        />
                        {{ vehicle.status }}
                    </ColorBadge>
                </div>
                <div class="flex flex-wrap gap-2">
                    <ColorBadge
                        v-if="mileage !== null"
                        class="tabular-nums"
                        :title="t('Mileage')"
                    >
                        <AppIcon name="ic:baseline-route" class="size-3.5" />
                        {{ mileage }} km
                    </ColorBadge>
                    <ColorBadge
                        v-if="vehicle.age !== null"
                        :title="
                            vehicle.constructionDate
                                ? `${t('Construction date')}: ${vehicle.constructionDate}`
                                : undefined
                        "
                    >
                        <AppIcon
                            name="fa7-solid:cake-candles"
                            class="size-3.5"
                        />
                        {{ vehicle.age }} {{ t('years old') }}
                    </ColorBadge>
                </div>
            </div>
            <Deferred data="statuses">
                <template #fallback>
                    <Skeleton class="h-24 flex-1 basis-72" />
                </template>
                <div
                    v-if="statuses?.length"
                    class="grid flex-1 basis-72 content-center gap-3 sm:grid-cols-2"
                >
                    <div
                        v-for="status in statuses"
                        :key="status.title"
                        class="rounded-lg border p-3.5"
                    >
                        <div
                            class="flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            <AppIcon :name="status.icon" class="size-4" />
                            {{ status.title }}
                        </div>
                        <div class="mt-1 text-lg leading-snug font-semibold">
                            {{ status.primary }}
                        </div>
                        <div class="text-sm text-muted-foreground">
                            {{ status.secondary }}
                        </div>
                    </div>
                </div>
            </Deferred>
        </section>

        <SectionCard :title="t('Needs attention')" flush>
            <template v-if="attention" #actions>
                <span
                    class="mr-2 rounded-full bg-danger-muted px-2.5 py-0.5 text-xs font-semibold text-danger tabular-nums"
                >
                    {{ attention }}
                </span>
            </template>
            <Deferred data="notifications">
                <template #fallback>
                    <div class="p-4"><Skeleton class="h-10 w-full" /></div>
                </template>
                <ul class="divide-y">
                    <li
                        v-for="notification in notifications"
                        :key="notification.key"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3"
                    >
                        <span
                            :class="[
                                'flex items-center gap-2',
                                tones[notification.type],
                            ]"
                        >
                            <AppIcon
                                :name="notification.typeIcon"
                                class="size-6 shrink-0"
                            />
                            <AppIcon
                                v-if="notification.icon"
                                :name="notification.icon"
                                class="size-6 shrink-0"
                            />
                        </span>
                        <span class="min-w-0 flex-1 basis-56 font-medium">
                            {{ notification.text }}
                        </span>
                        <button
                            v-if="notification.hasModal"
                            type="button"
                            class="min-h-9 cursor-pointer text-sm font-semibold underline"
                            @click="details = notification"
                        >
                            {{ t('More information') }}
                        </button>
                        <Link
                            v-if="notification.url"
                            :href="notification.url"
                            class="min-h-9 content-center text-sm font-semibold underline"
                        >
                            {{ notification.urlLabel }}
                        </Link>
                    </li>
                </ul>
            </Deferred>
        </SectionCard>

        <div class="flex flex-wrap items-center justify-between gap-2 pt-2">
            <h2 class="flex flex-wrap items-center gap-2 text-lg font-semibold">
                {{ t('Statistics') }}
                <span
                    v-if="periodLabel"
                    class="rounded-md bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
                >
                    {{ periodLabel }}
                </span>
            </h2>
            <FilterSheet
                :active="chips.length"
                :description="periodLabel"
                @reset="resetFilters"
            >
                <PeriodFilter
                    :start="filters.start"
                    :end="filters.end"
                    complete
                    @apply="applyPeriod"
                />
                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">
                        {{ t('Cost types') }}
                    </legend>
                    <label
                        v-for="(label, type) in costTypeOptions"
                        :key="type"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Checkbox
                            :model-value="
                                filters.costTypes.includes(String(type))
                            "
                            @update:model-value="
                                (checked) =>
                                    toggleCostType(
                                        String(type),
                                        checked === true,
                                    )
                            "
                        />
                        {{ label }}
                    </label>
                </fieldset>
            </FilterSheet>
        </div>

        <FilterChips
            :chips="chips"
            @remove="removeChip"
            @reset="resetFilters"
        />

        <Deferred data="stats">
            <template #fallback><Skeleton class="h-56 w-full" /></template>
            <div
                class="grid grid-cols-2 gap-3 lg:grid-cols-4"
                :class="{ 'opacity-60': filtering }"
            >
                <div
                    v-for="stat in stats"
                    :key="stat.title"
                    class="flex flex-col gap-1.5 rounded-xl border bg-card p-4"
                >
                    <div
                        class="flex items-start gap-2 text-sm text-muted-foreground"
                    >
                        <AppIcon
                            :name="stat.icon"
                            class="mt-0.5 size-4 shrink-0"
                        />
                        {{ stat.title }}
                    </div>
                    <div
                        class="mt-auto text-xl font-bold tracking-tight tabular-nums sm:text-2xl"
                    >
                        {{ stat.value }}
                    </div>
                    <div
                        :class="[
                            'flex items-center gap-1 text-sm font-medium tabular-nums',
                            trends[stat.color],
                        ]"
                    >
                        <AppIcon
                            :name="
                                stat.trend > 0
                                    ? 'ic:round-trending-up'
                                    : stat.trend < 0
                                      ? 'ic:round-trending-down'
                                      : 'mdi:approximately-equal'
                            "
                            class="size-4 shrink-0"
                        />
                        {{ t('Latest:') }} {{ stat.latest }}
                    </div>
                </div>
            </div>
        </Deferred>

        <div
            class="grid items-start gap-4 lg:grid-cols-3"
            :class="{ 'opacity-60': filtering }"
        >
            <SectionCard :title="t('Monthly costs')" class="lg:col-span-2">
                <Deferred data="costsChart">
                    <template #fallback
                        ><Skeleton class="h-72 w-full"
                    /></template>
                    <CostsChart
                        v-if="costsChart?.series.length"
                        :labels="costsChart.labels"
                        :series="costsChart.series"
                    />
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('Nothing to show') }}
                    </p>
                </Deferred>
            </SectionCard>

            <SectionCard :title="t('Average monthly costs')">
                <Deferred data="averageMonthlyCosts">
                    <template #fallback
                        ><Skeleton class="h-40 w-full"
                    /></template>
                    <ul
                        v-if="averageMonthlyCosts?.length"
                        class="flex flex-col gap-3.5"
                    >
                        <li
                            v-for="cost in averageMonthlyCosts"
                            :key="cost.label"
                            class="flex flex-col gap-1.5"
                        >
                            <div class="flex items-center gap-2 text-sm">
                                <AppIcon
                                    :name="cost.icon"
                                    class="size-4 shrink-0 text-muted-foreground"
                                />
                                <span class="min-w-0 flex-1">{{
                                    cost.label
                                }}</span>
                                <span class="font-semibold tabular-nums">
                                    {{ cost.amount }}
                                </span>
                            </div>
                            <div
                                class="h-1.5 overflow-hidden rounded-full bg-muted"
                                aria-hidden="true"
                            >
                                <div
                                    class="h-full rounded-full"
                                    :style="{
                                        width: `${Math.max(cost.share, 1.5)}%`,
                                        background: `var(--chart-series-${(cost.key % 14) + 1})`,
                                    }"
                                />
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('Nothing to show') }}
                    </p>
                </Deferred>
            </SectionCard>
        </div>

        <div
            class="grid items-start gap-4 xl:grid-cols-2"
            :class="{ 'opacity-60': filtering }"
        >
            <SectionCard :title="t('Most recent costs')" flush>
                <Deferred data="latestCosts">
                    <template #fallback>
                        <div class="p-4"><Skeleton class="h-24 w-full" /></div>
                    </template>
                    <ul
                        v-if="latestCosts?.length"
                        class="max-h-112 divide-y overflow-y-auto"
                    >
                        <li v-for="cost in latestCosts" :key="cost.key">
                            <Link
                                :href="cost.url"
                                class="flex min-h-14 items-center gap-3 px-4 py-2 hover:bg-muted/60"
                            >
                                <span
                                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted"
                                >
                                    <AppIcon :name="cost.icon" class="size-5" />
                                </span>
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span class="truncate font-semibold">
                                        {{ cost.item || cost.type }}
                                    </span>
                                    <span class="text-xs text-muted-foreground">
                                        {{ cost.type }} · {{ cost.date }}
                                    </span>
                                </span>
                                <span
                                    class="font-semibold whitespace-nowrap tabular-nums"
                                >
                                    {{ cost.price }}
                                </span>
                            </Link>
                        </li>
                    </ul>
                    <p v-else class="p-4 text-sm text-muted-foreground">
                        {{
                            filters.start && filters.end
                                ? t('No costs in this period')
                                : t('No costs this month')
                        }}
                    </p>
                </Deferred>
            </SectionCard>

            <SectionCard :title="t('Cheapest gas stations')" flush>
                <Deferred data="cheapestGasStations">
                    <template #fallback>
                        <div class="p-4"><Skeleton class="h-24 w-full" /></div>
                    </template>
                    <div
                        v-if="cheapestGasStations?.length"
                        class="overflow-x-auto"
                    >
                        <table class="w-full text-sm tabular-nums">
                            <thead>
                                <tr
                                    class="text-right text-xs text-muted-foreground"
                                >
                                    <th
                                        class="px-4 py-2.5 text-left font-semibold"
                                    >
                                        {{ t('Gas station') }}
                                    </th>
                                    <th class="px-3 py-2.5 font-semibold">
                                        {{ t('Visit count') }}
                                    </th>
                                    <th class="px-3 py-2.5 font-semibold">
                                        {{ t('Average price') }}
                                    </th>
                                    <th class="py-2.5 pr-4 pl-3 font-semibold">
                                        {{ t('Lowest price') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in cheapestGasStations"
                                    :key="row.gasStation"
                                    class="border-t text-right"
                                >
                                    <td class="px-4 py-3 text-left font-medium">
                                        {{ row.gasStation }}
                                    </td>
                                    <td class="px-3 py-3">{{ row.visits }}</td>
                                    <td class="px-3 py-3 whitespace-nowrap">
                                        {{ row.averagePrice }}
                                    </td>
                                    <td
                                        class="py-3 pr-4 pl-3 font-semibold whitespace-nowrap"
                                    >
                                        {{ row.lowestPrice }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="p-4 text-sm text-muted-foreground">
                        {{ t('No refuelings found') }}
                    </p>
                </Deferred>
            </SectionCard>

            <template v-if="usesFuel">
                <SectionCard :title="t('Fuel usage by fuel type')" flush>
                    <Deferred data="fuelUsageByType">
                        <template #fallback>
                            <div class="p-4">
                                <Skeleton class="h-24 w-full" />
                            </div>
                        </template>
                        <div
                            v-if="fuelUsageByType?.length"
                            class="divide-y md:hidden"
                        >
                            <dl
                                v-for="row in fuelUsageByType"
                                :key="row.fuelType"
                                class="grid grid-cols-2 gap-x-4 gap-y-2 p-4 text-sm tabular-nums"
                            >
                                <dt class="col-span-2 font-semibold">
                                    {{ row.fuelType }}
                                </dt>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        {{ t('Average consumption') }}
                                    </dt>
                                    <dd class="font-semibold">
                                        {{ row.consumption }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        {{ t('Average speed') }}
                                    </dt>
                                    <dd>{{ row.speed }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        {{ t('Total refueled') }}
                                    </dt>
                                    <dd>{{ row.amount }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        {{ t('Number of refuelings') }}
                                    </dt>
                                    <dd>{{ row.count }}</dd>
                                </div>
                            </dl>
                        </div>
                        <div
                            v-if="fuelUsageByType?.length"
                            class="hidden overflow-x-auto md:block"
                        >
                            <table class="w-full text-sm tabular-nums">
                                <thead>
                                    <tr
                                        class="text-right text-xs text-muted-foreground"
                                    >
                                        <th
                                            class="px-4 py-2.5 text-left font-semibold"
                                        >
                                            {{ t('Fuel type') }}
                                        </th>
                                        <th class="px-3 py-2.5 font-semibold">
                                            {{ t('Average consumption') }}
                                        </th>
                                        <th class="px-3 py-2.5 font-semibold">
                                            {{ t('Average speed') }}
                                        </th>
                                        <th class="px-3 py-2.5 font-semibold">
                                            {{ t('Total refueled') }}
                                        </th>
                                        <th
                                            class="py-2.5 pr-4 pl-3 font-semibold"
                                        >
                                            {{ t('Number of refuelings') }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in fuelUsageByType"
                                        :key="row.fuelType"
                                        class="border-t text-right"
                                    >
                                        <td
                                            class="px-4 py-3 text-left font-medium"
                                        >
                                            {{ row.fuelType }}
                                        </td>
                                        <td
                                            class="px-3 py-3 font-semibold whitespace-nowrap"
                                        >
                                            {{ row.consumption }}
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap">
                                            {{ row.speed }}
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap">
                                            {{ row.amount }}
                                        </td>
                                        <td class="py-3 pr-4 pl-3">
                                            {{ row.count }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="p-4 text-sm text-muted-foreground">
                            {{ t('No refuelings found') }}
                        </p>
                    </Deferred>
                </SectionCard>

                <SectionCard
                    class="xl:col-span-2"
                    :title="t('Fuel prices abroad')"
                    collapsible
                    persist-key="fuel-prices-abroad"
                    flush
                >
                    <template #actions>
                        <Button
                            variant="ghost"
                            size="icon"
                            :disabled="refreshing"
                            :aria-label="t('Refresh fuel prices')"
                            :title="t('Refresh fuel prices')"
                            @click="refreshFuelPrices"
                        >
                            <RefreshCw
                                :class="[
                                    'size-4',
                                    refreshing ? 'animate-spin' : '',
                                ]"
                            />
                        </Button>
                    </template>
                    <Deferred data="fuelPricesAbroad">
                        <template #fallback>
                            <div class="p-4">
                                <Skeleton class="h-32 w-full" />
                            </div>
                        </template>
                        <!-- A block per country instead of a wide table: nothing to scroll sideways. -->
                        <div
                            v-if="fuelPricesAbroad?.countries.length"
                            class="-mr-px -mb-px grid sm:grid-cols-2 xl:grid-cols-4"
                        >
                            <div
                                v-for="country in fuelPricesAbroad.countries"
                                :key="country.key"
                                class="border-r border-b p-4"
                            >
                                <div class="mb-3 font-semibold">
                                    <CountryFlag
                                        v-if="country.isoCode"
                                        :iso-code="country.isoCode"
                                        :name="country.name"
                                        show-name
                                    />
                                </div>
                                <dl class="space-y-2 text-sm">
                                    <div
                                        v-for="(
                                            name, type
                                        ) in fuelPricesAbroad.fuelTypes"
                                        :key="type"
                                        class="flex justify-between gap-4"
                                    >
                                        <dt>{{ name }}</dt>
                                        <dd
                                            v-if="country.prices[type]"
                                            class="text-right tabular-nums"
                                            :title="
                                                fuelPriceTitle(
                                                    country.prices[type],
                                                )
                                            "
                                        >
                                            <div
                                                class="flex items-center justify-end gap-1 font-semibold"
                                            >
                                                <ArrowUp
                                                    v-if="
                                                        country.prices[type]
                                                            .trend > 0
                                                    "
                                                    class="size-3.5 shrink-0 text-danger"
                                                    :aria-label="
                                                        t('Price increase')
                                                    "
                                                />
                                                <ArrowDown
                                                    v-else-if="
                                                        country.prices[type]
                                                            .trend < 0
                                                    "
                                                    class="size-3.5 shrink-0 text-success"
                                                    :aria-label="
                                                        t('Price decrease')
                                                    "
                                                />
                                                <span>
                                                    €
                                                    {{
                                                        country.prices[type]
                                                            .price
                                                    }}/l
                                                </span>
                                            </div>
                                            <div
                                                v-if="!country.isHome"
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{
                                                    t(
                                                        'Break-even fuel: :km km',
                                                        {
                                                            km: country.prices[
                                                                type
                                                            ].detourFuel,
                                                        },
                                                    )
                                                }}<br />
                                                {{
                                                    t(
                                                        'Break-even full: :km km',
                                                        {
                                                            km: country.prices[
                                                                type
                                                            ].detourAll,
                                                        },
                                                    )
                                                }}
                                            </div>
                                        </dd>
                                        <dd
                                            v-else
                                            class="text-muted-foreground"
                                        >
                                            -
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                        <p v-else class="p-4 text-sm text-muted-foreground">
                            {{ t('No fuel price data available.') }}
                        </p>
                    </Deferred>
                </SectionCard>

                <!-- Left out altogether when no fuel prices are known for the period. -->
                <SectionCard
                    v-if="
                        fuelPricesChart === undefined ||
                        fuelPricesChart.countries.length
                    "
                    class="xl:col-span-2"
                    :title="t('Fuel prices')"
                    :badge="periodLabel || undefined"
                >
                    <Deferred data="fuelPricesChart">
                        <template #fallback>
                            <Skeleton class="h-72 w-full" />
                        </template>
                        <FuelPricesChart
                            v-if="fuelPricesChart?.countries.length"
                            :countries="fuelPricesChart.countries"
                            :home="fuelPricesChart.home"
                        />
                    </Deferred>
                </SectionCard>
            </template>
        </div>

        <!-- At the pump the phone is in one hand: adding a refueling is one tap away. -->
        <div
            class="sticky bottom-0 z-10 -mx-4 -mb-4 border-t bg-card p-3 md:hidden"
        >
            <Button as-child size="lg" class="w-full">
                <Link href="/refuelings/create">
                    <Plus class="size-4" />
                    {{ t('Add refueling') }}
                </Link>
            </Button>
        </div>

        <Dialog :open="details !== null" @update:open="details = null">
            <DialogContent class="max-h-[80vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{{
                        details?.modalTitle ?? details?.text
                    }}</DialogTitle>
                </DialogHeader>
                <table
                    v-for="(item, index) in details?.data ?? []"
                    :key="index"
                    class="w-full rounded border text-left text-sm"
                >
                    <tr
                        v-for="(value, key) in item"
                        :key="key"
                        class="border-b last:border-0"
                    >
                        <th
                            scope="row"
                            class="px-3 py-2 align-top font-semibold"
                        >
                            {{ label(String(key)) }}:
                        </th>
                        <td class="px-3 py-2 text-muted-foreground">
                            {{
                                typeof value === 'object'
                                    ? JSON.stringify(value)
                                    : value
                            }}
                        </td>
                    </tr>
                </table>
                <p
                    v-if="!details?.data.length"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('No details available') }}
                </p>
            </DialogContent>
        </Dialog>
    </div>
</template>
