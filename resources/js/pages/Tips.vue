<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import SectionCard from '@/components/SectionCard.vue';
import VehicleAdvice from '@/components/VehicleAdvice.vue';
import type { VehicleAdviceData } from '@/components/VehicleAdvice.vue';
import VehicleTip from '@/components/VehicleTip.vue';
import type {
    VehicleTipData,
    VehicleTipStatus,
} from '@/components/VehicleTip.vue';
import { useT } from '@/composables/useT';

const props = defineProps<{
    tips: VehicleAdviceData & {
        // The most recently handled first.
        kept: VehicleTipData[];
        done: VehicleTipData[];
    };
}>();

const { t } = useT();

const handling = ref<string | null>(null);

function setStatus(key: string, status: VehicleTipStatus) {
    router.post(
        props.tips.statusUrl,
        { key, status },
        {
            preserveScroll: true,
            only: ['tips'],
            onStart: () => (handling.value = key),
            onFinish: () => (handling.value = null),
        },
    );
}
</script>

<template>
    <Head :title="t('Tips')" />

    <div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
        <h1 class="text-2xl font-bold tracking-tight">{{ t('Tips') }}</h1>

        <VehicleAdvice
            :data="tips"
            :reload="['tips']"
            :on-dashboard="false"
            title="Current tips"
        />

        <SectionCard
            v-for="section in [
                { state: 'kept', title: 'Kept', tips: tips.kept },
                { state: 'done', title: 'Done', tips: tips.done },
            ] as const"
            :key="section.state"
            :title="t(section.title)"
            :badge="String(section.tips.length)"
            flush
        >
            <ul v-if="section.tips.length" class="divide-y">
                <VehicleTip
                    v-for="tip in section.tips"
                    :key="tip.key"
                    :tip="tip"
                    :state="section.state"
                    :busy="handling === tip.key"
                    @status="(status) => setStatus(tip.key, status)"
                />
            </ul>
            <p v-else class="p-4 text-sm text-muted-foreground">
                {{
                    section.state === 'kept'
                        ? t(
                              'Keep a tip to look it up here later, without it showing on the dashboard.',
                          )
                        : t('The tips you marked as done are listed here.')
                }}
            </p>
        </SectionCard>
    </div>
</template>
