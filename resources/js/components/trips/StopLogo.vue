<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import { groupOf, POI_GROUPS } from '@/lib/trips';

// The logo of the gas station at a stop, or else the icon of its kind of place.
const props = defineProps<{
    kind?: string;
    brand?: string | null;
    logoUrl: string;
}>();

const failed = ref(false);
watch(
    () => props.brand,
    () => (failed.value = false),
);

// At a service area only the logo of the brand itself: else its own icon.
const logo = computed(() =>
    props.brand && !failed.value
        ? `${props.logoUrl}?brand=${encodeURIComponent(props.brand)}${props.kind === 'service_area' ? '&known=1' : ''}`
        : null,
);
const group = computed(
    () => POI_GROUPS[groupOf(props.kind ?? '') ?? ''] ?? null,
);
</script>

<template>
    <img
        v-if="logo"
        :src="logo"
        alt=""
        class="size-8 shrink-0 rounded-md border bg-white object-contain p-0.5"
        @error="failed = true"
    />
    <span
        v-else
        class="flex size-8 shrink-0 items-center justify-center rounded-md text-white"
        :style="{ background: group?.color ?? '#64748b' }"
    >
        <AppIcon
            :name="group?.icon ?? 'maki:highway-rest-area'"
            class="size-4"
        />
    </span>
</template>
