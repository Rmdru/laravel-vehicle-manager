<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/composables/useT';
import { POI_GROUPS } from '@/lib/trips';

// What is shown on the map: the kinds of places, the kinds of traffic on the
// route and the surroundings, each to be switched on or off.
defineProps<{
    trafficGroups: {
        key: string;
        icon: string;
        color: string;
        label: string;
        count: number;
    }[];
    loadingStops: boolean;
}>();

const layers = defineModel<Record<string, boolean>>('layers', {
    required: true,
});
const surroundings = defineModel<boolean>('surroundings', { required: true });

const { t } = useT();
</script>

<template>
    <div
        class="absolute top-2 left-2 flex max-w-[calc(100%-4rem)] flex-wrap gap-1.5"
    >
        <label
            v-for="(group, key) in POI_GROUPS"
            :key="key"
            class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border bg-background/90 py-1 pr-2.5 pl-1 text-xs shadow-sm backdrop-blur"
            :class="{ 'opacity-60': !layers[key] }"
        >
            <input v-model="layers[key]" type="checkbox" class="sr-only" />
            <span
                class="flex size-5 items-center justify-center rounded-full text-white"
                :style="{ background: layers[key] ? group.color : '#94a3b8' }"
            >
                <AppIcon :name="group.icon" class="size-3" />
            </span>
            {{ t(group.label) }}
        </label>
        <label
            v-for="group in trafficGroups"
            :key="group.key"
            class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border bg-background/90 py-1 pr-2.5 pl-1 text-xs shadow-sm backdrop-blur"
            :class="{ 'opacity-60': !layers[group.key] }"
        >
            <input
                v-model="layers[group.key]"
                type="checkbox"
                class="sr-only"
            />
            <span
                class="flex size-5 items-center justify-center rounded-full text-white"
                :style="{
                    background: layers[group.key] ? group.color : '#94a3b8',
                }"
            >
                <AppIcon :name="group.icon" class="size-3" />
            </span>
            {{ t(group.label) }}
            <span class="text-muted-foreground">{{ group.count }}</span>
        </label>
        <label
            class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border bg-background/90 px-2.5 py-1 text-xs shadow-sm backdrop-blur"
        >
            <input
                v-model="surroundings"
                type="checkbox"
                class="size-3.5 accent-primary"
            />
            {{ t('Show surroundings') }}
        </label>
        <span
            v-if="loadingStops"
            class="inline-flex items-center gap-1.5 rounded-full border bg-background/90 px-2.5 py-1 text-xs shadow-sm backdrop-blur"
        >
            <Spinner class="size-3" />
            {{ t('Loading places…') }}
        </span>
    </div>
</template>
