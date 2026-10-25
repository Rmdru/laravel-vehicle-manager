<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import { Input } from '@/components/ui/input';
import { useT } from '@/composables/useT';
import type { Place } from '@/types/trips';

// A start or a destination: typed, and then picked from the matching places.
const props = defineProps<{
    modelValue: Place | null;
    geocodeUrl: string;
    // Where the address of the current location is looked up.
    reverseGeocodeUrl: string;
    placeholder: string;
    icon: string;
    invalid?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [Place | null] }>();

const { t } = useT();

const query = ref(props.modelValue?.label ?? '');
const results = ref<Place[]>([]);
const open = ref(false);
const searching = ref(false);
const locating = ref(false);
const locationError = ref(false);

watch(
    () => props.modelValue?.label,
    (label) => {
        if (label !== undefined && label !== query.value) {
            query.value = label;
        }
    },
);

let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

function onInput(value: string | number) {
    query.value = String(value);
    // Typed text is not a place until one is picked.
    emit('update:modelValue', {
        label: query.value,
        latitude: null,
        longitude: null,
        stayMinutes: props.modelValue?.stayMinutes ?? 0,
    });

    clearTimeout(timer);

    if (query.value.trim().length < 3) {
        results.value = [];
        open.value = false;

        return;
    }

    timer = setTimeout(search, 300);
}

async function search() {
    controller?.abort();
    controller = new AbortController();
    searching.value = true;

    try {
        const response = await fetch(
            `${props.geocodeUrl}?query=${encodeURIComponent(query.value.trim())}`,
            {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        );

        results.value = response.ok ? await response.json() : [];
        open.value = true;
    } catch {
        // Aborted by a newer search.
    } finally {
        searching.value = false;
    }
}

function pick(place: Place) {
    query.value = place.label;
    open.value = false;
    emit('update:modelValue', {
        ...place,
        stayMinutes: props.modelValue?.stayMinutes ?? 0,
    });
}

// The current location of the device, with its address.
function locate() {
    locationError.value = false;

    if (!navigator.geolocation) {
        locationError.value = true;

        return;
    }

    locating.value = true;

    navigator.geolocation.getCurrentPosition(
        async ({ coords }) => {
            try {
                const response = await fetch(
                    `${props.reverseGeocodeUrl}?latitude=${coords.latitude}&longitude=${coords.longitude}`,
                    { headers: { Accept: 'application/json' } },
                );

                if (response.ok) {
                    pick(await response.json());
                } else {
                    locationError.value = true;
                }
            } catch {
                locationError.value = true;
            } finally {
                locating.value = false;
            }
        },
        () => {
            locating.value = false;
            locationError.value = true;
        },
        { enableHighAccuracy: true, timeout: 10000 },
    );
}

function close() {
    // After a click on a result has been handled.
    setTimeout(() => (open.value = false), 150);
}

onBeforeUnmount(() => {
    clearTimeout(timer);
    controller?.abort();
});
</script>

<template>
    <div class="flex-1">
        <div class="relative">
            <AppIcon
                :name="icon"
                class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                :model-value="query"
                :placeholder="placeholder"
                :aria-invalid="invalid || undefined"
                class="pr-9 pl-8"
                autocomplete="off"
                @update:model-value="onInput"
                @focus="results.length && (open = true)"
                @blur="close"
                @keydown.enter.prevent="results[0] && pick(results[0])"
            />
            <button
                type="button"
                class="absolute top-1/2 right-1 flex size-7 -translate-y-1/2 items-center justify-center rounded text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-50 pointer-coarse:size-9"
                :title="t('Use my current location')"
                :aria-label="t('Use my current location')"
                :disabled="locating"
                @click="locate"
            >
                <AppIcon
                    name="mdi:crosshairs-gps"
                    class="size-4"
                    :class="{ 'animate-pulse': locating }"
                />
            </button>
            <ul
                v-if="open"
                class="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-md border bg-popover py-1 text-sm shadow-md"
            >
                <li
                    v-if="!results.length"
                    class="px-3 py-2 text-muted-foreground"
                >
                    {{ searching ? t('Searching…') : t('No places found') }}
                </li>
                <li v-for="result in results" :key="result.label">
                    <button
                        type="button"
                        class="w-full px-3 py-2 text-left hover:bg-accent"
                        @mousedown.prevent="pick(result)"
                    >
                        {{ result.label }}
                    </button>
                </li>
            </ul>
        </div>
        <p v-if="locationError" class="mt-1 text-xs text-destructive">
            {{ t('Your location could not be determined.') }}
        </p>
    </div>
</template>
