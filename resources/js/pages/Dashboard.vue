<script setup lang="ts">
import { Deferred, Head, Link, router, usePage } from '@inertiajs/vue3';
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
};

type FuelPrice = {
    date: string;
    price: string;
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
    statuses?: { title: string; primary: string; secondary: string; icon: string }[];
    stats?: { title: string; icon: string; value: string; latest: string; trend: number; color: string }[];
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
    fuelUsageByType?: { fuelType: string; consumption: string; speed: string; amount: string; count: number }[];
    cheapestGasStations?: { gasStation: string; visits: number; averagePrice: string; lowestPrice: string }[];
    averageMonthlyCosts?: { label: string; amount: string; icon: string }[];
    costsChart?: { labels: string[]; series: { key: number; label: string; data: number[] }[] };
    latestCosts?: { key: string; type: string; item: string; price: string; date: string; icon: string; url: string }[];
}>();

const { t } = useT();
const vehicle = computed(() => usePage().props.currentVehicle!);

// The props that depend on the filters; only these are fetched again.
const filteredProps = [
    'filters',
    'stats',
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
    formatPeriod(usePage().props.locale, props.filters.start, props.filters.end),
);

const chips = computed<FilterChip[]>(() => [
    ...(periodLabel.value ? [{ key: 'period', label: periodLabel.value }] : []),
    ...props.filters.costTypes.map((type) => ({
        key: type,
        label: props.costTypeOptions[type] ?? type,
    })),
]);

