<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import NotificationItem from '@/components/NotificationItem.vue';
import type { StoredNotification } from '@/components/NotificationItem.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useT } from '@/composables/useT';

defineProps<{
    notifications: {
        // The most urgent first.
        active: StoredNotification[];
        // The most recently dismissed first.
        dismissed: StoredNotification[];
        // The ones that no longer apply, the most recent first.
        history: StoredNotification[];
    };
}>();

const { t } = useT();
</script>

<template>
    <Head :title="t('Notifications')" />

    <div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
        <h1 class="text-2xl font-bold tracking-tight">
            {{ t('Notifications') }}
        </h1>

        <SectionCard
            v-for="section in [
                {
                    key: 'active',
                    title: 'Needs attention',
                    empty: 'Nothing needs attention right now.',
                    items: notifications.active,
                },
                {
                    key: 'dismissed',
                    title: 'Dismissed',
                    empty: 'A dismissed notification is listed here, and comes back under Needs attention when it changes.',
                    items: notifications.dismissed,
                },
                {
                    key: 'history',
                    title: 'History',
                    empty: 'The notifications that no longer apply are listed here.',
                    items: notifications.history,
                },
            ]"
            :key="section.key"
            :title="t(section.title)"
            :badge="String(section.items.length)"
            flush
        >
            <ul v-if="section.items.length" class="divide-y">
                <li
                    v-for="item in section.items"
                    :key="item.id"
                    class="px-4 py-3"
                >
                    <NotificationItem :notification="item" />
                </li>
            </ul>
            <p v-else class="p-4 text-sm text-muted-foreground">
                {{ t(section.empty) }}
            </p>
        </SectionCard>
    </div>
</template>
