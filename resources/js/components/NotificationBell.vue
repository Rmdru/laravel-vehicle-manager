<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed } from 'vue';
import NotificationItem from '@/components/NotificationItem.vue';
import type { StoredNotification } from '@/components/NotificationItem.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useT } from '@/composables/useT';

type Bell = {
    // The notifications that are new since the bell was last opened.
    count: number;
    // The latest: those that apply first, then dismissed, then no longer applying.
    items: StoredNotification[];
    url: string;
    // Marks the notifications as seen.
    seenUrl: string;
};

const { t } = useT();

const bell = computed(() => usePage().props.notificationBell as Bell | null);

// On a phone the bell goes straight to the page: a popup has little room there.
const isMobile = useMediaQuery('(max-width: 768px)');

const label = computed(
    () =>
        t('Notifications') +
        (bell.value?.count ? ` (${bell.value.count})` : ''),
);

// Opened: the notifications are seen, and the count goes away until a new one comes.
function opened(open: boolean) {
    if (open && bell.value?.count) {
        router.post(
            bell.value.seenUrl,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['notificationBell'],
            },
        );
    }
}
</script>

<template>
    <Button
        v-if="bell && isMobile"
        variant="ghost"
        size="icon"
        class="relative"
        :aria-label="label"
        as-child
    >
        <Link :href="bell.url">
            <Bell class="size-5" />
            <span
                v-if="bell.count"
                class="absolute -top-0.5 -right-0.5 flex min-w-4.5 items-center justify-center rounded-full bg-danger px-1 text-[0.65rem] leading-4.5 font-bold text-white tabular-nums"
            >
                {{ bell.count > 9 ? '9+' : bell.count }}
            </span>
        </Link>
    </Button>
    <DropdownMenu v-else-if="bell" @update:open="opened">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative"
                :aria-label="label"
            >
                <Bell class="size-5" />
                <span
                    v-if="bell.count"
                    class="absolute -top-0.5 -right-0.5 flex min-w-4.5 items-center justify-center rounded-full bg-danger px-1 text-[0.65rem] leading-4.5 font-bold text-white tabular-nums"
                >
                    {{ bell.count > 9 ? '9+' : bell.count }}
                </span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            align="end"
            class="w-[min(24rem,calc(100vw-2rem))] p-0"
        >
            <DropdownMenuLabel class="px-4 py-3">
                {{ t('Notifications') }}
            </DropdownMenuLabel>
            <DropdownMenuSeparator class="m-0" />
            <ul
                v-if="bell.items.length"
                class="max-h-[60vh] divide-y overflow-y-auto"
            >
                <li
                    v-for="item in bell.items"
                    :key="item.id"
                    class="px-4 py-2.5"
                >
                    <NotificationItem :notification="item" compact />
                </li>
            </ul>
            <p v-else class="px-4 py-3 text-sm text-muted-foreground">
                {{ t('No notifications') }}
            </p>
            <DropdownMenuSeparator class="m-0" />
            <Link
                :href="bell.url"
                class="block px-4 py-3 text-center text-sm font-semibold hover:bg-muted/60"
            >
                {{ t('All notifications') }}
            </Link>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
