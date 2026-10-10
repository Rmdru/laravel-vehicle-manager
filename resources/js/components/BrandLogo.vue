<script setup lang="ts">
import { Car } from '@lucide/vue';
import { shallowRef, watch } from 'vue';
import { iconName, parseSvg } from '@/lib/svg';
import type { SvgIcon } from '@/lib/svg';

// Name of a logo in resources/icons/brands (Simple Icons), e.g. "bmw".
const props = defineProps<{ name?: string | null }>();

// Loaded on demand: only the logos of the user's own vehicles are needed.
const files = import.meta.glob<string>('../../icons/brands/*.svg', {
    query: '?raw',
    import: 'default',
});

const loaders = Object.fromEntries(
    Object.entries(files).map(([path, load]) => [iconName(path), load]),
);

const cache: Record<string, SvgIcon | null> = {};
const logo = shallowRef<SvgIcon | null>(null);

watch(
    () => props.name,
    async (name) => {
        if (!name || !loaders[name]) {
            logo.value = null;

            return;
        }

        if (!(name in cache)) {
            // Keep showing what is there while the logo loads.
            cache[name] = parseSvg(await loaders[name]());
        }

        if (props.name === name) {
            logo.value = cache[name];
        }
    },
    { immediate: true },
);
</script>

<template>
    <!-- eslint-disable vue/no-v-html -->
    <svg
        v-if="logo"
        v-bind="logo.attributes"
        width="1em"
        height="1em"
        aria-hidden="true"
        v-html="logo.content"
    />
    <!-- No logo for this brand: a generic car. -->
    <Car v-else-if="!name || !loaders[name]" aria-hidden="true" />
    <svg v-else viewBox="0 0 24 24" width="1em" height="1em" aria-hidden="true" />
</template>
