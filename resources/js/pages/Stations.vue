<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import FilterSheet from '@/components/FilterSheet.vue';
import StationMap from '@/components/stations/StationMap.vue';
import type { StationMapState } from '@/components/stations/StationMap.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { useT } from '@/composables/useT';
import type { ShieldStyles } from '@/lib/map-theme';
import { PRICE_TIERS } from '@/lib/stations';
import type { StationFilters, StationKind } from '@/types/stations';

const props = defineProps<{
    dataUrl: string;
    pricesUrl: string;
    logoUrl: string;
    shields: ShieldStyles;
    fuelTypes: { value: string; label: string }[];
    connectors: string[];
    vehicleFuelTypes: string[];
    electric: boolean;
    stationsFrom: number;
    livePricesFrom: number;
}>();

const { t } = useT();

// The filters are kept in the browser: the map opens as it was left.
// A new version when the defaults change: else the old filters stay.
const FILTERS_KEY = 'station-map:filters:v2';

const KINDS: { value: StationKind; icon: string; label: string }[] = [
    {
        value: 'fuel',
        icon: 'ic:round-local-gas-station',
        label: 'Gas stations',
    },
    { value: 'fast', icon: 'mdi:ev-station', label: 'Fast chargers' },
    { value: 'slow', icon: 'mdi:ev-plug-type2', label: 'Slow chargers' },
];

const CONNECTORS: Record<string, string> = {
    ccs: 'CCS',
    chademo: 'CHAdeMO',
    type2: 'Type 2',
};

// To start with: the gas stations with the fuels of the vehicle and the fast
// chargers, or of an electric vehicle the fast chargers only.
// To start with: only the gas stations, with the fuels of the vehicle; of an
// electric vehicle the fast chargers only.
function defaults(): StationFilters {
    return {
        kinds: props.electric ? ['fast'] : ['fuel'],
        fuels: props.electric ? [] : [...props.vehicleFuelTypes],
        connectors: [],
        priced: false,
        primary: props.electric
            ? null
            : (props.vehicleFuelTypes[0] ?? 'Unleaded 95 (E10)'),
    };
}

function saved(): StationFilters | null {
    try {
        const filters = JSON.parse(localStorage.getItem(FILTERS_KEY) ?? 'null');

        return Array.isArray(filters?.kinds)
            ? { ...defaults(), ...filters }
            : null;
    } catch {
        return null;
    }
}

const filters = ref<StationFilters>(saved() ?? defaults());

// The primary fuel is one of the fuels filtered on: the others have no price.
const primaryOptions = computed(() =>
    filters.value.fuels.length
        ? props.fuelTypes.filter((type) =>
              filters.value.fuels.includes(type.value),
          )
        : props.fuelTypes,
);

watch(
    () => filters.value.fuels,
    (fuels) => {
        if (
            fuels.length &&
            (!filters.value.primary || !fuels.includes(filters.value.primary))
        ) {
            filters.value.primary = fuels[0];
        }
    },
);

watch(
    filters,
    (value) => {
        try {
            localStorage.setItem(FILTERS_KEY, JSON.stringify(value));
        } catch {
            // Without storage the map opens with the defaults.
        }
    },
    { deep: true },
);

function toggle<T extends string>(list: T[], value: T): T[] {
    return list.includes(value)
        ? list.filter((item) => item !== value)
        : [...list, value];
}

const active = computed(
    () =>
        filters.value.fuels.length +
        filters.value.connectors.length +
        (filters.value.priced ? 1 : 0),
);

function reset() {
    filters.value = defaults();
}

const fuelLabels = computed(() =>
    Object.fromEntries(props.fuelTypes.map((type) => [type.value, type.label])),
);

// The price beside a gas station, and its color: of the primary fuel.
const labelFuel = computed(() => filters.value.primary);
const labelFuelName = computed(() =>
    labelFuel.value
        ? (fuelLabels.value[labelFuel.value] ?? labelFuel.value)
        : null,
);

const state = ref<StationMapState | null>(null);

const status = computed(() => {
    if (!state.value) {
        return '';
    }

    if (state.value.failed) {
        return t('The stations could not be loaded.');
    }

    if (state.value.loading) {
        return t('Loading the stations of this area for the first time…');
    }

    if (state.value.mode === 'clusters') {
        return t('Zoom in to see the stations one by one.');
    }

    if (state.value.truncated) {
        return t('Not all stations are shown: zoom in further.');
    }

    return state.value.count === 1
        ? t('1 station')
        : t(':count stations', { count: state.value.count });
});
</script>

