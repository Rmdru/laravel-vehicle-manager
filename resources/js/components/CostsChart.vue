<script setup lang="ts">
import { computed, ref } from 'vue';

type Series = { key: number; label: string; data: number[] };

const props = defineProps<{ labels: string[]; series: Series[] }>();

// One fixed color per series key (see DashboardService::costsChart), so a cost
// type always has the same color. The last key is "Other" and stays neutral.
const palette = [
    'var(--chart-series-1)',
    'var(--chart-series-2)',
    'var(--chart-series-3)',
    'var(--chart-series-4)',
    'var(--chart-series-5)',
    'var(--chart-series-6)',
    'var(--chart-series-7)',
    'var(--chart-series-other)',
];

const color = (series: Series) => palette[series.key] ?? palette[7];
const money = (value: number) =>
    new Intl.NumberFormat('nl-NL', {
        style: 'currency',
        currency: 'EUR',
    }).format(value);

const totals = computed(() =>
    props.labels.map((_, month) =>
        props.series.reduce((sum, series) => sum + (series.data[month] ?? 0), 0),
    ),
);

// Round the top of the axis up to a "nice" number, with four gridlines.
const max = computed(() => {
    const highest = Math.max(...totals.value, 1);
    const step = 10 ** Math.floor(Math.log10(highest));

    return Math.ceil(highest / step) * step;
});
const ticks = computed(() => [1, 0.75, 0.5, 0.25, 0].map((part) => max.value * part));

const hovered = ref<number | null>(null);
</script>

<template>
    <div v-if="series.length" class="flex flex-col gap-4">
        <ul class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
            <li
                v-for="item in series"
                :key="item.key"
                class="flex items-center gap-1.5"
            >
                <span
                    class="size-2.5 rounded-sm"
                    :style="{ background: color(item) }"
                />
                {{ item.label }}
            </li>
        </ul>

        <div class="flex h-72 gap-2">
            <div
                class="flex flex-col justify-between pb-6 text-right text-xs text-muted-foreground tabular-nums"
            >
                <span v-for="tick in ticks" :key="tick">{{
                    Math.round(tick)
                }}</span>
            </div>
            <div class="relative min-w-0 flex-1 overflow-x-auto">
                <div
                    class="flex h-full min-w-full items-stretch gap-1 border-b border-border"
                    :style="{ width: `${labels.length * 2.75}rem` }"
                    @mouseleave="hovered = null"
                >
                    <div
                        v-for="(label, month) in labels"
                        :key="label"
                        class="group relative flex min-w-0 flex-1 flex-col justify-end"
                        tabindex="0"
                        @mouseenter="hovered = month"
                        @focus="hovered = month"
                        @blur="hovered = null"
                    >
                        <div
                            class="mx-auto flex w-full max-w-8 flex-col-reverse gap-0.5 overflow-hidden rounded-t"
                            :style="{
                                height: `calc(${(totals[month] / max) * 100}% - 1.5rem)`,
                            }"
                        >
                            <div
                                v-for="item in series.filter(
                                    (item) => item.data[month] > 0,
                                )"
                                :key="item.key"
                                :style="{
                                    background: color(item),
                                    flexGrow: item.data[month],
                                }"
                                class="min-h-px basis-0"
                            />
                        </div>
                        <div
                            class="h-6 truncate pt-1 text-center text-xs text-muted-foreground"
                        >
                            {{ label }}
                        </div>

                        <div
                            v-if="hovered === month"
                            class="pointer-events-none absolute bottom-8 left-1/2 z-10 w-56 -translate-x-1/2 rounded-md border bg-popover p-2 text-xs text-popover-foreground shadow-md"
                            :class="{
                                'left-0 translate-x-0': month === 0,
                                'right-0 left-auto translate-x-0':
                                    month === labels.length - 1,
                            }"
                        >
                            <div class="mb-1 font-semibold">
                                {{ label }} · {{ money(totals[month]) }}
                            </div>
                            <div
                                v-for="item in series.filter(
                                    (item) => item.data[month] > 0,
                                )"
                                :key="item.key"
                                class="flex items-center gap-1.5"
                            >
                                <span
                                    class="size-2 rounded-sm"
                                    :style="{ background: color(item) }"
                                />
                                <span class="flex-1 truncate">{{
                                    item.label
                                }}</span>
                                <span class="tabular-nums">{{
                                    money(item.data[month])
                                }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>