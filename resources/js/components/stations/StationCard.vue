<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import { useT } from '@/composables/useT';
import { SERVICES } from '@/lib/trips';
import type {
    Station,
    StationAverages,
    StationComparison,
} from '@/types/stations';

// What is known of a gas station or charging station, in its popup on the map.
const props = defineProps<{
    station: Station;
    // The average prices by country: for a gas station without prices of its own.
    averages: StationAverages;
    // The fuel types of the app in the language of the user.
    fuelLabels: Record<string, string>;
    logoUrl: string;
    // Its prices are being asked.
    loadingPrices: boolean;
    // The usual gas station of the vehicle, to compare with.
    comparison?: StationComparison;
}>();

const { t } = useT();
const page = usePage();
const locale = computed(() => String(page.props.locale ?? 'en'));

const KINDS = {
    fuel: {
        icon: 'ic:round-local-gas-station',
        // Neutral: on the map red means an expensive gas station.
        color: '#475569',
        label: 'Gas station',
    },
    fast: { icon: 'mdi:ev-station', color: '#0891b2', label: 'Fast charger' },
    slow: {
        icon: 'mdi:ev-plug-type2',
        color: '#0d9488',
        label: 'Charging station',
    },
} as const;

const CONNECTORS: Record<string, string> = {
    ccs: 'CCS',
    chademo: 'CHAdeMO',
    type2: 'Type 2',
};

const kind = computed(() => KINDS[props.station.kind]);
const title = computed(
    () => props.station.name ?? props.station.brand ?? t(kind.value.label),
);
const brand = computed(() =>
    props.station.brand && props.station.brand !== title.value
        ? props.station.brand
        : null,
);
const logo = computed(() => {
    const brand = props.station.brand ?? props.station.name;

    return brand && props.station.kind === 'fuel'
        ? `${props.logoUrl}?brand=${encodeURIComponent(brand)}`
        : null;
});
const logoFailed = ref(false);
watch(logo, () => (logoFailed.value = false));

// How much less than the usual gas station, and up to how far a detour pays off for a full tank.
const cheaper = computed(() => {
    const comparison = props.comparison;
    const price = comparison
        ? props.station.prices.find(
              (item) => item.fuel_type === comparison.fuel_type,
          )
        : undefined;

    if (!comparison || !price || price.price >= comparison.price - 0.0005) {
        return null;
    }

    const less = comparison.price - price.price;

    return {
        cents: Math.round(less * 100),
        km: Math.floor(
            (less * comparison.tank) / (2 * comparison.costs_per_km),
        ),
        usual: comparison.usual,
    };
});

const average = computed(() =>
    props.station.country ? (props.averages[props.station.country] ?? []) : [],
);

function euros(value: number): string {
    return new Intl.NumberFormat(locale.value, {
        style: 'currency',
        currency: 'EUR',
        minimumFractionDigits: 3,
    }).format(value);
}

function day(value: string): string {
    return new Intl.DateTimeFormat(locale.value, {
        day: 'numeric',
        month: 'short',
        hour: value.length > 10 ? '2-digit' : undefined,
        minute: value.length > 10 ? '2-digit' : undefined,
    }).format(new Date(value.replace(' ', 'T')));
}

const fuel = (type: string) => props.fuelLabels[type] ?? type;
const details = computed(() => props.station.details ?? {});
</script>

