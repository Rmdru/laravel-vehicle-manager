<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import { useT } from '@/composables/useT';
import {
    FEATURE_ICONS,
    FEATURE_LABELS,
    groupOf,
    KIND_LABELS,
    needIcon,
    needLabel,
    POI_GROUPS,
    SERVICES,
} from '@/lib/trips';
import type { FuelPrice, Poi, TripCandidate, TripStop } from '@/types/trips';

// What is known of a place on the trip map, in its popup.
const props = defineProps<{
    poi: Poi;
    // When a stop can be made here: what it offers, the service area and its gas station together.
    candidate?: TripCandidate;
    // The gas station of a service area.
    gasStation?: Poi;
    price?: FuelPrice;
    // When a stop is planned here.
    stop?: TripStop;
    // Where the logo of a brand is looked up.
    logoUrl: string;
    electric?: boolean;
    // Whether the driver can choose to stop here, or not to.
    canChoose?: boolean;
}>();

const emit = defineEmits<{
    choose: [action: 'pin' | 'unpin' | 'exclude', poi: number];
}>();

const { t } = useT();
const page = usePage();
const locale = computed(() => String(page.props.locale ?? 'en'));

const group = computed(() => POI_GROUPS[groupOf(props.poi.kind) ?? ''] ?? null);
// The logo of the brand of the gas station, if there is one.
const logo = computed(() => {
    const station =
        props.poi.kind === 'gas_station' ? props.poi : props.gasStation;
    const brand = station ? (station.brand ?? station.name) : null;

    if (!brand) {
        return null;
    }

    // At a service area only the logo of the brand itself: else its own icon.
    const known = props.poi.kind === 'service_area' ? '&known=1' : '';

    return `${props.logoUrl}?brand=${encodeURIComponent(brand)}${known}`;
});
const logoFailed = ref(false);
watch(logo, () => (logoFailed.value = false));
const title = computed(
    () =>
        props.poi.name ??
        props.poi.brand ??
        t(KIND_LABELS[props.poi.kind] ?? 'Stop'),
);
const brand = computed(() => {
    const brand = props.poi.brand ?? props.candidate?.brand ?? null;

    return brand && brand !== title.value ? brand : null;
});
const features = computed(
    () => props.candidate?.features ?? props.poi.features,
);
// The other facilities: of the service area with everything around it.
const services = computed(() => [
    ...new Set([
        ...(props.candidate?.services ?? props.poi.services ?? []),
        ...(props.gasStation?.services ?? []),
    ]),
]);
const details = computed(() => ({
    ...props.gasStation?.details,
    ...props.poi.details,
}));

// Prices and availability of a hotel are not free to look up: a search does.
const bookingUrl = computed(
    () =>
        `https://www.booking.com/searchresults.html?ss=${encodeURIComponent(title.value)}&latitude=${props.poi.latitude}&longitude=${props.poi.longitude}`,
);

function euros(value: number): string {
    return new Intl.NumberFormat(locale.value, {
        style: 'currency',
        currency: 'EUR',
        minimumFractionDigits: 3,
    }).format(value);
}
</script>

