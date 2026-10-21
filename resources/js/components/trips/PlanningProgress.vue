<script setup lang="ts">
import { computed } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/composables/useT';
import type { TripProgress } from '@/types/trips';

const props = defineProps<{
    progress: TripProgress | null;
    // The routes are known, the stops along them not yet.
    routed: boolean;
}>();

const { t } = useT();

// The part of the planning that is done, in percent.
const percent = computed(() => Math.round((props.progress?.value ?? 0) * 100));

// What is being done, with how many of its requests are done.
const label = computed(() => {
    const progress = props.progress;
    const counted = (text: string) =>
        progress?.total
            ? t(text, { done: progress.done ?? 0, total: progress.total })
            : t(text.replace(' (:done/:total)', ''));

    switch (progress?.stage) {
        case 'traffic':
            return t('Looking up the traffic along the routes…');
        case 'places':
            return counted(
                'Fetching the places to stop along the routes (:done/:total)…',
            );
        case 'amenities':
            return counted(
                'Fetching what the service areas offer (:done/:total)…',
            );
        case 'stops':
            return t('Planning the stops…');
        default:
            return props.routed
                ? t('Looking for places to stop along the routes…')
                : t('Planning the routes…');
    }
});
</script>

<template>
    <div
        class="flex flex-col gap-2 rounded-xl border bg-card p-4 text-sm"
        role="progressbar"
        :aria-valuenow="percent"
        aria-valuemin="0"
        aria-valuemax="100"
    >
        <div class="flex items-center gap-2">
            <Spinner class="size-4 shrink-0" />
            <span class="flex-1">{{ label }}</span>
            <span class="font-medium tabular-nums">{{ percent }}%</span>
        </div>
        <div class="h-2 overflow-hidden rounded-full bg-muted">
            <div
                class="h-full rounded-full bg-primary transition-[width] duration-700"
                :style="{ width: `${Math.max(percent, 2)}%` }"
            />
        </div>
        <p v-if="routed" class="text-xs text-muted-foreground">
            {{
                t(
                    'The routes are ready. The first time in an area, finding the places to stop can take a few minutes.',
                )
            }}
        </p>
    </div>
</template>
