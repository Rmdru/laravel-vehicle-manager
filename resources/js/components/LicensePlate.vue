<script setup lang="ts">
import type { LicensePlate } from '@/types';

defineProps<{ plate: LicensePlate }>();
</script>

<template>
    <div
        class="flex w-fit items-center overflow-hidden rounded font-bold whitespace-nowrap"
    >
        <div v-if="plate.euBar" class="bg-blue-800 px-1 text-white">
            {{ plate.prefix }}
        </div>
        <div :class="['px-1', plate.plateClasses]">{{ plate.label }}</div>
    </div>
</template>