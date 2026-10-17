<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { RefreshCw } from '@lucide/vue';
import { nextTick, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import ColorBadge from '@/components/ColorBadge.vue';
import SectionCard from '@/components/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { useT } from '@/composables/useT';

export type VehicleAdviceSuggestion = {
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
    // Where to do what the suggestion asks for.
    url: string | null;
    urlLabel: string | null;
};

export type VehicleAdviceData = {
    advice: {
        id: string;
        // The most important first; without the ones dismissed since.
        suggestions: VehicleAdviceSuggestion[];
        model: string;
        generatedAt: string;
    } | null;
    canGenerate: boolean;
    generateUrl: string;
    dismissUrl: string;
};

const props = defineProps<{ data: VehicleAdviceData }>();

const { t } = useT();

const generating = ref(false);
const dismissing = ref<string | null>(null);
// The suggestion a notification under "Needs attention" pointed to.
const highlighted = ref<string | null>(null);
const card = ref<HTMLElement | null>(null);

const categories: Record<VehicleAdviceSuggestion['category'], string> = {
    maintenance: 'Maintenance',
    season: 'Season',
    known_issue: 'Known issue',
    costs: 'Costs and savings',
    value: 'Resale value',
    legal: 'Rules and obligations',
    usage: 'Use',
};

const tones: Record<VehicleAdviceSuggestion['priority'], string> = {
    high: 'bg-warning-muted text-warning',
    medium: 'bg-info-muted text-info',
    low: 'bg-muted text-muted-foreground',
};

// Reloaded with the notifications: urgent tips are shown there as well.
const reload = ['vehicleAdvice', 'notifications'];

function generate() {
    router.post(
        props.data.generateUrl,
        {},
        {
            preserveScroll: true,
            only: reload,
            onStart: () => (generating.value = true),
            onFinish: () => (generating.value = false),
        },
    );
}

function dismiss(key: string, reason: 'done' | 'not_relevant') {
    router.post(
        props.data.dismissUrl,
        { key, reason },
        {
            preserveScroll: true,
            only: reload,
            onStart: () => (dismissing.value = key),
            onFinish: () => (dismissing.value = null),
        },
    );
}

// The dashboard points to a tip from its notification under "Needs attention".
defineExpose({
    show: async (key?: string) => {
        highlighted.value = key ?? null;
        await nextTick();
        card.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    },
});
</script>

<template>
    <div ref="card" class="scroll-mt-4">
        <SectionCard
            :title="t('Tips')"
            icon="ic:round-auto-awesome"
            collapsible
            persist-key="vehicle-advice"
            flush
        >
            <template v-if="data.advice" #actions>
                <Button
                    variant="ghost"
                    size="icon"
                    :disabled="generating || !data.canGenerate"
                    :aria-label="t('Generate again')"
                    :title="
                        data.canGenerate
                            ? t('Generate again')
                            : t('The tips have just been generated.')
                    "
                    @click="generate"
                >
                    <RefreshCw
                        :class="['size-4', generating ? 'animate-spin' : '']"
                    />
                </Button>
            </template>

            <div
                v-if="!data.advice"
                class="flex flex-wrap items-center gap-3 p-4"
            >
                <span
                    class="min-w-0 flex-1 basis-56 text-sm text-muted-foreground"
                >
                    {{
                        t(
                            'Get tips on maintenance, costs and the season, based on everything that is known about this vehicle.',
                        )
                    }}
                </span>
                <Button
                    size="sm"
                    :disabled="generating || !data.canGenerate"
                    @click="generate"
                >
                    <RefreshCw
                        :class="['size-4', generating ? 'animate-spin' : '']"
                    />
                    {{ generating ? t('Generating…') : t('Generate tips') }}
                </Button>
            </div>

            <template v-else>
                <ul v-if="data.advice.suggestions.length" class="divide-y">
                    <li
                        v-for="suggestion in data.advice.suggestions"
                        :key="suggestion.key"
                        :class="[
                            'flex gap-3 px-4 py-3 transition-colors',
                            highlighted === suggestion.key ? 'bg-muted/60' : '',
                            dismissing === suggestion.key ? 'opacity-50' : '',
                        ]"
                    >
                        <span
                            :class="[
                                'flex size-9 shrink-0 items-center justify-center rounded-lg',
                                tones[suggestion.priority],
                            ]"
                        >
                            <AppIcon :name="suggestion.icon" class="size-5" />
                        </span>
                        <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                            <div
                                class="flex flex-wrap items-center gap-x-2 gap-y-1"
                            >
                                <span class="font-semibold">
                                    {{ suggestion.title }}
                                </span>
                                <ColorBadge
                                    v-if="suggestion.priority === 'high'"
                                    color="warning"
                                >
                                    {{ t('Important') }}
                                </ColorBadge>
                            </div>
                            <p
                                class="text-sm leading-relaxed text-muted-foreground"
                            >
                                {{ suggestion.body }}
                            </p>
                            <div
                                class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
                            >
                                <span>{{
                                    t(categories[suggestion.category])
                                }}</span>
                                <span
                                    v-if="suggestion.due"
                                    class="flex items-center gap-1"
                                >
                                    <AppIcon
                                        name="ic:round-calendar-month"
                                        class="size-3.5"
                                    />
                                    {{
                                        t('Before :date', {
                                            date: suggestion.due,
                                        })
                                    }}
                                </span>
                                <span>
                                    {{
                                        suggestion.basis === 'app_data'
                                            ? t('Based on your data')
                                            : t('General knowledge')
                                    }}
                                </span>
                            </div>
                            <div
                                class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm"
                            >
                                <Link
                                    v-if="suggestion.url"
                                    :href="suggestion.url"
                                    class="min-h-9 content-center font-semibold underline"
                                >
                                    {{ suggestion.urlLabel }}
                                </Link>
                                <button
                                    type="button"
                                    class="inline-flex min-h-9 cursor-pointer items-center gap-1 text-muted-foreground hover:text-foreground"
                                    :disabled="dismissing !== null"
                                    @click="dismiss(suggestion.key, 'done')"
                                >
                                    <AppIcon
                                        name="ic:round-check"
                                        class="size-4"
                                    />
                                    {{ t('Done') }}
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex min-h-9 cursor-pointer items-center gap-1 text-muted-foreground hover:text-foreground"
                                    :disabled="dismissing !== null"
                                    @click="
                                        dismiss(suggestion.key, 'not_relevant')
                                    "
                                >
                                    <AppIcon
                                        name="ic:round-close"
                                        class="size-4"
                                    />
                                    {{ t('Not relevant') }}
                                </button>
                            </div>
                        </div>
                    </li>
                </ul>
                <p v-else class="p-4 text-sm text-muted-foreground">
                    {{
                        t(
                            'No tips right now: nothing stands out for this vehicle.',
                        )
                    }}
                </p>
                <p class="border-t px-4 py-3 text-xs text-muted-foreground">
                    {{
                        t('Generated on :date by :model.', {
                            date: data.advice.generatedAt,
                            model: data.advice.model,
                        })
                    }}
                    {{ t('Indicative: check a tip before acting on it.') }}
                </p>
            </template>
        </SectionCard>
    </div>
</template>
