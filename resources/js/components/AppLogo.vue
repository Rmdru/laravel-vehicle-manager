<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandLogo from '@/components/BrandLogo.vue';

const page = usePage();
const name = page.props.name;
const brandLogo = computed(() => page.props.currentVehicle?.brandLogo);
</script>

<template>
    <BrandLogo :name="brandLogo" class="size-8 shrink-0" />
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">{{
            name
        }}</span>
    </div>
</template>
