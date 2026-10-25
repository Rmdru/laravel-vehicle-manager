<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Maximize2, Minimize2, RefreshCw, Sparkles } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import BrandLogo from '@/components/BrandLogo.vue';
import ColorBadge from '@/components/ColorBadge.vue';
import SectionCard from '@/components/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useT } from '@/composables/useT';

type CostKind =
    | 'fuel'
    | 'road_tax'
    | 'insurance'
    | 'maintenance'
    | 'depreciation';

type Car = {
    key: string;
    // The vehicle itself, the others are its alternatives.
    current: boolean;
    powertrain: string;
    powertrainName: string;
    brand: string;
    brandLogo: string | null;
    model: string;
    version: string | null;
    bodyType: string | null;
    buildYear: number;
    powerHp: number;
    // Used, of the build year: for the vehicle itself what it is worth now.
    priceNow: number;
    purchasePrice: number | null;
    // In practice: an electric range at 3/5 of the WLTP range.
    range: { total: number; electric: number | null; fuel: number | null };
    consumption: {
        fuel: number | null;
        fuelUnit: string | null;
        electricity: number | null;
        // A plug-in hybrid: the part of the kilometers driven electric.
        electricShare: number | null;
        // An electric vehicle: the part of the energy fast charged.
        fastShare: number | null;
    };
    consumptionEstimated: boolean;
    // What the energy costs by the liter, kilogram or kWh.
    prices: {
        type: string;
        label: string;
        price: number;
        unit: string;
        estimated: boolean;
    }[];
    // A year; estimated by the AI model, or from the records of the vehicle.
    costs: Record<CostKind, { year: number; estimated: boolean }>;
    reason: string | null;
};

export type PowertrainComparisonData = {
    comparison: {
        cars: Car[];
        kmPerYear: number;
        kmEstimated: boolean;
        drivingStyle: number;
        pricesDate: string | null;
        calculatedAt: string;
        model: string;
        chosenAt: string;
    } | null;
    // The alternatives are being chosen (again).
    pending: boolean;
    // Choosing them failed, and there are none yet.
    failed: boolean;
    canChoose: boolean;
    chooseUrl: string;
    refreshUrl: string;
};

const props = defineProps<{ data: PowertrainComparisonData }>();

const { t } = useT();
const locale = computed(() => usePage().props.locale);

// The driving costs by default; the total adds the depreciation.
const view = ref<'driving' | 'total'>('driving');
const kinds = computed<CostKind[]>(() =>
    view.value === 'total'
        ? ['fuel', 'road_tax', 'insurance', 'maintenance', 'depreciation']
        : ['fuel', 'road_tax', 'insurance', 'maintenance'],
);
const labels = computed<Record<CostKind, string>>(() => ({
    fuel: t('Fuel'),
    road_tax: t('Road tax'),
    insurance: t('Insurance'),
    maintenance: t('Maintenance'),
    depreciation: t('Depreciation'),
}));

const cars = computed(() => props.data.comparison?.cars ?? []);
const monthly = (car: Car) =>
    kinds.value.reduce((sum, kind) => sum + car.costs[kind].year, 0) / 12;
const own = computed(() => cars.value.find((car) => car.current));
const cheapest = computed(() =>
    cars.value.reduce<Car | null>(
        (best, car) => (best && monthly(best) <= monthly(car) ? best : car),
        null,
    ),
);

