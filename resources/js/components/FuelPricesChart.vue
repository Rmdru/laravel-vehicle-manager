<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CountryFlag from '@/components/CountryFlag.vue';

type Point = { date: string; price: number };
type Series = { key: number; label: string; points: Point[] };
type Country = {
    key: string;
    name: string;
    isoCode: string | null;
    series: Series[];
};

const props = defineProps<{
    countries: Country[];
    // The country the chart opens on, when it has prices.
    home?: string | null;
}>();

const locale = usePage().props.locale;

const selected = ref<string | null>(null);

// The chosen country can drop out when the period changes: fall back to the
// home country, or else the first.
const country = computed(
    () =>
        props.countries.find((item) => item.key === selected.value) ??
        props.countries.find((item) => item.key === props.home) ??
        props.countries[0],
);

// One fixed color per series key (see DashboardService::fuelPricesChart), so a
// fuel type has the same color in every country and period.
const color = (series: Series) =>
    `var(--chart-series-${(series.key % 14) + 1})`;

const money = (value: number, digits = 3) =>
    new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: 'EUR',
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(value);

const time = (date: string) => new Date(`${date}T00:00:00`).getTime();

const day = (date: string) =>
    new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(time(date));

const dates = computed(() =>
    [
        ...new Set(
            country.value.series.flatMap((series) =>
                series.points.map((point) => point.date),
            ),
        ),
    ].sort(),
);

// Horizontal position of a date, in percent of the plot.
function x(date: string) {
    const first = time(dates.value[0]);
    const span = time(dates.value[dates.value.length - 1]) - first;

    return span ? ((time(date) - first) / span) * 100 : 50;
}

// The axis runs between "nice" prices around the lowest and the highest one.
const axis = computed(() => {
    const prices = country.value.series.flatMap((series) =>
        series.points.map((point) => point.price),
    );
    const lowest = Math.min(...prices);
    const highest = Math.max(...prices);
    const step =
        [0.01, 0.02, 0.05, 0.1, 0.2, 0.25, 0.5, 1].find(
            (step) => (highest - lowest) / step <= 5,
        ) ?? 1;
    const from = Math.floor(lowest / step + 1e-9) * step;
    const until = Math.max(
        Math.ceil(highest / step - 1e-9) * step,
        from + step,
    );
    const ticks = Array.from(
        { length: Math.round((until - from) / step) + 1 },
        (_, index) => until - index * step,
    );

    return { from, until, ticks };
});

// Vertical position of a price, in percent of the plot.
const y = (price: number) =>
    ((axis.value.until - price) / (axis.value.until - axis.value.from)) * 100;

const path = (series: Series) =>
    series.points
        .map(
            (point, index) =>
                `${index ? 'L' : 'M'}${x(point.date)} ${y(point.price)}`,
        )
        .join(' ');

const hovered = ref<string | null>(null);

// The prices of every fuel type on the day the pointer is closest to.
const readout = computed(() =>
    hovered.value === null
        ? []
        : country.value.series.flatMap((series) => {
              const point = series.points.find(
                  (point) => point.date === hovered.value,
              );

              return point ? [{ series, point }] : [];
          }),
);

function hover(event: PointerEvent) {
    const plot = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const position = ((event.clientX - plot.left) / plot.width) * 100;

    hovered.value = dates.value.reduce((nearest, date) =>
        Math.abs(x(date) - position) < Math.abs(x(nearest) - position)
            ? date
            : nearest,
    );
}

// With the keyboard: the arrow keys walk through the days.
function step(days: number) {
    const index =
        hovered.value === null
            ? dates.value.length - 1
            : dates.value.indexOf(hovered.value) + days;

    hovered.value =
        dates.value[Math.min(Math.max(index, 0), dates.value.length - 1)];
}

