<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppIcon from '@/components/AppIcon.vue';
import ColorBadge from '@/components/ColorBadge.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useT } from '@/composables/useT';

type Item = {
    label: string;
    icon: string;
    // Null when it cannot be told.
    amount: string | null;
    basis: string;
    when: string | null;
};

defineProps<{
    forecast: {
        groups: { key: string; label: string; items: Item[] }[];
        total: string;
        perMonth: string;
        // Items without an amount.
        unknown: number;
        // A year, apart from what to put aside; null when not known.
        depreciation: string | null;
    };
    perKm: {
        since: string;
        km: string;
        costs: string;
        perKm: string;
        depreciation: string | null;
        perKmWithDepreciation: string | null;
        years: {
            year: number;
            km: string;
            costs: string;
            perKm: string | null;
        }[];
    } | null;
    // Null when what the vehicle is worth is not known.
    verdict: { verdict: 'repair' | 'weigh' | 'replace'; text: string } | null;
    comparisonUrl: string;
    prices: {
        key: string;
        label: string;
        icon: string;
        prices: {
            date: string;
            garage: string | null;
            price: string;
            url: string;
        }[];
    }[];
    garages: {
        garage: string;
        visits: number;
        total: string;
        average: string;
        last: string;
    }[];
}>();

const { t } = useT();

const verdictColors = {
    repair: 'success',
    weigh: 'warning',
    replace: 'danger',
} as const;

const verdictLabels = {
    repair: 'Repair',
    weigh: 'Weigh it up',
    replace: 'Consider replacing',
} as const;
</script>

