<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue';
import SectionCard from '@/components/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/composables/useT';
import { TIP_ICONS } from '@/lib/trips';
import type { TripTips } from '@/types/trips';

defineProps<{
    tips: TripTips | null;
    // Tips are only generated for a trip that is planned.
    canGenerate: boolean;
    generating: boolean;
}>();

const emit = defineEmits<{
    generate: [];
}>();

const { t } = useT();
</script>

<template>
    <SectionCard
        :title="t('Travel tips')"
        icon="mdi:lightbulb-on-outline"
        flush
    >
        <template #actions>
            <Button
                size="sm"
                variant="outline"
                :disabled="generating || !canGenerate"
                @click="emit('generate')"
            >
                <Spinner v-if="generating" class="size-3.5" />
                {{ tips ? t('Generate again') : t('Generate tips') }}
            </Button>
        </template>
        <ul v-if="tips?.items?.length" class="divide-y">
            <li
                v-for="(tip, index) in tips.items"
                :key="index"
                class="flex gap-3 px-4 py-3"
            >
                <AppIcon
                    :name="
                        TIP_ICONS[tip.category] ?? 'mdi:lightbulb-on-outline'
                    "
                    class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                />
                <div class="flex flex-col gap-0.5 text-sm">
                    <span class="font-medium">{{ tip.title }}</span>
                    <span class="text-muted-foreground">{{ tip.body }}</span>
                </div>
            </li>
        </ul>
        <p v-else class="p-4 text-sm text-muted-foreground">
            {{
                t(
                    'Get tips for this trip from AI: rules in the countries on the way, costs, busy days and what to prepare.',
                )
            }}
        </p>
    </SectionCard>
</template>
