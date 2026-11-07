<script setup lang="ts">
import { computed, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/composables/useT';
import { usePage } from '@inertiajs/vue3';

export type NearbyStation = {
    id: number;
    // The brand first: the form takes the first word for the logo.
    label: string;
    street: string | null;
    // The key of the country, as the form has it.
    country: string | null;
    latitude: number;
    longitude: number;
    distance_km: number;
    price: number | null;
    fuel_type: string | null;
    logo: string | null;
};

const props = defineProps<{
    url: string;
    // Where to look without the location of the device.
    home: { latitude: number; longitude: number } | null;
    fuelType: string | null;
}>();

const emit = defineEmits<{ choose: [station: NearbyStation] }>();

const { t } = useT();
const page = usePage();
const locale = computed(() => String(page.props.locale ?? 'en'));

const open = ref(false);
const loading = ref(false);
const failed = ref<string | null>(null);
const stations = ref<NearbyStation[]>([]);
const fromHome = ref(false);
const failedLogos = ref(new Set<number>());

// The location of the device, if the owner allows it: at the pump, that is where it is.
function here(): Promise<{ latitude: number; longitude: number } | null> {
    return new Promise((resolve) => {
        if (!navigator.geolocation) {
            resolve(null);

            return;
        }

        navigator.geolocation.getCurrentPosition(
            (position) =>
                resolve({
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                }),
            () => resolve(null),
            { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 },
        );
    });
}

async function show() {
    open.value = true;
    loading.value = true;
    failed.value = null;
    stations.value = [];

    const location = (await here()) ?? props.home;
    fromHome.value = location === props.home;

    if (!location) {
        loading.value = false;
        failed.value = t(
            'Your location is not known: allow it in the browser, or fill in your home address in your profile.',
        );

        return;
    }

    try {
        const params = new URLSearchParams({
            latitude: String(location.latitude),
            longitude: String(location.longitude),
            ...(props.fuelType ? { fuel_type: props.fuelType } : {}),
        });
        const response = await fetch(`${props.url}?${params}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        stations.value = (await response.json()).stations;

        if (!stations.value.length) {
            failed.value = t('No gas stations are known nearby.');
        }
    } catch {
        failed.value = t('The gas stations could not be loaded.');
    } finally {
        loading.value = false;
    }
}

function choose(station: NearbyStation) {
    emit('choose', station);
    open.value = false;
}

function euros(value: number): string {
    return new Intl.NumberFormat(locale.value, {
        style: 'currency',
        currency: 'EUR',
        minimumFractionDigits: 3,
    }).format(value);
}
</script>

<template>
    <Button type="button" variant="outline" size="sm" @click="show">
        <AppIcon name="mdi:map-marker-radius" class="size-4" />
        {{ t('Choose a gas station nearby') }}
    </Button>

    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[85vh] overflow-x-hidden overflow-y-auto sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{
                    t('Choose a gas station nearby')
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        fromHome
                            ? t('Near your home address.')
                            : t('Near where you are now.')
                    }}
                </DialogDescription>
            </DialogHeader>

            <p v-if="loading" class="text-sm text-muted-foreground">
                {{ t('Looking for gas stations nearby…') }}
            </p>
            <p v-else-if="failed" class="text-sm text-muted-foreground">
                {{ failed }}
            </p>
            <!-- min-w-0: a long name is cut off, rather than the list growing wider than the dialog. -->
            <ul v-else class="flex min-w-0 flex-col divide-y">
                <li v-for="station in stations" :key="station.id">
                    <button
                        type="button"
                        class="flex w-full cursor-pointer items-center gap-3 py-2.5 text-left hover:bg-muted/60"
                        @click="choose(station)"
                    >
                        <img
                            v-if="station.logo && !failedLogos.has(station.id)"
                            :src="station.logo"
                            alt=""
                            class="size-9 shrink-0 rounded-lg border bg-white object-contain p-1"
                            @error="failedLogos.add(station.id)"
                        />
                        <span
                            v-else
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted"
                        >
                            <AppIcon
                                name="ic:round-local-gas-station"
                                class="size-5"
                            />
                        </span>
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="truncate font-semibold">{{
                                station.label
                            }}</span>
                            <span
                                class="truncate text-xs text-muted-foreground"
                            >
                                {{
                                    [
                                        station.street,
                                        `${station.distance_km} km`,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}
                            </span>
                        </span>
                        <span
                            v-if="station.price"
                            class="font-semibold whitespace-nowrap tabular-nums"
                        >
                            {{ euros(station.price) }}
                        </span>
                    </button>
                </li>
            </ul>
        </DialogContent>
    </Dialog>
</template>
