<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/components/AppIcon.vue';
import ColorBadge from '@/components/ColorBadge.vue';
import { useT } from '@/composables/useT';

export type VehicleTipData = {
    key: string;
    category:
        | 'maintenance'
        | 'season'
        | 'known_issue'
        | 'costs'
        | 'value'
        | 'legal'
        | 'usage';
    icon: string;
    priority: 'high' | 'medium' | 'low';
    // From the records in the app, or from what the model knows in general.
    basis: 'app_data' | 'general_knowledge';
    title: string;
    body: string;
    due: string | null;
    // Where to do what the tip asks for.
    url: string | null;
    urlLabel: string | null;
    // When it was kept or done; only for those.
    handledAt?: string;
};

export type VehicleTipStatus = 'done' | 'kept' | 'not_relevant';

const props = defineProps<{
    tip: VehicleTipData;
    // Current: not handled yet. Kept and done: in the overview only.
    state: 'current' | VehicleTipStatus;
    busy?: boolean;
    highlighted?: boolean;
}>();

const emit = defineEmits<{ status: [status: VehicleTipStatus] }>();

const { t } = useT();

const categories: Record<VehicleTipData['category'], string> = {
    maintenance: 'Maintenance',
    season: 'Season',
    known_issue: 'Known issue',
    costs: 'Costs and savings',
    value: 'Resale value',
    legal: 'Rules and obligations',
    usage: 'Use',
};

const tones: Record<VehicleTipData['priority'], string> = {
    high: 'bg-warning-muted text-warning',
    medium: 'bg-info-muted text-info',
    low: 'bg-muted text-muted-foreground',
};

// What can be done with the tip, from where it is: a tip that is done can
// still be kept, and the other way around. Not relevant takes it away.
const actions = (
    [
        { status: 'done', icon: 'ic:round-check', label: 'Done' },
        { status: 'kept', icon: 'mdi:bookmark-outline', label: 'Keep' },
        {
            status: 'not_relevant',
            icon: 'ic:round-close',
            label: props.state === 'current' ? 'Not relevant' : 'Remove',
        },
    ] as const
).filter((action) => action.status !== props.state);
</script>

<template>
    <li
        :class="[
            'flex gap-3 px-4 py-3 transition-colors',
            highlighted ? 'bg-muted/60' : '',
            busy ? 'opacity-50' : '',
        ]"
    >
        <span
            :class="[
                'flex size-9 shrink-0 items-center justify-center rounded-lg',
                state === 'current'
                    ? tones[tip.priority]
                    : 'bg-muted text-muted-foreground',
            ]"
        >
            <AppIcon :name="tip.icon" class="size-5" />
        </span>
        <div class="flex min-w-0 flex-1 flex-col gap-1.5">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span class="font-semibold">{{ tip.title }}</span>
                <ColorBadge
                    v-if="state === 'current' && tip.priority === 'high'"
                    color="warning"
                >
                    {{ t('Important') }}
                </ColorBadge>
            </div>
            <p class="text-sm leading-relaxed text-muted-foreground">
                {{ tip.body }}
            </p>
            <div
                class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
            >
                <span>{{ t(categories[tip.category]) }}</span>
                <span v-if="tip.due" class="flex items-center gap-1">
                    <AppIcon name="ic:round-calendar-month" class="size-3.5" />
                    {{ t('Before :date', { date: tip.due }) }}
                </span>
                <span>
                    {{
                        tip.basis === 'app_data'
                            ? t('Based on your data')
                            : t('General knowledge')
                    }}
                </span>
                <span v-if="state === 'kept' && tip.handledAt">
                    {{ t('Kept on :date', { date: tip.handledAt }) }}
                </span>
                <span v-if="state === 'done' && tip.handledAt">
                    {{ t('Done on :date', { date: tip.handledAt }) }}
                </span>
            </div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                <Link
                    v-if="tip.url && state !== 'done'"
                    :href="tip.url"
                    class="min-h-9 content-center font-semibold underline"
                >
                    {{ tip.urlLabel }}
                </Link>
                <button
                    v-for="action in actions"
                    :key="action.status"
                    type="button"
                    class="inline-flex min-h-9 cursor-pointer items-center gap-1 text-muted-foreground hover:text-foreground disabled:cursor-default"
                    :disabled="busy"
                    @click="emit('status', action.status)"
                >
                    <AppIcon :name="action.icon" class="size-4" />
                    {{ t(action.label) }}
                </button>
            </div>
        </div>
    </li>
</template>
