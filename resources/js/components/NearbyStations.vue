<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import ColorBadge from '@/components/ColorBadge.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useT } from '@/composables/useT';

export type NearbyStationsData = {
    // Without a home on the map there is nothing to look around.
    missingHome: boolean;
    profileUrl?: string;
    fuelType?: string | null;
    // The gas station the vehicle usually refuels at, and what it costs now as far as it is known.
    usual?: { name: string; price: number | null } | null;
    radiusKm?: number;
    mapUrl?: string;
    // The fuel shown, the ones to choose from, and where another one is asked.
    selected?: string;
    fuelOptions?: { value: string; label: string }[];
    url?: string;
    stations: {
        id: number;
        name: string;
        address: string | null;
        distanceKm: number;
        price: number;
        servedPrice: number | null;
        // Less (negative) or more than at the usual gas station, per liter.
        difference: number | null;
        // Up to how far a detour for it pays off, for a full tank.
        detourKm: number | null;
        logo: string | null;
    }[];
};

const props = defineProps<{ data: NearbyStationsData }>();

const { t } = useT();

// The fuel chosen is kept in the browser: the widget shows it again next time.
const FUEL_KEY = 'nearby-stations:fuel';

const current = ref<NearbyStationsData>(props.data);
const loading = ref(false);
watch(
    () => props.data,
    (data) => (current.value = data),
);

async function choose(fuelType: string) {
    if (!current.value.url || fuelType === current.value.selected) {
        return;
    }

    try {
        localStorage.setItem(FUEL_KEY, fuelType);
    } catch {
        // Without storage it starts at Euro 95 (E10) next time.
    }

    loading.value = true;

    try {
        const response = await fetch(
            `${current.value.url}?fuel_type=${encodeURIComponent(fuelType)}`,
            { headers: { Accept: 'application/json' } },
        );

        if (response.ok) {
            current.value = await response.json();
        }
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    try {
        const saved = localStorage.getItem(FUEL_KEY);

        if (
            saved &&
            current.value.fuelOptions?.some((option) => option.value === saved)
        ) {
            choose(saved);
        }
    } catch {
        // Without storage: Euro 95 (E10).
    }
});
const page = usePage();
const locale = computed(() => String(page.props.locale ?? 'en'));
const failedLogos = ref(new Set<number>());

function euros(value: number): string {
    return new Intl.NumberFormat(locale.value, {
        style: 'currency',
        currency: 'EUR',
        minimumFractionDigits: 3,
    }).format(value);
}
</script>

<template>
    <SectionCard
        :title="t('Cheapest near you')"
        icon="ic:round-local-gas-station"
        collapsible
        persist-key="nearby-stations"
        flush
    >
        <template v-if="current.fuelOptions?.length" #actions>
            <select
                :value="current.selected"
                :aria-label="t('Fuel type')"
                :disabled="loading"
                class="h-8 rounded-md border bg-background px-2 text-sm"
                @change="choose(($event.target as HTMLSelectElement).value)"
            >
                <option
                    v-for="option in current.fuelOptions"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
        </template>
        <p v-if="current.missingHome" class="p-4 text-sm text-muted-foreground">
            {{
                t(
                    'Fill in your address in your profile to see the cheapest gas stations near you.',
                )
            }}
            <Link
                v-if="current.profileUrl"
                :href="current.profileUrl"
                class="font-semibold underline"
            >
                {{ t('Profile') }}
            </Link>
        </p>
        <p
            v-else-if="!current.stations.length"
            class="p-4 text-sm text-muted-foreground"
        >
            {{
                t('No prices of gas stations within :km km are known.', {
                    km: current.radiusKm ?? 10,
                })
            }}
        </p>
        <template v-else>
            <ul :class="['divide-y', loading ? 'opacity-60' : '']">
                <li
                    v-for="station in current.stations"
                    :key="station.id"
                    class="flex items-center gap-3 px-4 py-3"
                >
                    <img
                        v-if="station.logo && !failedLogos.has(station.id)"
                        :src="station.logo"
                        alt=""
                        class="size-10 shrink-0 rounded-lg border bg-white object-contain p-1"
                        @error="failedLogos.add(station.id)"
                    />
                    <span
                        v-else
                        class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted"
                    >
                        <AppIcon
                            name="ic:round-local-gas-station"
                            class="size-5"
                        />
                    </span>
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="truncate font-semibold">{{
                            station.name
                        }}</span>
                        <span class="truncate text-xs text-muted-foreground">
                            {{
                                [station.address, `${station.distanceKm} km`]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </span>
                        <span
                            v-if="station.detourKm"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                t('A detour pays off up to :km km', {
                                    km: station.detourKm,
                                })
                            }}
                        </span>
                    </span>
                    <span class="flex flex-col items-end gap-1">
                        <span
                            class="font-semibold whitespace-nowrap tabular-nums"
                        >
                            {{ euros(station.price) }}
                        </span>
                        <ColorBadge
                            v-if="
                                station.difference !== null &&
                                station.difference < 0
                            "
                            color="success"
                            :title="
                                t('Less than at :station', {
                                    station: current.usual?.name ?? '',
                                })
                            "
                        >
                            {{
                                t(':cents ct less', {
                                    cents: Math.round(
                                        -station.difference * 100,
                                    ),
                                })
                            }}
                        </ColorBadge>
                    </span>
                </li>
            </ul>
            <div
                class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t px-4 py-2 text-sm"
            >
                <span
                    v-if="current.usual?.price"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        t('Your usual gas station :station: :price', {
                            station: current.usual.name,
                            price: euros(current.usual.price),
                        })
                    }}
                </span>
                <Link
                    v-if="current.mapUrl"
                    :href="current.mapUrl"
                    class="ml-auto inline-flex min-h-9 items-center gap-1 font-semibold"
                >
                    {{ t('Fuel stations') }}
                    <ChevronRight class="size-4" />
                </Link>
            </div>
        </template>
    </SectionCard>
</template>