<template>
    <div class="flex w-76 flex-col gap-3 p-4 text-sm">
        <div class="flex items-start gap-3 pr-5">
            <img
                v-if="logo && !logoFailed"
                :src="logo"
                alt=""
                class="size-10 shrink-0 rounded-lg border bg-white object-contain p-1"
                @error="logoFailed = true"
            />
            <span
                v-else
                class="flex size-10 shrink-0 items-center justify-center rounded-lg text-white"
                :style="{ background: kind.color }"
            >
                <AppIcon :name="kind.icon" class="size-5" />
            </span>
            <div class="min-w-0">
                <div class="leading-snug font-semibold">{{ title }}</div>
                <div class="text-xs text-muted-foreground">
                    {{ t(kind.label)
                    }}<template v-if="brand"> · {{ brand }}</template>
                </div>
            </div>
        </div>

        <!-- The prices of the gas station itself, the cheapest first. -->
        <ul v-if="station.prices.length" class="flex flex-col gap-1.5">
            <li
                v-for="price in station.prices"
                :key="price.fuel_type"
                class="flex items-start justify-between gap-2"
            >
                <span class="flex flex-col">
                    <span class="font-medium">{{ fuel(price.fuel_type) }}</span>
                    <span
                        v-if="price.updated_at"
                        class="text-xs text-muted-foreground"
                    >
                        {{ t('Since :date', { date: day(price.updated_at) }) }}
                    </span>
                </span>
                <span class="flex flex-col items-end">
                    <span class="text-base font-semibold tabular-nums">
                        {{ euros(price.price) }}
                    </span>
                    <span
                        v-if="price.served_price"
                        class="text-xs text-muted-foreground tabular-nums"
                    >
                        {{ t('Self service') }} ·
                        {{
                            t('Served: :price', {
                                price: euros(price.served_price),
                            })
                        }}
                    </span>
                    <span
                        v-else-if="price.service === 'served'"
                        class="text-xs text-muted-foreground"
                    >
                        {{ t('Served') }}
                    </span>
                </span>
            </li>
        </ul>
        <p
            v-else-if="station.kind === 'fuel' && loadingPrices && station.live"
            class="text-muted-foreground"
        >
            {{ t('Loading the prices…') }}
        </p>
        <div
            v-else-if="station.kind === 'fuel' && average.length"
            class="flex flex-col gap-1.5"
        >
            <span class="text-xs text-muted-foreground">
                {{
                    t(
                        'No price of this gas station is known: the average of the country.',
                    )
                }}
            </span>
            <div
                v-for="price in average"
                :key="price.fuel_type"
                class="flex items-center justify-between gap-2"
            >
                <span>{{ fuel(price.fuel_type) }}</span>
                <span class="text-muted-foreground tabular-nums">
                    ~ {{ euros(price.price) }}
                </span>
            </div>
        </div>

        <div
            v-if="cheaper"
            class="flex items-start gap-2 rounded-lg bg-success-muted px-3 py-2 text-xs text-success"
        >
            <AppIcon name="mdi:trending-down" class="size-4 shrink-0" />
            <span>
                {{
                    t(':cents ct less than :station', {
                        cents: cheaper.cents,
                        station: cheaper.usual,
                    })
                }}<template v-if="cheaper.km > 0"
                    >;
                    {{
                        t('a detour pays off up to :km km', { km: cheaper.km })
                    }}</template
                >
            </span>
        </div>

        <div
            v-if="station.own"
            class="flex items-center justify-between gap-2 rounded-lg bg-muted/60 px-3 py-2"
        >
            <span class="flex flex-col">
                <span class="text-xs text-muted-foreground">
                    {{
                        t('You paid here on :date', {
                            date: day(station.own.date),
                        })
                    }}
                </span>
                <span class="text-xs font-medium">{{
                    fuel(station.own.fuel_type)
                }}</span>
            </span>
            <span class="font-semibold tabular-nums">
                {{ euros(station.own.price) }}
            </span>
        </div>

        <!-- A charging station: how fast, and with what. -->
        <div
            v-if="
                station.kind !== 'fuel' &&
                (station.power || station.connectors.length)
            "
            class="flex flex-wrap gap-1.5"
        >
            <span
                v-if="station.power"
                class="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-0.5 text-xs font-semibold"
            >
                <AppIcon name="mdi:lightning-bolt" class="size-3.5" />
                {{ Math.round(station.power) }} kW
            </span>
            <span
                v-for="connector in station.connectors"
                :key="connector"
                class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs"
            >
                <AppIcon name="mdi:ev-plug-type2" class="size-3.5" />
                {{ CONNECTORS[connector] ?? connector }}
            </span>
        </div>

        <div v-if="station.services.length" class="flex flex-wrap gap-1.5">
            <span
                v-for="service in station.services"
                :key="service"
                class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs"
            >
                <AppIcon
                    :name="SERVICES[service]?.icon ?? 'mdi:plus'"
                    class="size-3.5"
                />
                {{ t(SERVICES[service]?.label ?? service) }}
            </span>
        </div>

        <dl
            v-if="
                details.fuels?.length ||
                details.opening_hours ||
                details.payments?.length ||
                details.operator
            "
            class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-xs"
        >
            <template v-if="details.fuels?.length">
                <dt class="text-muted-foreground">{{ t('Fuel') }}</dt>
                <dd>{{ details.fuels.join(', ') }}</dd>
            </template>
            <template v-if="details.opening_hours">
                <dt class="text-muted-foreground">{{ t('Opening hours') }}</dt>
                <dd class="wrap-break-word">{{ details.opening_hours }}</dd>
            </template>
            <template v-if="details.payments?.length">
                <dt class="text-muted-foreground">{{ t('Payment') }}</dt>
                <dd>
                    {{
                        details.payments.map((payment) => t(payment)).join(', ')
                    }}
                </dd>
            </template>
            <template v-if="details.operator">
                <dt class="text-muted-foreground">{{ t('Operator') }}</dt>
                <dd>{{ details.operator }}</dd>
            </template>
        </dl>

        <a
            :href="`https://www.google.com/maps/dir/?api=1&destination=${station.latitude},${station.longitude}`"
            target="_blank"
            rel="noopener"
            class="inline-flex min-h-9 items-center gap-1.5 self-start font-semibold underline"
        >
            <AppIcon name="mdi:navigation-variant" class="size-4" />
            {{ t('Navigate') }}
        </a>
    </div>
</template>