<template>
    <div class="flex w-72 flex-col gap-3 p-4 text-sm">
        <div class="flex items-start gap-3 pr-5">
            <img
                v-if="logo && !logoFailed"
                :src="logo"
                alt=""
                class="size-10 shrink-0 rounded-lg border bg-white object-contain p-1"
                @error="logoFailed = true"
            />
            <span
                v-else-if="group"
                class="flex size-10 shrink-0 items-center justify-center rounded-lg text-white"
                :style="{ background: group.color }"
            >
                <AppIcon :name="group.icon" class="size-5" />
            </span>
            <div class="min-w-0">
                <div class="leading-snug font-semibold">{{ title }}</div>
                <div class="text-xs text-muted-foreground">
                    {{ t(KIND_LABELS[poi.kind] ?? 'Stop') }}
                    <template v-if="brand"> · {{ brand }}</template>
                    <template v-if="details.ref"> · {{ details.ref }}</template>
                </div>
            </div>
        </div>

        <div
            v-if="stop?.needs.length"
            class="flex flex-col gap-1.5 rounded-lg bg-blue-50 p-2.5 text-blue-900 dark:bg-blue-950/40 dark:text-blue-100"
        >
            <span class="text-xs font-medium">{{ t('Planned stop') }}</span>
            <span class="flex flex-wrap gap-1.5">
                <span
                    v-for="need in stop.needs"
                    :key="need"
                    class="inline-flex items-center gap-1 rounded-md bg-white/70 px-2 py-0.5 text-xs dark:bg-white/10"
                >
                    <AppIcon
                        :name="needIcon(need, !!electric)"
                        class="size-3.5"
                    />
                    {{ t(needLabel(need, !!electric)) }}
                </span>
            </span>
        </div>

        <div v-if="price" class="flex items-center justify-between gap-2">
            <span class="flex flex-col">
                <span class="text-muted-foreground">
                    {{
                        price.source === 'station'
                            ? t('Price per liter')
                            : t('Estimated price per liter')
                    }}
                </span>
                <span v-if="price.fuel_type" class="text-xs font-medium">
                    {{ price.fuel_type }}
                </span>
            </span>
            <span class="text-base font-semibold tabular-nums">
                {{ euros(price.price) }}
            </span>
        </div>

        <div
            v-if="candidate?.exit || candidate?.detour_seconds"
            class="flex items-center gap-2 text-muted-foreground"
        >
            <AppIcon name="mdi:highway" class="size-4 shrink-0" />
            {{
                t(':minutes min detour, by exit :exit', {
                    minutes: Math.round(candidate.detour_seconds / 60),
                    exit: candidate.exit ?? '',
                })
            }}
        </div>

        <div v-if="features.length" class="flex flex-wrap gap-1.5">
            <span
                v-for="feature in features"
                :key="feature"
                class="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-0.5 text-xs"
            >
                <AppIcon :name="FEATURE_ICONS[feature]" class="size-3.5" />
                {{ t(FEATURE_LABELS[feature] ?? feature) }}
            </span>
        </div>

        <div v-if="services.length" class="flex flex-col gap-1.5">
            <span class="text-xs text-muted-foreground">{{
                t('Facilities')
            }}</span>
            <div class="flex flex-wrap gap-1.5">
                <span
                    v-for="service in services"
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
                <dd class="break-words">{{ details.opening_hours }}</dd>
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

        <div
            v-if="poi.kind === 'hotel'"
            class="flex items-center justify-between gap-2"
        >
            <span
                v-if="details.stars"
                class="flex items-center text-amber-500"
                :title="t(':count stars', { count: details.stars })"
            >
                <AppIcon
                    v-for="star in details.stars"
                    :key="star"
                    name="mdi:star"
                    class="size-3.5"
                />
            </span>
            <a
                :href="bookingUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="ml-auto inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-medium hover:bg-accent"
            >
                <AppIcon name="mdi:bed" class="size-3.5" />
                {{ t('Search on Booking.com') }}
            </a>
        </div>

        <div
            v-if="details.website || details.phone || details.wheelchair"
            class="flex flex-wrap items-center gap-3 text-xs"
        >
            <a
                v-if="details.website"
                :href="details.website"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1 text-primary underline-offset-2 hover:underline"
            >
                <AppIcon name="mdi:web" class="size-3.5" />
                {{ t('Website') }}
            </a>
            <a
                v-if="details.phone"
                :href="`tel:${details.phone}`"
                class="inline-flex items-center gap-1 text-primary underline-offset-2 hover:underline"
            >
                <AppIcon name="mdi:phone" class="size-3.5" />
                {{ details.phone }}
            </a>
            <span
                v-if="details.wheelchair"
                class="inline-flex items-center gap-1 text-muted-foreground"
            >
                <AppIcon name="mdi:wheelchair" class="size-3.5" />
                {{ t('Wheelchair accessible') }}
            </span>
        </div>

        <div
            v-if="canChoose && (candidate || stop)"
            class="flex flex-wrap gap-2 border-t pt-3"
        >
            <button
                v-if="!stop"
                type="button"
                class="inline-flex items-center gap-1 rounded-md bg-primary px-2.5 py-1 text-xs font-medium text-primary-foreground hover:bg-primary/90"
                @click="emit('choose', 'pin', poi.id)"
            >
                <AppIcon name="mdi:map-marker-plus" class="size-3.5" />
                {{ t('Stop here') }}
            </button>
            <button
                v-if="stop?.pinned"
                type="button"
                class="inline-flex items-center gap-1 rounded-md border px-2.5 py-1 text-xs font-medium hover:bg-accent"
                @click="emit('choose', 'unpin', poi.id)"
            >
                <AppIcon name="mdi:pin-off" class="size-3.5" />
                {{ t('Let the planner choose') }}
            </button>
            <button
                v-if="stop"
                type="button"
                class="inline-flex items-center gap-1 rounded-md border px-2.5 py-1 text-xs font-medium hover:bg-accent"
                @click="emit('choose', 'exclude', poi.id)"
            >
                <AppIcon name="mdi:close-circle-outline" class="size-3.5" />
                {{ t('Do not stop here') }}
            </button>
        </div>
    </div>
</template>
