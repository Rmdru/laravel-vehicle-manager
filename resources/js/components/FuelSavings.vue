<script setup lang="ts">
import { computed, ref } from 'vue';
import SectionCard from '@/components/SectionCard.vue';
import { useT } from '@/composables/useT';

export type FuelSavingsData = {
    // The country the gas stations are compared in.
    country: string;
    // Of all fuel types together.
    total: string;
    // The fuel type refueled most first.
    fuelTypes: {
        key: string;
        label: string;
        // The cheapest of the usual gas stations.
        best: string;
        savings: string;
        stations: {
            name: string;
            visits: number;
            best: boolean;
            difference: string;
            // -1: below the average of the country, 1: above.
            trend: number;
            // What was paid more than at the cheapest; null when nothing.
            extra: string | null;
        }[];
    }[];
};

const props = defineProps<{ data: FuelSavingsData }>();

const { t } = useT();

const selected = ref(props.data.fuelTypes[0]?.key ?? null);
const fuelType = computed(
    () =>
        props.data.fuelTypes.find((type) => type.key === selected.value) ??
        props.data.fuelTypes[0],
);
</script>

<template>
    <SectionCard
        :title="t('Savings at the pump')"
        :badge="t('Past year')"
        icon="mdi:piggy-bank-outline"
        flush
    >
        <div
            v-if="data.fuelTypes.length > 1"
            class="flex flex-wrap items-center gap-2 border-b px-4 py-3"
        >
            <button
                v-for="type in data.fuelTypes"
                :key="type.key"
                type="button"
                :aria-pressed="type.key === fuelType?.key"
                :class="[
                    'inline-flex min-h-9 cursor-pointer items-center rounded-full border px-3 py-1 text-sm font-medium transition',
                    type.key === fuelType?.key
                        ? 'border-transparent bg-primary text-primary-foreground'
                        : 'border-input text-muted-foreground hover:border-ring/50 hover:text-foreground',
                ]"
                @click="selected = type.key"
            >
                {{ type.label }}
            </button>
            <span class="ml-auto text-sm text-muted-foreground tabular-nums">
                {{ t('In all: :amount', { amount: data.total }) }}
            </span>
        </div>
        <template v-if="fuelType">
            <p class="px-4 pt-3 text-sm">
                {{
                    t(
                        'Refueling at :station every time would have saved :amount in the past year.',
                        { station: fuelType.best, amount: fuelType.savings },
                    )
                }}
            </p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm tabular-nums">
                    <thead>
                        <tr class="text-right text-xs text-muted-foreground">
                            <th class="px-4 py-2.5 text-left font-semibold">
                                {{ t('Gas station') }}
                            </th>
                            <th class="px-3 py-2.5 font-semibold">
                                {{ t('Visit count') }}
                            </th>
                            <th class="px-3 py-2.5 font-semibold">
                                {{ t('Against the average') }}
                            </th>
                            <th class="py-2.5 pr-4 pl-3 font-semibold">
                                {{ t('Paid extra') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="station in fuelType.stations"
                            :key="station.name"
                            class="border-t text-right"
                        >
                            <td class="px-4 py-3 text-left font-medium">
                                {{ station.name }}
                                <span
                                    v-if="station.best"
                                    class="ml-1 rounded-md bg-success-muted px-1.5 py-0.5 text-xs font-semibold text-success"
                                >
                                    {{ t('Cheapest') }}
                                </span>
                            </td>
                            <td class="px-3 py-3">{{ station.visits }}</td>
                            <td
                                :class="[
                                    'px-3 py-3 whitespace-nowrap',
                                    station.trend < 0
                                        ? 'text-success'
                                        : station.trend > 0
                                          ? 'text-danger'
                                          : '',
                                ]"
                            >
                                {{ station.difference }}
                            </td>
                            <td
                                class="py-3 pr-4 pl-3 font-semibold whitespace-nowrap"
                            >
                                {{ station.extra ?? '–' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
        <p
            class="border-t px-4 py-3 text-xs text-muted-foreground max-sm:text-sm"
        >
            {{
                t(
                    'Compared with the average price in :country on the day of each refueling, as prices move.',
                    { country: data.country },
                )
            }}
        </p>
    </SectionCard>
</template>
