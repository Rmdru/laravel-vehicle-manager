<script setup lang="ts">
defineProps<{ isoCode: string; name?: string; showName?: boolean }>();
</script>

<template>
    <span class="inline-flex items-center gap-2">
        <img
            :src="`https://flagsapi.com/${isoCode}/flat/64.png`"
            :alt="name ?? isoCode"
            :title="name"
            class="h-8"
            loading="lazy"
        />
        <template v-if="showName">{{ name }}</template>
    </span>
</template>