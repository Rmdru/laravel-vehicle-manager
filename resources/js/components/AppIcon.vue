<script setup lang="ts">
import { computed } from 'vue';
import { iconName, parseSvg } from '@/lib/svg';
import type { SvgIcon } from '@/lib/svg';

const props = defineProps<{ name: string }>();

// The icons in resources/icons, keyed by file name (e.g. "mdi-car-wrench").
// The server refers to them by the same names, see App\Support\Html::icon().
const files = import.meta.glob<string>('../../icons/*.svg', {
    query: '?raw',
    import: 'default',
    eager: true,
});

const icons: Record<string, SvgIcon> = {};

for (const [path, svg] of Object.entries(files)) {
    const icon = parseSvg(svg);

    if (icon) {
        icons[iconName(path)] = icon;
    }
}

const icon = computed(() => icons[props.name]);
</script>

<template>
    <!-- eslint-disable vue/no-v-html -->
    <svg
        v-if="icon"
        v-bind="icon.attributes"
        width="1em"
        height="1em"
        aria-hidden="true"
        v-html="icon.content"
    />
</template>
