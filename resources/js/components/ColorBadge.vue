<script setup lang="ts">
defineProps<{ color?: string }>();

const colors: Record<string, string> = {
    gray: 'bg-muted text-muted-foreground',
    success: 'bg-success-muted text-success',
    danger: 'bg-danger-muted text-danger',
    warning: 'bg-warning-muted text-warning',
    info: 'bg-info-muted text-info',
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
