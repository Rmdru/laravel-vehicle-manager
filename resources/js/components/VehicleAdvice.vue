<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ChevronRight, RefreshCw } from '@lucide/vue';
import { nextTick, ref } from 'vue';
import SectionCard from '@/components/SectionCard.vue';
import { Button } from '@/components/ui/button';
import VehicleTip from '@/components/VehicleTip.vue';
import type {
    VehicleTipData,
    VehicleTipStatus,
} from '@/components/VehicleTip.vue';
import { useT } from '@/composables/useT';

export type VehicleAdviceData = {
    advice: {
        id: string;
        // The most important first; without the ones handled since.
        suggestions: VehicleTipData[];
        model: string;
        generatedAt: string;
    } | null;
    canGenerate: boolean;
    generateUrl: string;
    statusUrl: string;
    overviewUrl: string;
};

const props = withDefaults(
    defineProps<{
        data: VehicleAdviceData;
        // The props to reload after a change: urgent tips are notifications as well.
        reload?: string[];
        // On the dashboard: foldable, with a link to the overview of all tips.
        onDashboard?: boolean;
        title?: string;
    }>(),
    {
        reload: () => ['vehicleAdvice', 'notifications'],
        onDashboard: true,
        title: 'Tips',
    },
);

const { t } = useT();

const generating = ref(false);
const handling = ref<string | null>(null);
// The tip a notification under "Needs attention" pointed to.
const highlighted = ref<string | null>(null);
const card = ref<HTMLElement | null>(null);

function generate() {
    router.post(
        props.data.generateUrl,
        {},
        {
            preserveScroll: true,
            only: props.reload,
            onStart: () => (generating.value = true),
            onFinish: () => (generating.value = false),
        },
    );
}

function setStatus(key: string, status: VehicleTipStatus) {
    router.post(
        props.data.statusUrl,
        { key, status },
        {
            preserveScroll: true,
            only: props.reload,
            onStart: () => (handling.value = key),
            onFinish: () => (handling.value = null),
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
            :title="t(title)"
            icon="ic:round-auto-awesome"
            :collapsible="onDashboard"
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
                    <VehicleTip
                        v-for="suggestion in data.advice.suggestions"
                        :key="suggestion.key"
                        :tip="suggestion"
                        state="current"
                        :busy="handling === suggestion.key"
                        :highlighted="highlighted === suggestion.key"
                        @status="(status) => setStatus(suggestion.key, status)"
                    />
                </ul>
                <p v-else class="p-4 text-sm text-muted-foreground">
                    {{
                        t(
                            'No tips right now: nothing stands out for this vehicle.',
                        )
                    }}
                </p>
                <div
                    v-if="onDashboard"
                    class="flex flex-wrap items-center justify-end gap-x-4 gap-y-1 border-t px-4 py-2"
                >
                    <Link
                        :href="data.overviewUrl"
                        class="inline-flex min-h-9 items-center gap-1 text-sm font-semibold"
                    >
                        {{ t('All tips') }}
                        <ChevronRight class="size-4" />
                    </Link>
                </div>
            </template>
        </SectionCard>
    </div>
</template>
