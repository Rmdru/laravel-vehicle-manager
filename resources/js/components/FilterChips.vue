<script setup lang="ts">
import { X } from '@lucide/vue';
import { useT } from '@/composables/useT';

export type FilterChip = { key: string; label: string };

defineProps<{ chips: FilterChip[] }>();

defineEmits<{ remove: [key: string]; reset: [] }>();

const { t } = useT();
</script>

<template>
    <div v-if="chips.length" class="flex flex-wrap items-center gap-2">
        <button
            v-for="chip in chips"
            :key="chip.key"
            type="button"
            class="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-muted/70 hover:text-foreground"
            :title="t('Delete')"
            @click="$emit('remove', chip.key)"
        >
            {{ chip.label }}
            <X class="size-3.5" />
        </button>
        <button
            v-if="chips.length > 1"
            type="button"
            class="text-xs text-muted-foreground hover:underline"
            @click="$emit('reset')"
        >
            {{ t('Reset') }}
        </button>
    </div>
</template>
