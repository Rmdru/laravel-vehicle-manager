<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Check, Undo2, X } from '@lucide/vue';
import { ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import { Button } from '@/components/ui/button';
import { useT } from '@/composables/useT';

export type StoredNotification = {
    id: number;
    type: 'critical' | 'warning' | 'info' | 'success';
    // Applies and shows under "Needs attention", dismissed, or no longer applies.
    state: 'active' | 'dismissed' | 'resolved';
    text: string;
    icon: string | null;
    typeIcon: string | null;
    url: string | null;
    urlLabel: string | null;
    // A check of the maintenance plan that can be ticked off at once.
    doneUrl: string | null;
    since: string;
    // When it was dismissed, or stopped applying.
    until: string | null;
    dismissUrl: string | null;
    restoreUrl: string | null;
};

defineProps<{
    notification: StoredNotification;
    // In the bell: less room, the dates left out.
    compact?: boolean;
}>();

const { t } = useT();

const tones = {
    critical: 'text-danger',
    warning: 'text-warning',
    info: 'text-info',
    success: 'text-success',
};

const busy = ref(false);

function post(url: string) {
    router.post(
        url,
        {},
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
        },
    );
}
</script>

<template>
    <div
        :class="[
            'flex items-start gap-3',
            notification.state === 'active' ? '' : 'opacity-60',
        ]"
    >
        <span
            :class="[
                'mt-0.5 flex shrink-0 items-center gap-1',
                tones[notification.type],
            ]"
        >
            <AppIcon
                v-if="notification.typeIcon"
                :name="notification.typeIcon"
                class="size-5"
            />
            <AppIcon
                v-if="notification.icon && !compact"
                :name="notification.icon"
                class="size-5"
            />
        </span>
        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span class="text-sm font-medium">{{ notification.text }}</span>
            <span class="text-xs text-muted-foreground">
                <template v-if="notification.state === 'dismissed'">
                    {{ t('Dismissed on :date', { date: notification.until! }) }}
                </template>
                <template v-else-if="notification.state === 'resolved'">
                    {{
                        t('No longer applies since :date', {
                            date: notification.until!,
                        })
                    }}
                </template>
                <template v-else-if="!compact">
                    {{ t('Since :date', { date: notification.since }) }}
                </template>
            </span>
            <div
                v-if="notification.url || notification.doneUrl"
                class="flex flex-wrap gap-x-3"
            >
                <button
                    v-if="notification.doneUrl"
                    type="button"
                    class="inline-flex min-h-9 cursor-pointer items-center gap-1 text-sm font-semibold underline"
                    :disabled="busy"
                    @click="post(notification.doneUrl)"
                >
                    <Check class="size-4" />
                    {{ t('Done') }}
                </button>
                <Link
                    v-if="notification.url"
                    :href="notification.url"
                    class="min-h-9 content-center text-sm font-semibold underline"
                >
                    {{ notification.urlLabel ?? t('Show') }}
                </Link>
            </div>
        </div>
        <Button
            v-if="notification.dismissUrl"
            variant="ghost"
            size="icon"
            :aria-label="t('Dismiss')"
            :title="t('Dismiss')"
            :disabled="busy"
            @click.stop.prevent="post(notification.dismissUrl)"
        >
            <X class="size-4" />
        </Button>
        <Button
            v-else-if="notification.restoreUrl"
            variant="ghost"
            size="icon"
            :aria-label="t('Show again')"
            :title="t('Show again')"
            :disabled="busy"
            @click.stop.prevent="post(notification.restoreUrl)"
        >
            <Undo2 class="size-4" />
        </Button>
    </div>
</template>
