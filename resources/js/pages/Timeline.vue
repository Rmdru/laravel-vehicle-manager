<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import SectionCard from '@/components/SectionCard.vue';
import TimelineMonths from '@/components/TimelineMonths.vue';
import type { TimelineMonth } from '@/components/TimelineMonths.vue';
import { useT } from '@/composables/useT';

defineProps<{
    history: TimelineMonth[];
    predictions: TimelineMonth[];
}>();

const { t } = useT();
</script>

<template>
    <Head :title="t('Timeline')" />

    <div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
        <h1 class="text-2xl font-bold tracking-tight">{{ t('Timeline') }}</h1>

        <SectionCard
            v-if="predictions.length"
            :title="t('Predictions')"
            icon="ic:round-auto-awesome"
            collapsible
            collapsed
            class="border-dashed"
        >
            <TimelineMonths :months="predictions" />
        </SectionCard>

        <TimelineMonths :months="history" />

        <p
            v-if="!history.length"
            class="rounded-xl border bg-card p-4 font-semibold"
        >
            {{ t('Nothing to show') }}
        </p>
    </div>
</template>
