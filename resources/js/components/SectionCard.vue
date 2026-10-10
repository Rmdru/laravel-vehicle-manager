<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';

const props = defineProps<{
    title: string;
    icon?: string;
    // Short note next to the title, e.g. the period the content covers.
    badge?: string;
    // When set, the collapsed state is remembered under this key.
    persistKey?: string;
    collapsed?: boolean;
}>();

const storageKey = props.persistKey ? `section:${props.persistKey}` : null;
const stored = storageKey ? localStorage.getItem(storageKey) : null;
const open = ref(stored === null ? !props.collapsed : stored === 'open');

function toggle() {
    open.value = !open.value;

    if (storageKey) {
        localStorage.setItem(storageKey, open.value ? 'open' : 'closed');
    }
}
</script>

<template>
    <section class="rounded-xl border border-sidebar-border/70 bg-card">
        <button
            type="button"
            class="flex w-full items-center gap-2 px-4 py-3 text-left font-semibold"
            :aria-expanded="open"
            @click="toggle"
        >
            <AppIcon
                v-if="icon"
                :name="icon"
                class="size-5 text-muted-foreground"
            />
            <span class="flex flex-1 flex-wrap items-center gap-2">
                {{ title }}
                <span
                    v-if="badge"
                    class="rounded-md bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
                >
                    {{ badge }}
                </span>
            </span>
            <ChevronDown
                :class="[
                    'size-4 text-muted-foreground transition-transform',
                    open ? 'rotate-180' : '',
                ]"
            />
        </button>
        <div v-show="open" class="border-t border-sidebar-border/70 p-4">
            <slot />
        </div>
    </section>
</template>