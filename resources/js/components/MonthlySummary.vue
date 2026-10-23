<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { onBeforeUnmount, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import SectionCard from '@/components/SectionCard.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useT } from '@/composables/useT';

export type MonthlySummaryData = {
    // "This month", or "Last month" early in a month.
    title: string;
    period: string;
    // Whether anything was recorded in the month.
    hasRecords: boolean;
    figures: {
        key: string;
        label: string;
        icon: string;
        value: string;
        // Against what is usual; null without an average to compare with.
        comparison: string | null;
        tone: 'warning' | 'success' | 'neutral';
        details: string[];
    }[];
    upcoming: {
        key: string;
        icon: string;
        label: string;
        date: string;
        relative: string;
        url: string;
    }[];
    upcomingWithin: number;
    // Written by an AI model; null until it is.
    summary: {
        headline: string;
        text: string;
        model: string;
        generatedAt: string;
    } | null;
    // The summary is being written (again), after the facts changed.
    pending: boolean;
};

const props = defineProps<{ data: MonthlySummaryData }>();

const { t } = useT();

const tones = {
    warning: 'text-warning',
    success: 'text-success',
    neutral: 'text-muted-foreground',
};

// While the summary is written, it is asked for again now and then: it takes
// a few seconds, and the page should not have to be reloaded for it.
const POLL_EVERY = 4000;
const POLL_TIMES = 10;
let polls = 0;
let timer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => props.data.pending,
    (pending) => {
        clearTimeout(timer);

        if (!pending || polls >= POLL_TIMES) {
            return;
        }

        timer = setTimeout(() => {
            polls++;
            router.reload({ only: ['monthlySummary'] });
        }, POLL_EVERY);
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <SectionCard
        :title="data.title"
        :badge="data.period"
        icon="ic:round-auto-awesome"
        collapsible
        persist-key="monthly-summary"
    >
        <div class="flex flex-col gap-4">
            <div v-if="data.summary" class="flex flex-col gap-1">
                <p class="leading-snug font-semibold">
                    {{ data.summary.headline }}
                </p>
                <p
                    class="text-sm text-muted-foreground"
                    :title="
                        t('Generated on :date by :model.', {
                            date: data.summary.generatedAt,
                            model: data.summary.model,
                        })
                    "
                >
                    {{ data.summary.text }}
                </p>
            </div>
            <div
                v-else-if="data.pending"
                class="flex flex-col gap-2"
                :aria-label="t('The summary is being written…')"
            >
                <Skeleton class="h-5 w-1/2" />
                <Skeleton class="h-4 w-full" />
            </div>
            <p
                v-else-if="!data.hasRecords"
                class="text-sm text-muted-foreground"
            >
                {{ t('Nothing has been recorded this month yet.') }}
            </p>

            <div v-if="data.figures.length" class="grid gap-3 sm:grid-cols-3">
                <div
                    v-for="figure in data.figures"
                    :key="figure.key"
                    class="rounded-lg border p-3.5"
                >
                    <div
                        class="flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        <AppIcon :name="figure.icon" class="size-4" />
                        {{ figure.label }}
                    </div>
                    <div
                        class="mt-1 text-lg leading-snug font-semibold tabular-nums"
                    >
                        {{ figure.value }}
                    </div>
                    <div
                        v-if="figure.comparison"
                        :class="['text-sm', tones[figure.tone]]"
                    >
                        {{ figure.comparison }}
                    </div>
                    <div
                        v-for="detail in figure.details"
                        :key="detail"
                        class="text-xs text-muted-foreground"
                    >
                        {{ detail }}
                    </div>
                </div>
            </div>

            <div>
                <h3
                    class="mb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ t('Coming up') }}
                </h3>
                <ul v-if="data.upcoming.length" class="divide-y">
                    <li v-for="item in data.upcoming" :key="item.key">
                        <Link
                            :href="item.url"
                            class="flex min-h-11 items-center gap-3 py-1.5"
                        >
                            <AppIcon
                                :name="item.icon"
                                class="size-5 shrink-0 text-muted-foreground"
                            />
                            <span class="min-w-0 flex-1 font-medium">
                                {{ item.label }}
                            </span>
                            <span
                                class="text-right text-sm text-muted-foreground"
                            >
                                {{ item.date }}
                                <span class="hidden sm:inline">
                                    · {{ item.relative }}
                                </span>
                            </span>
                            <ChevronRight
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                        </Link>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    {{
                        t('Nothing is due in the coming :days days.', {
                            days: data.upcomingWithin,
                        })
                    }}
                </p>
            </div>
        </div>
    </SectionCard>
</template>
