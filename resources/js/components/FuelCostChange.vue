<script setup lang="ts">
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useT } from '@/composables/useT';

export type FuelCostChangeData = {
    period: string;
    compared: string;
    // Compared with the same months a year before, rather than the months before.
    sameSeason: boolean;
    // The fuel costs per month, now and then.
    now: string;
    before: string;
    difference: string;
    // 1: the costs went up, -1: down.
    trend: number;
    perYear: string;
    // What the price, the distance and the consumption did: they add up to the difference.
    parts: {
        key: string;
        label: string;
        icon: string;
        detail: string;
        amount: string;
        value: number;
        trend: number;
    }[];
};

const props = defineProps<{ data: FuelCostChangeData }>();

const { t } = useT();

const largest = computed(() =>
    Math.max(...props.data.parts.map((part) => Math.abs(part.value)), 0.01),
);

// More costs is bad, less is good.
const tone = (trend: number) =>
    trend > 0
        ? 'text-danger'
        : trend < 0
          ? 'text-success'
          : 'text-muted-foreground';
</script>

<template>
    <SectionCard
        :title="t('Why the fuel costs changed')"
        :badge="data.period"
        icon="mdi:chart-waterfall"
    >
        <div class="flex flex-col gap-4">
            <div
                class="flex flex-wrap items-end justify-between gap-x-6 gap-y-2"
            >
                <div>
                    <div class="text-sm text-muted-foreground">
                        {{ t('Fuel costs per month') }}
                    </div>
                    <div class="text-2xl font-bold tracking-tight tabular-nums">
                        {{ data.now }}
                    </div>
                    <div class="text-sm text-muted-foreground tabular-nums">
                        {{ t('was :amount', { amount: data.before }) }}
                    </div>
                </div>
                <div class="text-right">
                    <div
                        :class="[
                            'text-xl font-semibold tabular-nums',
                            tone(data.trend),
                        ]"
                    >
                        {{ data.difference }}
                    </div>
                    <div class="text-sm text-muted-foreground tabular-nums">
                        {{ t(':amount per year', { amount: data.perYear }) }}
                    </div>
                </div>
            </div>

            <ul class="flex flex-col gap-3">
                <li
                    v-for="part in data.parts"
                    :key="part.key"
                    class="flex flex-col gap-1.5"
                >
                    <div class="flex items-start gap-2 text-sm">
                        <AppIcon
                            :name="part.icon"
                            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="font-medium">{{ part.label }}</span>
                            <span
                                class="text-xs text-muted-foreground tabular-nums max-sm:text-sm"
                            >
                                {{ part.detail }}
                            </span>
                        </span>
                        <span
                            :class="[
                                'font-semibold whitespace-nowrap tabular-nums',
                                tone(part.trend),
                            ]"
                        >
                            {{ part.amount }}
                        </span>
                    </div>
                    <div
                        class="h-1.5 overflow-hidden rounded-full bg-muted"
                        aria-hidden="true"
                    >
                        <div
                            :class="[
                                'h-full rounded-full',
                                part.trend > 0
                                    ? 'bg-danger'
                                    : part.trend < 0
                                      ? 'bg-success'
                                      : 'bg-muted-foreground',
                            ]"
                            :style="{
                                width: `${Math.max((Math.abs(part.value) / largest) * 100, 1.5)}%`,
                            }"
                        />
                    </div>
                </li>
            </ul>

            <p class="text-xs text-muted-foreground max-sm:text-sm">
                {{
                    data.sameSeason
                        ? t(
                              'Compared with the same months a year before (:period). Together the parts make up the difference.',
                              { period: data.compared },
                          )
                        : t(
                              'Compared with the months before (:period). Together the parts make up the difference.',
                              { period: data.compared },
                          )
                }}
            </p>
        </div>
    </SectionCard>
</template>