function applyFilters(filters: { start?: string | null; end?: string | null; costTypes?: string[] }) {
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

const resetFilters = () => applyFilters({ start: null, end: null, costTypes: [] });

function refreshFuelPrices() {
    refreshing.value = true;
    router.post(props.refreshFuelPricesUrl, {}, {
        preserveScroll: true,
        onFinish: () => (refreshing.value = false),
    });
}

const label = (key: string) => {
    const text = key.replaceAll('_', ' ');

    return t(text.charAt(0).toUpperCase() + text.slice(1));
};
</script>

<template>
    <Head :title="t('Dashboard')" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <img
            v-if="vehicle.imageUrl"
            :src="vehicle.imageUrl"
            alt=""
            class="w-96 max-w-full rounded-xl"
        />

        <div class="flex flex-wrap items-center gap-4">
            <div class="flex items-center gap-2 text-lg font-semibold">
                <BrandLogo
                    :name="vehicle.brandLogo"
                    class="size-7 shrink-0"
                />
                {{ vehicle.name }}
            </div>
            <LicensePlate :plate="vehicle.licensePlate" />
            <ColorBadge v-if="vehicle.mileage !== null">
                {{ vehicle.mileage }} km
            </ColorBadge>
            <div class="sm:ml-auto">
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
                                :model-value="filters.costTypes.includes(String(type))"
                                @update:model-value="(checked) => toggleCostType(String(type), checked === true)"
                            />
                            {{ label }}
                        </label>
                    </fieldset>
                </FilterSheet>
            </div>
        </div>

        <FilterChips :chips="chips" @remove="removeChip" @reset="resetFilters" />

        <SectionCard :title="t('Notifications')" icon="gmdi-notifications-active-r" persist-key="notifications">
            <Deferred data="notifications">
                <template #fallback><Skeleton class="h-10 w-full" /></template>
                <div class="flex flex-col gap-3">
                    <div
                        v-for="notification in notifications"
                        :key="notification.key"
                        :class="[
                            'flex items-center gap-2 rounded-lg border p-2 font-medium',
                            notification.textColor,
                            notification.borderColor,
                        ]"
                    >
                        <AppIcon :name="notification.typeIcon" class="size-6 shrink-0" />
                        <AppIcon
                            v-if="notification.icon"
                            :name="notification.icon"
                            class="size-6 shrink-0"
                        />
                        <span>{{ notification.text }}</span>
                        <button
                            v-if="notification.hasModal"
                            type="button"
                            class="underline"
                            @click="details = notification"
                        >
                            {{ t('More information') }}
                        </button>
                    </div>
                </div>
            </Deferred>
        </SectionCard>

        <SectionCard :title="t('Status')" icon="mdi-list-status" persist-key="status">
            <Deferred data="statuses">
                <template #fallback><Skeleton class="h-20 w-full" /></template>
                <div v-if="statuses?.length" class="grid gap-4 md:grid-cols-3">
                    <div
                        v-for="status in statuses"
                        :key="status.title"
                        class="rounded-lg border p-4"
                    >
                        <div class="flex items-center gap-2 text-sm text-muted-foreground">
                            <AppIcon :name="status.icon" class="size-4" />
                            {{ status.title }}
                        </div>
                        <div class="mt-1 text-xl font-semibold">{{ status.primary }}</div>
                        <div class="text-sm text-muted-foreground">{{ status.secondary }}</div>
                    </div>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    {{ t('No statuses available to show.') }}
                </p>
            </Deferred>
        </SectionCard>

        <SectionCard :title="t('Statistics')" icon="gmdi-show-chart-r" persist-key="statistics" :badge="periodLabel" :class="{ 'opacity-60': filtering }">
            <Deferred data="stats">
                <template #fallback><Skeleton class="h-40 w-full" /></template>
                <div class="grid gap-4 md:grid-cols-3">
                    <div
                        v-for="stat in stats"
                        :key="stat.title"
                        class="rounded-lg border p-4"
                    >
                        <div class="flex items-center gap-2 text-sm text-muted-foreground">
                            <AppIcon :name="stat.icon" class="size-4" />
                            {{ stat.title }}
                        </div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums">{{ stat.value }}</div>
                        <div
                            :class="[
                                'mt-1 flex items-center gap-1 text-sm',
                                {
                                    success: 'text-green-700 dark:text-green-400',
                                    danger: 'text-red-700 dark:text-red-400',
                                    gray: 'text-muted-foreground',
                                }[stat.color],
                            ]"
                        >
                            <AppIcon
                                :name="
                                    stat.trend > 0
                                        ? 'gmdi-trending-up-r'
                                        : stat.trend < 0
                                          ? 'gmdi-trending-down-r'
                                          : 'mdi-approximately-equal'
                                "
                                class="size-4"
                            />
                            {{ t('Latest:') }} {{ stat.latest }}
                        </div>
                    </div>
                </div>
            </Deferred>
        </SectionCard>

        <template v-if="usesFuel">
            <SectionCard :title="t('Fuel prices abroad')" icon="gmdi-local-gas-station-r" persist-key="fuel-prices-abroad">
                <div class="mb-4 flex justify-end">
                    <Button :disabled="refreshing" @click="refreshFuelPrices">
                        <AppIcon name="gmdi-sync-o" :class="['size-4', refreshing ? 'animate-spin' : '']" />
                        {{ t('Refresh fuel prices') }}
                    </Button>
                </div>
                <Deferred data="fuelPricesAbroad">
                    <template #fallback><Skeleton class="h-32 w-full" /></template>
                    <div v-if="fuelPricesAbroad?.countries.length" class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-muted-foreground">
                                    <th class="p-2 font-medium">{{ t('Country') }}</th>
                                    <th
                                        v-for="(name, type) in fuelPricesAbroad.fuelTypes"
                                        :key="type"
                                        class="p-2 font-medium"
                                    >
                                        {{ name }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="country in fuelPricesAbroad.countries"
                                    :key="country.key"
                                    class="border-t"
                                >
                                    <td class="p-2 font-semibold whitespace-nowrap">
                                        <CountryFlag
                                            v-if="country.isoCode"
                                            :iso-code="country.isoCode"
                                            :name="country.name"
                                            show-name
                                        />
                                    </td>
                                    <td
                                        v-for="(name, type) in fuelPricesAbroad.fuelTypes"
                                        :key="type"
                                        class="p-2 tabular-nums"
                                    >
                                        <div
                                            v-if="country.prices[type]"
                                            :title="
                                                country.isHome
                                                    ? country.prices[type].date
                                                    : `${t('Break-even full: :km km', { km: country.prices[type].detourAll })} (${country.prices[type].date})`
                                            "
                                        >
                                            € {{ country.prices[type].price }}/l
                                            <div
                                                v-if="!country.isHome"
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{ t('Break-even fuel: :km km', { km: country.prices[type].detourFuel }) }}
                                            </div>
                                        </div>
                                        <span v-else class="text-muted-foreground">-</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('No fuel price data available.') }}
                    </p>
                </Deferred>
            </SectionCard>

            <SectionCard :title="t('Fuel usage by fuel type')" icon="gmdi-local-gas-station-r" persist-key="fuel-usage-by-type" :badge="periodLabel" :class="{ 'opacity-60': filtering }">
                <Deferred data="fuelUsageByType">
                    <template #fallback><Skeleton class="h-24 w-full" /></template>
                    <div v-if="fuelUsageByType?.length" class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-muted-foreground">
                                    <th class="p-2 font-medium">{{ t('Fuel type') }}</th>
                                    <th class="p-2 font-medium">{{ t('Average consumption') }}</th>
                                    <th class="p-2 font-medium">{{ t('Average speed') }}</th>
                                    <th class="p-2 font-medium">{{ t('Total refueled') }}</th>
                                    <th class="p-2 font-medium">{{ t('Number of refuelings') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in fuelUsageByType" :key="row.fuelType" class="border-t tabular-nums">
                                    <td class="p-2">{{ row.fuelType }}</td>
                                    <td class="p-2">{{ row.consumption }}</td>
                                    <td class="p-2">{{ row.speed }}</td>
                                    <td class="p-2">{{ row.amount }}</td>
                                    <td class="p-2">{{ row.count }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">{{ t('No refuelings found') }}</p>
                </Deferred>
            </SectionCard>
        </template>

        <SectionCard :title="t('Cheapest gas stations')" icon="gmdi-local-gas-station-r" persist-key="cheapest-gas-stations" :badge="periodLabel" :class="{ 'opacity-60': filtering }">
            <Deferred data="cheapestGasStations">
                <template #fallback><Skeleton class="h-24 w-full" /></template>
                <div v-if="cheapestGasStations?.length" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-muted-foreground">
                                <th class="p-2 font-medium">{{ t('Gas station') }}</th>
                                <th class="p-2 font-medium">{{ t('Visit count') }}</th>
                                <th class="p-2 font-medium">{{ t('Average price') }}</th>
                                <th class="p-2 font-medium">{{ t('Lowest price') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in cheapestGasStations" :key="row.gasStation" class="border-t tabular-nums">
                                <td class="p-2">{{ row.gasStation }}</td>
                                <td class="p-2">{{ row.visits }}</td>
                                <td class="p-2">{{ row.averagePrice }}</td>
                                <td class="p-2">{{ row.lowestPrice }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-sm text-muted-foreground">{{ t('No refuelings found') }}</p>
            </Deferred>
        </SectionCard>

        <SectionCard :title="t('Average monthly costs')" icon="mdi-hand-coin-outline" persist-key="average-monthly-costs" :badge="periodLabel" :class="{ 'opacity-60': filtering }">
            <Deferred data="averageMonthlyCosts">
                <template #fallback><Skeleton class="h-40 w-full" /></template>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground">
                            <th class="p-2 text-left font-medium">{{ t('Type') }}</th>
                            <th class="p-2 text-right font-medium">{{ t('Average per month') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="cost in averageMonthlyCosts" :key="cost.label" class="border-t">
                            <td class="flex items-center gap-3 p-2">
                                <AppIcon :name="cost.icon" class="size-4 text-muted-foreground" />
                                {{ cost.label }}
                            </td>
                            <td class="p-2 text-right tabular-nums">{{ cost.amount }}</td>
                        </tr>
                    </tbody>
                </table>
            </Deferred>
        </SectionCard>

        <SectionCard :title="t('Monthly costs')" icon="gmdi-bar-chart-r" persist-key="monthly-costs" :badge="periodLabel" :class="{ 'opacity-60': filtering }">
            <Deferred data="costsChart">
                <template #fallback><Skeleton class="h-72 w-full" /></template>
                <CostsChart
                    v-if="costsChart?.series.length"
                    :labels="costsChart.labels"
                    :series="costsChart.series"
                />
                <p v-else class="text-sm text-muted-foreground">{{ t('Nothing to show') }}</p>
            </Deferred>
        </SectionCard>

        <SectionCard :title="t('Most recent costs')" icon="mdi-hand-coin-outline" persist-key="most-recent-costs" :badge="periodLabel" :class="{ 'opacity-60': filtering }">
            <Deferred data="latestCosts">
                <template #fallback><Skeleton class="h-24 w-full" /></template>
                <div v-if="latestCosts?.length" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-muted-foreground">
                                <th class="p-2 font-medium">{{ t('Type') }}</th>
                                <th class="p-2 font-medium">{{ t('Item') }}</th>
                                <th class="p-2 font-medium">{{ t('Price') }}</th>
                                <th class="p-2 font-medium">{{ t('Date') }}</th>
                                <th class="p-2" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="cost in latestCosts" :key="cost.key" class="border-t">
                                <td class="flex items-center gap-3 p-2">
                                    <AppIcon :name="cost.icon" class="size-4 text-muted-foreground" />
                                    {{ cost.type }}
                                </td>
                                <td class="p-2">{{ cost.item }}</td>
                                <td class="p-2 tabular-nums">{{ cost.price }}</td>
                                <td class="p-2">{{ cost.date }}</td>
                                <td class="p-2 text-right">
                                    <Link :href="cost.url" class="inline-flex items-center gap-1 hover:underline">
                                        <AppIcon name="gmdi-remove-red-eye-r" class="size-4" />
                                        {{ t('Show') }}
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-sm text-muted-foreground">{{ t('No costs this month') }}</p>
            </Deferred>
        </SectionCard>

        <Dialog :open="details !== null" @update:open="details = null">
            <DialogContent class="max-h-[80vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{{ details?.modalTitle ?? details?.text }}</DialogTitle>
                </DialogHeader>
                <table
                    v-for="(item, index) in details?.data ?? []"
                    :key="index"
                    class="w-full rounded border text-left text-sm"
                >
                    <tr v-for="(value, key) in item" :key="key" class="border-b last:border-0">
                        <th scope="row" class="px-3 py-2 align-top font-semibold">{{ label(String(key)) }}:</th>
                        <td class="px-3 py-2 text-muted-foreground">
                            {{ typeof value === 'object' ? JSON.stringify(value) : value }}
                        </td>
                    </tr>
                </table>
                <p v-if="!details?.data.length" class="text-sm text-muted-foreground">
                    {{ t('No details available') }}
                </p>
            </DialogContent>
        </Dialog>
    </div>
</template>
