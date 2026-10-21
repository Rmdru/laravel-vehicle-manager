<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useT } from '@/composables/useT';
import type { TripRoute } from '@/types/trips';

// What the driver should know about the vehicle for this trip.
defineProps<{
    notices: TripRoute['notices'];
}>();

const { t } = useT();
</script>

<template>
    <SectionCard
        :title="t('Mind')"
        icon="mdi:alert-outline"
        :badge="String(notices.length)"
        flush
    >
        <ul class="divide-y">
            <li
                v-for="(notice, index) in notices"
                :key="index"
                class="flex gap-2 px-4 py-3 text-sm"
                :class="
                    notice.level === 'warning'
                        ? 'bg-amber-50 text-amber-900 dark:bg-amber-900/20 dark:text-amber-100'
                        : ''
                "
            >
                <AppIcon
                    :name="
                        notice.level === 'warning'
                            ? 'mdi:alert-outline'
                            : 'mdi:lightbulb-on-outline'
                    "
                    class="mt-0.5 size-4 shrink-0"
                />
                {{ notice.message }}
            </li>
        </ul>
    </SectionCard>
</template>
