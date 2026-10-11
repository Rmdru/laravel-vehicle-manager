<script setup lang="ts">
import { computed } from 'vue';
import { parseSvg } from '@/lib/svg';
import type { SvgIcon } from '@/lib/svg';

const props = defineProps<{ name: string }>();

// The icons in resources/icons, keyed by their Iconify name: the folder is
// the icon set, the file the icon (e.g. "mdi:car-wrench"). Add one with
// `php artisan icons:add`. The server refers to them by the same names, see
// App\Support\Html::icon(). The brand logos are left to BrandLogo, which
// loads them on demand.
const files = import.meta.glob<string>(
    [
        '../../icons/*/*.svg',
        '!../../icons/thesvg-color/*.svg',
        '!../../icons/simple-icons/*.svg',
    ],
    {
        query: '?raw',
        import: 'default',
        eager: true,
    },
);

const icons: Record<string, SvgIcon> = {};

for (const [path, svg] of Object.entries(files)) {
    const icon = parseSvg(svg);

    if (icon) {
        icons[path.split('/').slice(-2).join(':').replace('.svg', '')] = icon;
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
