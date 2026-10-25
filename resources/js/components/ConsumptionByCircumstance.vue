<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useT } from '@/composables/useT';

export type ConsumptionByCircumstanceData = {
    // Of all refuelings of the period, weighted by distance.
    average: string;
    // The latest price paid, that the costs are at.
    price: string;
    circumstances: {
        key: string;
        label: string;
        // The most economical first.
        values: {
            key: string;
            label: string;
            icon: string | null;
            refuelings: number;
            consumption: string;
            // Against the average.
            difference: string;
            // Per 1000 km, against the average.
            costs: string;
            trend: number;
        }[];
    }[];
};

defineProps<{ data: ConsumptionByCircumstanceData; badge?: string }>();

const { t } = useT();
</script>

<template>
    <SectionCard
        :title="t('Consumption by circumstance')"
        :badge="badge"
        icon="mdi:speedometer"
        flush
    >
        <p class="px-4 pt-3 text-sm text-muted-foreground">
            {{
                t('Average :consumption; the costs per 1000 km at :price.', {
                    consumption: data.average,
                    price: data.price,
                })
            }}
        </p>
        <div class="divide-y">
            <div
                v-for="circumstance in data.circumstances"
                :key="circumstance.key"
                class="px-4 py-3"
            >
                <h3 class="mb-2 text-sm font-semibold">
                    {{ circumstance.label }}
                </h3>
                <ul class="flex flex-col gap-2 text-sm tabular-nums">
                    <li
                        v-for="value in circumstance.values"
                        :key="value.key"
                        class="flex flex-wrap items-center gap-x-3 gap-y-0.5"
                    >
                        <AppIcon
                            v-if="value.icon"
                            :name="value.icon"
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                        <span class="min-w-0 flex-1 basis-32">
                            {{ value.label }}
                            <span
                                class="text-xs text-muted-foreground max-sm:text-sm"
                            >
                                ·
                                {{
                                    t(':count refuelings', {
                                        count: value.refuelings,
                                    })
                                }}
                            </span>
                        </span>
                        <span class="whitespace-nowrap">
                            {{ value.consumption }}
                            <span class="text-xs text-muted-foreground">
                                ({{ value.difference }})
                            </span>
                        </span>
                        <span
                            :class="[
                                'w-36 text-right font-semibold whitespace-nowrap max-sm:w-auto',
                                value.trend > 0
                                    ? 'text-danger'
                                    : value.trend < 0
                                      ? 'text-success'
                                      : 'text-muted-foreground',
                            ]"
                        >
                            {{
                                t(':amount per 1000 km', {
                                    amount: value.costs,
                                })
                            }}
                        </span>
                    </li>
                </ul>
            </div>
        </div>
        <p
            class="border-t px-4 py-3 text-xs text-muted-foreground max-sm:text-sm"
        >
            {{
                t(
                    'Circumstances often go together, such as winter tires and the heater: a difference is not always caused by the circumstance itself.',
                )
            }}
        </p>
    </SectionCard>
</template>