const euros = (amount: number, digits = 0) =>
    new Intl.NumberFormat(locale.value, {
        style: 'currency',
        currency: 'EUR',
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(amount);
const number = (value: number, digits = 0) =>
    new Intl.NumberFormat(locale.value, {
        maximumFractionDigits: digits,
    }).format(value);
const percent = (share: number) =>
    new Intl.NumberFormat(locale.value, { style: 'percent' }).format(share);

// The icon of what a vehicle refuels: gas comes from a cylinder, hydrogen from a station of its own.
function fuelIcon(car: Car): string {
    if (/lpg|cng/.test(car.powertrain)) {
        return 'mdi:gas-cylinder';
    }

    return car.powertrain === 'hydrogen'
        ? 'mdi:hydrogen-station'
        : 'ic:round-local-gas-station';
}

function difference(car: Car): string {
    const amount = Math.round(monthly(car) - monthly(own.value!));

    return amount === 0
        ? t('The same')
        : `${amount > 0 ? '+' : '−'}${euros(Math.abs(amount))}`;
}

// While the alternatives are chosen, they are asked for again now and then:
// it takes a minute, and the page should not have to be reloaded for it.
const POLL_EVERY = 6000;
const POLL_TIMES = 30;
let polls = 0;
let timer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => props.data.pending,
    (pending) => {
        clearTimeout(timer);

        if (!pending || polls >= POLL_TIMES) {
            return;
        }

        timer = setTimeout(() => {
            polls++;
            router.reload({ only: ['powertrainComparison'] });
        }, POLL_EVERY);
    },
    { immediate: true },
);

// Enlarged over the whole screen; Escape makes it small again, and the page
// behind it does not scroll meanwhile.
const expanded = ref(false);

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        expanded.value = false;
    }
}

watch(expanded, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';

    if (open) {
        window.addEventListener('keydown', onKeydown);
    } else {
        window.removeEventListener('keydown', onKeydown);
    }
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    expanded.value = false;
    document.body.style.overflow = '';
    window.removeEventListener('keydown', onKeydown);
});

const busy = ref(false);

function post(url: string) {
    polls = 0;
    router.post(
        url,
        {},
        {
            preserveScroll: true,
            only: ['powertrainComparison'],
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
        },
    );
}
</script>

