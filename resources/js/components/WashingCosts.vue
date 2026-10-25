<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useT } from '@/composables/useT';

export type WashingCostsData = {
    months: number;
    kinds: {
        key: 'self' | 'carwash' | 'others';
        label: string;
        icon: string;
        washes: number;
        perMonth: string;
        // Null without a wash of this kind, such as products bought only.
        perWash: string | null;
        note: string | null;
    }[];
    combined: { washes: number; perMonth: string; perWash: string | null };
    insight: string | null;
};

defineProps<{ data: WashingCostsData; badge?: string }>();

const { t } = useT();
</script>

<template>
    <SectionCard :title="t('Washing costs')" :badge="badge" icon="mdi:car-wash">
        <div class="flex flex-col gap-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="kind in data.kinds"
                    :key="kind.key"
                    class="flex flex-col gap-1 rounded-lg border p-3"
                >
                    <div
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <AppIcon :name="kind.icon" class="size-4 shrink-0" />
                        {{ kind.label }}
                        <span class="ml-auto tabular-nums"
                            >{{ kind.washes }}×</span
                        >
                    </div>
                    <div class="text-xl font-bold tracking-tight tabular-nums">
                        {{ kind.perMonth }}
                        <span class="text-sm font-normal text-muted-foreground">
                            {{ t('per month') }}
                        </span>
                    </div>
                    <div
                        v-if="kind.perWash"
                        class="text-sm font-medium tabular-nums"
                    >
                        {{ kind.perWash }} {{ t('per wash') }}
                    </div>
                    <div v-if="kind.note" class="text-xs text-muted-foreground">
                        {{ kind.note }}
                    </div>
                </div>

                <div class="flex flex-col gap-1 rounded-lg bg-muted p-3">
                    <div
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <AppIcon name="mdi:sigma" class="size-4 shrink-0" />
                        {{ t('Combined') }}
                        <span class="ml-auto tabular-nums"
                            >{{ data.combined.washes }}×</span
                        >
                    </div>
                    <div class="text-xl font-bold tracking-tight tabular-nums">
                        {{ data.combined.perMonth }}
                        <span class="text-sm font-normal text-muted-foreground">
                            {{ t('per month') }}
                        </span>
                    </div>
                    <div
                        v-if="data.combined.perWash"
                        class="text-sm font-medium tabular-nums"
                    >
                        {{ data.combined.perWash }} {{ t('per wash') }}
                    </div>
                </div>
            </div>

            <p v-if="data.insight" class="text-sm">{{ data.insight }}</p>

            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'Washing yourself costs the detailing products bought in the period, divided over the times you washed the outside yourself.',
                    )
                }}
            </p>
        </div>
    </SectionCard>
</template>
