<script setup lang="ts">
defineProps<{ color?: string }>();

const colors: Record<string, string> = {
    gray: 'bg-muted text-muted-foreground',
    success:
        'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
    danger: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
    warning:
        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    info: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
};
</script>

<template>
    <span
        :class="[
            'inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap',
            colors[color ?? 'gray'] ?? colors.gray,
        ]"
    >
        <slot />
    </span>
</template>