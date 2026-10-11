<script setup lang="ts">
import { Car } from '@lucide/vue';
import { shallowRef, watch } from 'vue';
import { parseSvg } from '@/lib/svg';
import type { SvgIcon } from '@/lib/svg';

// Iconify name of a brand logo in resources/icons: in the brand's colors
// ("thesvg-color:bmw") or monochrome ("simple-icons:tesla").
const props = defineProps<{ name?: string | null }>();

// Loaded on demand: only the logos of the user's own vehicles are needed.
const files = import.meta.glob<string>(
    ['../../icons/thesvg-color/*.svg', '../../icons/simple-icons/*.svg'],
    {
        query: '?raw',
        import: 'default',
    },
);

const loaders = Object.fromEntries(
    Object.entries(files).map(([path, load]) => [
        path.split('/').slice(-2).join(':').replace('.svg', ''),
        load,
    ]),
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
    <span
        class="inline-flex items-center justify-center rounded-md bg-white text-black ring-1 ring-black/10"
        aria-hidden="true"
    >
        <!-- Brand colors assume a white ground, so the logo sits on one in both themes. -->
        <!-- eslint-disable vue/no-v-html -->
        <svg
            v-if="logo"
            v-bind="logo.attributes"
            class="size-[72%] overflow-visible"
            v-html="logo.content"
        />
        <!-- No logo for this brand: a generic car. -->
        <Car
            v-else-if="!name || !loaders[name]"
            class="size-[72%] overflow-visible"
        />
    </span>
</template>
