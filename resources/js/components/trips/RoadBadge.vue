<script setup lang="ts">
// A road number as on the road signs of its country, e.g. a red A2 in the Netherlands.
defineProps<{ road: { label: string; classes: string } }>();
</script>

<template>
    <span
        class="inline-flex h-5 items-center rounded px-1.5 text-xs leading-none font-bold whitespace-nowrap"
        :class="road.classes"
    >
        {{ road.label }}
    </span>
</template>
