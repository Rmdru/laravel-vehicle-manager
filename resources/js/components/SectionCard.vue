<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';

const props = defineProps<{
    title: string;
    icon?: string;
    // Short note next to the title, e.g. the period the content covers.
    badge?: string;
    // Lets the content be folded away; off by default.
    collapsible?: boolean;
    // When set, the collapsed state is remembered under this key.
    persistKey?: string;
    collapsed?: boolean;
    // Content that brings its own padding, such as a table or a list of rows.
    flush?: boolean;
}>();

const storageKey =
    props.collapsible && props.persistKey
        ? `section:${props.persistKey}`
        : null;
const stored = storageKey ? localStorage.getItem(storageKey) : null;
const open = ref(
    !props.collapsible ||
        (stored === null ? !props.collapsed : stored === 'open'),
);

function toggle() {
    open.value = !open.value;

    if (storageKey) {
        localStorage.setItem(storageKey, open.value ? 'open' : 'closed');
    }
}
</script>

<template>
    <section class="overflow-hidden rounded-xl border bg-card">
        <header
            class="flex min-h-13 items-center gap-2 py-1 pr-2 pl-4"
            :class="{ 'border-b': open }"
        >
            <h2 class="flex flex-1">
                <component
                    :is="collapsible ? 'button' : 'div'"
                    :type="collapsible ? 'button' : undefined"
                    class="flex min-h-11 flex-1 items-center gap-2 text-left font-semibold"
                    :aria-expanded="collapsible ? open : undefined"
                    @click="collapsible && toggle()"
                >
                    <AppIcon
                        v-if="icon"
                        :name="icon"
                        class="size-5 shrink-0 text-muted-foreground"
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
                        v-if="collapsible"
                        :class="[
                            'mr-2 size-4 text-muted-foreground transition-transform',
                            open ? 'rotate-180' : '',
                        ]"
                    />
                </component>
            </h2>
            <slot name="actions" />
        </header>
        <div v-show="open" :class="flush ? '' : 'p-4'">
            <slot />
        </div>
    </section>
</template>