<template>
    <Head :title="t('Cost forecast')" />

    <div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
        <h1 class="text-2xl font-bold tracking-tight">
            {{ t('Cost forecast') }}
        </h1>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">
                    {{ t('Put aside a month') }}
                </p>
                <p class="text-3xl font-bold tabular-nums">
                    {{ forecast.perMonth }}
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{
                        t(':total in the coming 12 months', {
                            total: forecast.total,
                        })
                    }}
                </p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">
                    {{ t('Cost per km') }}
                </p>
                <template v-if="perKm">
                    <p class="text-3xl font-bold tabular-nums">
                        {{ perKm.perKm }}
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{
                            perKm.perKmWithDepreciation
                                ? t(':amount with the loss of value', {
                                      amount: perKm.perKmWithDepreciation,
                                  })
                                : t('Since :date, over :km', {
                                      date: perKm.since,
                                      km: perKm.km,
                                  })
                        }}
                    </p>
                </template>
                <p v-else class="mt-1 text-sm text-muted-foreground">
                    {{
                        t(
                            'Not known yet: record the mileage with your refuelings or maintenance.',
                        )
                    }}
                </p>
            </div>
        </div>

        <SectionCard
            v-if="verdict"
            :title="t('Repair or replace')"
            icon="mdi:scale-balance"
            collapsible
            collapsed
            persist-key="cost-forecast-verdict"
        >
            <div class="flex flex-col gap-2">
                <ColorBadge
                    :color="verdictColors[verdict.verdict]"
                    class="w-fit"
                >
                    {{ t(verdictLabels[verdict.verdict]) }}
                </ColorBadge>
                <p class="text-sm">{{ verdict.text }}</p>
            </div>
        </SectionCard>
        <p v-else class="text-sm text-muted-foreground">
            {{
                t(
                    'Whether repairing is still worth it is told by what the vehicle is worth: calculate the alternative powertrains to have it estimated.',
                )
            }}
            <Link :href="comparisonUrl" class="underline">{{
                t('Dashboard')
            }}</Link>
        </p>

        <SectionCard
            :title="t('The coming 12 months')"
            icon="ic:round-calendar-month"
            flush
        >
            <div v-for="group in forecast.groups" :key="group.key">
                <h3
                    class="border-b bg-muted/40 px-4 py-2 text-sm font-semibold text-muted-foreground"
                >
                    {{ group.label }}
                </h3>
                <ul class="divide-y">
                    <li
                        v-for="item in group.items"
                        :key="item.label"
                        class="flex items-center gap-3 px-4 py-3"
                    >
                        <AppIcon
                            :name="item.icon"
                            class="size-5 shrink-0 text-muted-foreground"
                        />
                        <div class="min-w-0 flex-1">
                            <span class="font-medium">{{ item.label }}</span>
                            <span class="block text-sm text-muted-foreground">
                                {{
                                    [item.when, item.basis]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}
                            </span>
                        </div>
                        <span
                            class="font-semibold whitespace-nowrap tabular-nums"
                            :class="{ 'text-muted-foreground': !item.amount }"
                        >
                            {{ item.amount ?? t('Not known') }}
                        </span>
                    </li>
                </ul>
            </div>
            <div
                class="flex items-center justify-between gap-3 border-t px-4 py-3 font-semibold"
            >
                <span>{{ t('Total') }}</span>
                <span class="tabular-nums">{{ forecast.total }}</span>
            </div>
            <p
                v-if="forecast.unknown || forecast.depreciation"
                class="border-t px-4 py-3 text-sm text-muted-foreground"
            >
                <template v-if="forecast.unknown">
                    {{
                        t(
                            ':count items have no price yet: record what you pay for them with the components of the tasks.',
                            { count: forecast.unknown },
                        )
                    }}
                </template>
                <template v-if="forecast.depreciation">
                    {{
                        t(
                            'Besides, the vehicle loses about :amount in value a year.',
                            { amount: forecast.depreciation },
                        )
                    }}
                </template>
            </p>
        </SectionCard>

        <SectionCard
            v-if="perKm"
            :title="t('Cost per km by year')"
            icon="mdi:chart-line"
            flush
        >
            <table class="w-full text-sm">
                <thead class="text-left text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-4 py-2 font-medium">{{ t('Year') }}</th>
                        <th class="px-4 py-2 text-right font-medium">
                            {{ t('Mileage') }}
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            {{ t('Costs') }}
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            {{ t('Per km') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y tabular-nums">
                    <tr v-for="year in perKm.years" :key="year.year">
                        <td class="px-4 py-2">{{ year.year }}</td>
                        <td class="px-4 py-2 text-right">{{ year.km }}</td>
                        <td class="px-4 py-2 text-right">{{ year.costs }}</td>
                        <td class="px-4 py-2 text-right">
                            {{ year.perKm ?? '–' }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="border-t px-4 py-3 text-sm text-muted-foreground">
                {{
                    t(':costs since :date over :km.', {
                        costs: perKm.costs,
                        date: perKm.since,
                        km: perKm.km,
                    })
                }}
                <template v-if="perKm.depreciation">
                    {{
                        t('The loss of value is about :amount.', {
                            amount: perKm.depreciation,
                        })
                    }}
                </template>
            </p>
        </SectionCard>

        <SectionCard
            v-if="prices.length"
            :title="t('Prices paid')"
            icon="mdi:receipt-text-outline"
            flush
        >
            <ul class="divide-y">
                <li
                    v-for="component in prices"
                    :key="component.key"
                    class="flex gap-3 px-4 py-3"
                >
                    <AppIcon
                        :name="component.icon"
                        class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                    />
                    <div class="min-w-0 flex-1">
                        <span class="font-medium">{{ component.label }}</span>
                        <ul class="mt-1 grid gap-0.5 text-sm">
                            <li
                                v-for="price in component.prices"
                                :key="price.url + price.price"
                                class="flex justify-between gap-3"
                            >
                                <Link
                                    :href="price.url"
                                    class="truncate text-muted-foreground hover:underline"
                                >
                                    {{
                                        [price.date, price.garage]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}
                                </Link>
                                <span class="tabular-nums">{{
                                    price.price
                                }}</span>
                            </li>
                        </ul>
                    </div>
                </li>
            </ul>
        </SectionCard>

        <SectionCard
            v-if="garages.length"
            :title="t('Garages')"
            icon="mdi:garage"
            flush
        >
            <table class="w-full text-sm">
                <thead class="text-left text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-4 py-2 font-medium">
                            {{ t('Garage') }}
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            {{ t('Visits') }}
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            {{ t('Average') }}
                        </th>
                        <th
                            class="hidden px-4 py-2 text-right font-medium sm:table-cell"
                        >
                            {{ t('Total') }}
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            {{ t('Last') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y tabular-nums">
                    <tr v-for="garage in garages" :key="garage.garage">
                        <td class="px-4 py-2">{{ garage.garage }}</td>
                        <td class="px-4 py-2 text-right">
                            {{ garage.visits }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{ garage.average }}
                        </td>
                        <td class="hidden px-4 py-2 text-right sm:table-cell">
                            {{ garage.total }}
                        </td>
                        <td class="px-4 py-2 text-right">{{ garage.last }}</td>
                    </tr>
                </tbody>
            </table>
        </SectionCard>
    </div>
</template>
