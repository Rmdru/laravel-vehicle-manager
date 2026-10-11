<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue';

defineProps<{ isoCode: string; name?: string; showName?: boolean }>();
</script>

<template>
    <span class="inline-flex items-center gap-2" :title="name">
        <AppIcon
            :name="`circle-flags:${isoCode.toLowerCase()}`"
            class="size-6 shrink-0 rounded-full"
        />
        <template v-if="showName">{{ name }}</template>
        <span v-else class="sr-only">{{ name ?? isoCode }}</span>
    </span>
</template>