<template>
    <SectionCard
        :title="t('Alternative powertrains')"
        icon="mdi:car-search"
        collapsible
        persist-key="powertrain-comparison"
        flush
    >
        <template #actions>
            <Button
                v-if="data.comparison"
                variant="ghost"
                size="icon"
                :aria-label="t('Enlarge')"
                :title="t('Enlarge')"
                @click="expanded = true"
            >
                <Maximize2 class="size-4" />
            </Button>
            <Button
                v-if="data.comparison"
                variant="ghost"
                size="icon"
                :disabled="busy"
                :aria-label="t('Calculate the costs again')"
                :title="t('Calculate the costs again')"
                @click="post(data.refreshUrl)"
            >
                <RefreshCw :class="['size-4', busy ? 'animate-spin' : '']" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                :disabled="busy || data.pending || !data.canChoose"
                :aria-label="t('Choose the alternatives again')"
                :title="t('Choose the alternatives again')"
                @click="post(data.chooseUrl)"
            >
                <Sparkles class="size-4" />
            </Button>
        </template>

        <div
            v-if="!data.comparison && data.pending"
            class="flex flex-col gap-2 p-4"
            :aria-label="t('The alternatives are being chosen…')"
        >
            <p class="text-sm text-muted-foreground">
                {{ t('The alternatives are being chosen…') }}
            </p>
            <Skeleton class="h-40 w-full" />
        </div>
        <p
            v-else-if="!data.comparison"
            class="p-4 text-sm text-muted-foreground"
        >
            {{
                data.failed
                    ? t(
                          'The alternatives could not be chosen. Please try again later.',
                      )
                    : t(
                          'Compare your vehicle with an alternative per powertrain, on your own use.',
                      )
            }}
        </p>

        <!-- Enlarged, the comparison takes the whole screen, with narrower columns: as many cars side by side as fit. -->
        <Teleport v-else to="body" :disabled="!expanded">
            <div
                :class="
                    expanded
                        ? 'fixed inset-0 z-50 flex flex-col bg-card'
                        : 'contents'
                "
                :role="expanded ? 'dialog' : undefined"
                :aria-modal="expanded ? true : undefined"
                :aria-label="
                    expanded ? t('Alternative powertrains') : undefined
                "
            >
                <div
                    v-if="expanded"
                    class="flex min-h-13 items-center gap-2 border-b py-1 pr-2 pl-4"
                >
                    <h2 class="flex-1 font-semibold">
                        {{ t('Alternative powertrains') }}
                    </h2>
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="t('Shrink')"
                        :title="t('Shrink')"
                        @click="expanded = false"
                    >
                        <Minimize2 class="size-4" />
                    </Button>
                </div>
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
                >
                    <div
                        class="flex rounded-lg bg-muted p-0.5 text-xs font-semibold"
                    >
                        <button
                            v-for="option in [
                                { value: 'driving', label: t('Driving costs') },
                                {
                                    value: 'total',
                                    label: t('Total incl. depreciation'),
                                },
                            ] as const"
                            :key="option.value"
                            type="button"
                            :aria-pressed="view === option.value"
                            :class="[
                                'min-h-8 cursor-pointer rounded-md px-2.5 transition pointer-coarse:min-h-10',
                                view === option.value
                                    ? 'bg-card text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground',
                            ]"
                            @click="view = option.value"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                    <span
                        v-if="data.pending"
                        class="text-xs text-muted-foreground max-sm:text-sm"
                    >
                        {{ t('The alternatives are being chosen…') }}
                    </span>
                </div>

                <!-- Wide by nature: scrolls sideways within the card, with the labels in view. -->
                <div
                    :class="
                        expanded ? 'flex-1 overflow-auto' : 'overflow-x-auto'
                    "
                >
                    <table class="w-full text-sm tabular-nums">
                        <thead>
                            <tr class="align-top">
                                <th
                                    class="sticky left-0 z-10 w-32 min-w-32 bg-card px-4 py-3"
                                />
                                <th
                                    v-for="car in cars"
                                    :key="car.key"
                                    :class="[
                                        'px-3 py-3 text-left font-normal',
                                        expanded ? 'min-w-32' : 'min-w-44',
                                        car.key === cheapest?.key
                                            ? 'bg-success-muted/60'
                                            : '',
                                    ]"
                                >
                                    <div class="flex flex-wrap gap-1">
                                        <ColorBadge
                                            :color="
                                                car.current ? 'info' : 'gray'
                                            "
                                        >
                                            {{
                                                car.current
                                                    ? t('Your vehicle')
                                                    : car.powertrainName
                                            }}
                                        </ColorBadge>
                                        <ColorBadge
                                            v-if="car.key === cheapest?.key"
                                            color="success"
                                        >
                                            {{ t('Cheapest') }}
                                        </ColorBadge>
                                    </div>
                                    <div class="mt-2 flex items-center gap-2">
                                        <BrandLogo
                                            :name="car.brandLogo"
                                            class="size-7 shrink-0"
                                        />
                                        <span
                                            class="leading-tight font-semibold"
                                        >
                                            {{ car.brand }} {{ car.model }}
                                        </span>
                                    </div>
                                    <div
                                        class="mt-1 text-xs leading-snug text-muted-foreground max-sm:text-sm"
                                    >
                                        {{ car.version }}
                                    </div>
                                    <div
                                        class="text-xs text-muted-foreground max-sm:text-sm"
                                    >
                                        <template v-if="car.current">
                                            {{ car.powertrainName }} ·
                                        </template>
                                        <template v-if="car.bodyType">
                                            {{ car.bodyType }} ·
                                        </template>
                                        {{ car.buildYear }} ·
                                        {{ number(car.powerHp) }} {{ t('hp') }}
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-t">
                                <th
                                    scope="row"
                                    class="sticky left-0 z-10 bg-card px-4 py-2.5 text-left text-xs font-semibold text-muted-foreground"
                                >
                                    {{ t('Used price now') }}
                                </th>
                                <td
                                    v-for="car in cars"
                                    :key="car.key"
                                    class="px-3 py-2.5"
                                >
                                    ≈ {{ euros(car.priceNow) }}
                                    <div
                                        v-if="car.purchasePrice"
                                        class="text-xs text-muted-foreground max-sm:text-sm"
                                    >
                                        {{
                                            t('Bought for :price', {
                                                price: euros(car.purchasePrice),
                                            })
                                        }}
                                    </div>
                                </td>
                            </tr>
                            <tr class="border-t">
                                <th
                                    scope="row"
                                    class="sticky left-0 z-10 bg-card px-4 py-2.5 text-left text-xs font-semibold text-muted-foreground"
                                >
                                    {{ t('Range') }}
                                </th>
                                <td
                                    v-for="car in cars"
                                    :key="car.key"
                                    class="px-3 py-2.5"
                                >
                                    <!-- Both electric and on fuel: the total, with each part below it. -->
                                    <div
                                        v-if="
                                            car.range.electric && car.range.fuel
                                        "
                                    >
                                        {{ number(car.range.total) }} km
                                    </div>
                                    <div
                                        :class="[
                                            'flex flex-col',
                                            car.range.electric && car.range.fuel
                                                ? 'text-xs text-muted-foreground'
                                                : '',
                                        ]"
                                    >
                                        <span
                                            v-if="car.range.electric"
                                            class="flex items-center gap-1.5"
                                            :title="t('Electric')"
                                        >
                                            <AppIcon
                                                name="mdi:lightning-bolt"
                                                class="size-3.5 shrink-0 text-muted-foreground"
                                            />
                                            {{ number(car.range.electric) }} km
                                        </span>
                                        <span
                                            v-if="car.range.fuel"
                                            class="flex items-center gap-1.5"
                                            :title="t('Fuel')"
                                        >
                                            <AppIcon
                                                :name="fuelIcon(car)"
                                                class="size-3.5 shrink-0 text-muted-foreground"
                                            />
                                            {{ number(car.range.fuel) }} km
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            <tr class="border-t">
                                <th
                                    scope="row"
                                    class="sticky left-0 z-10 bg-card px-4 py-2.5 text-left text-xs font-semibold text-muted-foreground"
                                >
                                    {{ t('Consumption') }}
                                </th>
                                <td
                                    v-for="car in cars"
                                    :key="car.key"
                                    class="px-3 py-2.5 whitespace-nowrap"
                                >
                                    <!-- The estimate sign on the first line, whichever that is. -->
                                    <div
                                        v-if="car.consumption.fuel"
                                        class="flex items-center gap-1.5"
                                        :title="t('Fuel')"
                                    >
                                        <AppIcon
                                            :name="fuelIcon(car)"
                                            class="size-3.5 shrink-0 text-muted-foreground"
                                        />
                                        <span>
                                            <template
                                                v-if="car.consumptionEstimated"
                                                >≈
                                            </template>
                                            {{
                                                number(car.consumption.fuel, 1)
                                            }}
                                            {{ car.consumption.fuelUnit }}
                                        </span>
                                    </div>
                                    <div
                                        v-if="car.consumption.electricity"
                                        class="flex items-center gap-1.5"
                                        :title="t('Electric')"
                                    >
                                        <AppIcon
                                            name="mdi:lightning-bolt"
                                            class="size-3.5 shrink-0 text-muted-foreground"
                                        />
                                        <span>
                                            <template
                                                v-if="
                                                    car.consumptionEstimated &&
                                                    !car.consumption.fuel
                                                "
                                                >≈
                                            </template>
                                            {{
                                                number(
                                                    car.consumption.electricity,
                                                    1,
                                                )
                                            }}
                                            kWh/100km
                                        </span>
                                    </div>
                                    <div
                                        v-if="
                                            car.consumption.electricShare !==
                                            null
                                        "
                                        class="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground max-sm:text-sm"
                                        :title="
                                            t(':share driven electric', {
                                                share: percent(
                                                    car.consumption
                                                        .electricShare,
                                                ),
                                            })
                                        "
                                    >
                                        <AppIcon
                                            name="mdi:car-electric"
                                            class="size-3.5 shrink-0"
                                        />
                                        {{
                                            t(':share driven electric', {
                                                share: percent(
                                                    car.consumption
                                                        .electricShare,
                                                ),
                                            })
                                        }}
                                    </div>
                                    <div
                                        v-if="
                                            car.consumption.fastShare !== null
                                        "
                                        class="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground max-sm:text-sm"
                                    >
                                        <AppIcon
                                            name="mdi:ev-station"
                                            class="size-3.5 shrink-0"
                                        />
                                        {{
                                            t(':share fast charged', {
                                                share: percent(
                                                    car.consumption.fastShare,
                                                ),
                                            })
                                        }}
                                    </div>
                                </td>
                            </tr>
                            <tr class="border-t">
                                <th
                                    scope="row"
                                    class="sticky left-0 z-10 bg-card px-4 py-2.5 text-left text-xs font-semibold text-muted-foreground"
                                >
                                    {{ t('Fuel price') }}
                                </th>
                                <td
                                    v-for="car in cars"
                                    :key="car.key"
                                    class="px-3 py-2.5 whitespace-nowrap"
                                >
                                    <div
                                        v-for="price in car.prices"
                                        :key="price.type"
                                    >
                                        <template v-if="price.estimated"
                                            >≈
                                        </template>
                                        {{ euros(price.price, 2) }}/{{
                                            price.unit
                                        }}
                                        <span
                                            class="text-xs text-muted-foreground max-sm:text-sm"
                                        >
                                            {{ price.label }}
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            <tr
                                v-for="kind in kinds"
                                :key="kind"
                                class="border-t"
                            >
                                <th
                                    scope="row"
                                    class="sticky left-0 z-10 bg-card px-4 py-2.5 text-left text-xs font-semibold text-muted-foreground"
                                >
                                    {{ labels[kind] }}
                                    <span class="font-normal">{{
                                        t('per month')
                                    }}</span>
                                </th>
                                <td
                                    v-for="car in cars"
                                    :key="car.key"
                                    class="px-3 py-2.5 whitespace-nowrap"
                                >
                                    <template v-if="car.costs[kind].estimated"
                                        >≈
                                    </template>
                                    {{ euros(car.costs[kind].year / 12) }}
                                </td>
                            </tr>
                            <tr class="border-t-2">
                                <th
                                    scope="row"
                                    class="sticky left-0 z-10 bg-card px-4 py-3 text-left font-semibold"
                                >
                                    {{ t('Total per month') }}
                                </th>
                                <td
                                    v-for="car in cars"
                                    :key="car.key"
                                    :class="[
                                        'px-3 py-3 text-base font-bold whitespace-nowrap',
                                        car.key === cheapest?.key
                                            ? 'bg-success-muted/60 text-success'
                                            : '',
                                    ]"
                                >
                                    {{ euros(monthly(car)) }}
                                </td>
                            </tr>
                            <tr class="border-t">
                                <th
                                    scope="row"
                                    class="sticky left-0 z-10 bg-card px-4 py-2.5 text-left text-xs font-semibold text-muted-foreground"
                                >
                                    {{ t('Per km') }}
                                </th>
                                <td
                                    v-for="car in cars"
                                    :key="car.key"
                                    class="px-3 py-2.5 whitespace-nowrap"
                                >
                                    {{
                                        euros(
                                            (monthly(car) * 12) /
                                                data.comparison.kmPerYear,
                                            2,
                                        )
                                    }}
                                </td>
                            </tr>
                            <tr class="border-t">
                                <th
                                    scope="row"
                                    class="sticky left-0 z-10 bg-card px-4 py-2.5 text-left text-xs font-semibold text-muted-foreground"
                                >
                                    {{ t('Against your vehicle') }}
                                </th>
                                <td
                                    v-for="car in cars"
                                    :key="car.key"
                                    :class="[
                                        'px-3 py-2.5 font-semibold whitespace-nowrap',
                                        car.current
                                            ? 'text-muted-foreground'
                                            : monthly(car) < monthly(own!)
                                              ? 'text-success'
                                              : monthly(car) > monthly(own!)
                                                ? 'text-danger'
                                                : '',
                                    ]"
                                >
                                    {{
                                        car.current
                                            ? '—'
                                            : `${difference(car)} ${t('per month')}`
                                    }}
                                </td>
                            </tr>
                            <tr class="border-t align-top">
                                <th
                                    scope="row"
                                    class="sticky left-0 z-10 bg-card px-4 py-2.5 text-left text-xs font-semibold text-muted-foreground"
                                >
                                    {{ t('Why this one') }}
                                </th>
                                <td
                                    v-for="car in cars"
                                    :key="car.key"
                                    :class="[
                                        'max-w-56 px-3 py-2.5 text-xs leading-snug whitespace-normal text-muted-foreground max-sm:text-sm',
                                        expanded ? 'min-w-32' : 'min-w-44',
                                    ]"
                                >
                                    {{ car.reason ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </Teleport>
    </SectionCard>
</template>