<template>
    <Head :title="t('Fuel stations')" />

    <div class="flex h-[calc(100svh-3.5rem)] flex-col">
        <div class="flex flex-wrap items-center gap-2 border-b px-4 py-2">
            <h1 class="mr-auto text-lg font-bold tracking-tight">
                {{ t('Fuel stations') }}
            </h1>
            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="kind in KINDS"
                    :key="kind.value"
                    type="button"
                    :aria-pressed="filters.kinds.includes(kind.value)"
                    :class="[
                        'inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-full border px-3 text-sm transition-colors',
                        filters.kinds.includes(kind.value)
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:bg-muted',
                    ]"
                    @click="filters.kinds = toggle(filters.kinds, kind.value)"
                >
                    <AppIcon :name="kind.icon" class="size-4" />
                    {{ t(kind.label) }}
                </button>
            </div>
            <FilterSheet :active="active" @reset="reset">
                <section
                    v-if="filters.kinds.includes('fuel')"
                    class="flex flex-col gap-2"
                >
                    <h2 class="text-sm font-semibold">
                        {{ t('Primary fuel') }}
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Its price is beside every gas station, and gives it its color: compared with the other gas stations in view.',
                            )
                        }}
                    </p>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="type in primaryOptions"
                            :key="type.value"
                            type="button"
                            :aria-pressed="filters.primary === type.value"
                            :class="[
                                'inline-flex min-h-9 cursor-pointer items-center rounded-full border px-3 text-sm transition-colors',
                                filters.primary === type.value
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:bg-muted',
                            ]"
                            @click="filters.primary = type.value"
                        >
                            {{ type.label }}
                        </button>
                    </div>
                </section>
                <section class="flex flex-col gap-2">
                    <h2 class="text-sm font-semibold">{{ t('Fuel types') }}</h2>
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Gas stations that do not tell what they sell are shown too.',
                            )
                        }}
                    </p>
                    <label
                        v-for="type in fuelTypes"
                        :key="type.value"
                        class="flex min-h-9 items-center gap-2 text-sm"
                    >
                        <Checkbox
                            :model-value="filters.fuels.includes(type.value)"
                            @update:model-value="
                                () =>
                                    (filters.fuels = toggle(
                                        filters.fuels,
                                        type.value,
                                    ))
                            "
                        />
                        {{ type.label }}
                    </label>
                </section>
                <section class="flex flex-col gap-2">
                    <h2 class="text-sm font-semibold">{{ t('Connectors') }}</h2>
                    <label
                        v-for="connector in connectors"
                        :key="connector"
                        class="flex min-h-9 items-center gap-2 text-sm"
                    >
                        <Checkbox
                            :model-value="
                                filters.connectors.includes(connector)
                            "
                            @update:model-value="
                                () =>
                                    (filters.connectors = toggle(
                                        filters.connectors,
                                        connector,
                                    ))
                            "
                        />
                        {{ CONNECTORS[connector] ?? connector }}
                    </label>
                </section>
                <label class="flex min-h-9 items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="filters.priced"
                        @update:model-value="
                            (checked) => (filters.priced = checked === true)
                        "
                    />
                    {{ t('Only gas stations with a price') }}
                </label>
            </FilterSheet>
        </div>

        <div class="relative flex-1">
            <StationMap
                :filters="filters"
                :label-fuel="labelFuel"
                :fuel-labels="fuelLabels"
                :data-url="dataUrl"
                :prices-url="pricesUrl"
                :logo-url="logoUrl"
                :shields="shields"
                :stations-from="stationsFrom"
                :live-prices-from="livePricesFrom"
                @state="(value) => (state = value)"
            />
            <div
                v-if="status"
                class="pointer-events-none absolute top-3 left-1/2 max-w-[calc(100%-2rem)] -translate-x-1/2 rounded-full border bg-card/95 px-3 py-1.5 text-center text-xs font-medium shadow"
            >
                {{ status }}
            </div>
            <!-- What the colors of the gas stations mean. -->
            <div
                v-if="
                    state?.mode === 'stations' &&
                    filters.kinds.includes('fuel') &&
                    labelFuelName
                "
                class="pointer-events-none absolute bottom-8 left-3 flex flex-col gap-1 rounded-lg border bg-card/95 px-3 py-2 text-xs shadow"
            >
                <span class="font-medium">{{ labelFuelName }}</span>
                <!-- Nothing to compare: say why, rather than colors none of them have. -->
                <span
                    v-if="!state.priced"
                    class="max-w-56 text-muted-foreground"
                >
                    {{
                        t(
                            'No prices of these gas stations are known: in some countries, such as the Netherlands and Belgium, no free source of prices per gas station exists.',
                        )
                    }}
                </span>
                <template v-else>
                    <span
                        v-for="(tier, key) in PRICE_TIERS"
                        :key="key"
                        class="flex items-center gap-1.5"
                    >
                        <span
                            class="size-2.5 rounded-full"
                            :style="{ background: tier.color }"
                        />
                        {{ t(tier.label) }}
                    </span>
                </template>
            </div>
        </div>
    </div>
</template>