function select(key: string) {
    selected.value = key;
    hovered.value = null;
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div role="tablist" class="flex flex-wrap gap-2">
            <button
                v-for="item in countries"
                :key="item.key"
                type="button"
                role="tab"
                :aria-selected="item.key === country.key"
                :class="[
                    'inline-flex min-h-9 cursor-pointer items-center gap-2 rounded-full border py-1 pr-3 pl-1.5 text-sm font-medium transition',
                    item.key === country.key
                        ? 'border-transparent bg-primary text-primary-foreground'
                        : 'border-input text-muted-foreground hover:border-ring/50 hover:text-foreground',
                ]"
                @click="select(item.key)"
            >
                <CountryFlag v-if="item.isoCode" :iso-code="item.isoCode" />
                {{ item.name }}
            </button>
        </div>

        <ul
            class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground"
        >
            <li
                v-for="series in country.series"
                :key="series.key"
                class="flex items-center gap-1.5"
            >
                <span
                    class="h-0.5 w-3.5 rounded-full"
                    :style="{ background: color(series) }"
                />
                {{ series.label }}
            </li>
        </ul>

        <div class="flex h-64 gap-2 py-2">
            <div
                class="relative w-12 shrink-0 text-right text-xs text-muted-foreground tabular-nums"
            >
                <span
                    v-for="tick in axis.ticks"
                    :key="tick"
                    class="absolute right-0 -translate-y-1/2"
                    :style="{ top: `${y(tick)}%` }"
                >
                    {{ money(tick, 2) }}
                </span>
            </div>
            <div
                class="relative min-w-0 flex-1 touch-pan-y outline-none focus-visible:ring-2 focus-visible:ring-ring"
                tabindex="0"
                @pointermove="hover"
                @pointerdown="hover"
                @pointerleave="hovered = null"
                @keydown.left.prevent="step(-1)"
                @keydown.right.prevent="step(1)"
                @blur="hovered = null"
            >
                <div
                    v-for="tick in axis.ticks"
                    :key="tick"
                    class="absolute inset-x-0 border-t border-border/60"
                    :style="{ top: `${y(tick)}%` }"
                />

                <svg
                    class="absolute inset-0 size-full overflow-visible"
                    viewBox="0 0 100 100"
                    preserveAspectRatio="none"
                    aria-hidden="true"
                >
                    <path
                        v-for="series in country.series"
                        :key="series.key"
                        :d="path(series)"
                        :stroke="color(series)"
                        fill="none"
                        stroke-width="2"
                        stroke-linejoin="round"
                        stroke-linecap="round"
                        vector-effect="non-scaling-stroke"
                    />
                </svg>

                <!-- A single price is no line yet: mark it with a dot. -->
                <template v-for="series in country.series" :key="series.key">
                    <span
                        v-if="series.points.length === 1"
                        class="absolute size-2.5 -translate-1/2 rounded-full ring-2 ring-card"
                        :style="{
                            left: `${x(series.points[0].date)}%`,
                            top: `${y(series.points[0].price)}%`,
                            background: color(series),
                        }"
                    />
                </template>

                <template v-if="hovered !== null">
                    <div
                        class="pointer-events-none absolute inset-y-0 w-px bg-foreground/30"
                        :style="{ left: `${x(hovered)}%` }"
                    />
                    <span
                        v-for="{ series, point } in readout"
                        :key="series.key"
                        class="pointer-events-none absolute size-2.5 -translate-1/2 rounded-full ring-2 ring-card"
                        :style="{
                            left: `${x(point.date)}%`,
                            top: `${y(point.price)}%`,
                            background: color(series),
                        }"
                    />
                    <div
                        class="pointer-events-none absolute top-0 z-10 w-52 rounded-md border bg-popover p-2 text-xs text-popover-foreground shadow-md"
                        :style="
                            x(hovered) > 50
                                ? {
                                      right: `calc(${100 - x(hovered)}% + 0.75rem)`,
                                  }
                                : { left: `calc(${x(hovered)}% + 0.75rem)` }
                        "
                    >
                        <div class="mb-1 font-semibold">{{ day(hovered) }}</div>
                        <div
                            v-for="{ series, point } in readout"
                            :key="series.key"
                            class="flex items-center gap-1.5"
                        >
                            <span
                                class="h-0.5 w-3 shrink-0 rounded-full"
                                :style="{ background: color(series) }"
                            />
                            <span class="font-semibold tabular-nums">
                                {{ money(point.price) }}
                            </span>
                            <span class="truncate text-muted-foreground">
                                {{ series.label }}
                            </span>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div
            class="-mt-3 ml-14 flex text-xs text-muted-foreground"
            :class="dates.length > 1 ? 'justify-between' : 'justify-center'"
        >
            <span>{{ day(dates[0]) }}</span>
            <span v-if="dates.length > 1">
                {{ day(dates[dates.length - 1]) }}
            </span>
        </div>
    </div>
</template>
