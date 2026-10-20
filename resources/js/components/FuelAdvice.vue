<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronRight, RefreshCw } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import ColorBadge from '@/components/ColorBadge.vue';
import CountryFlag from '@/components/CountryFlag.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/composables/useT';

type Country = { name: string; isoCode: string | null };

export type FuelAdviceData = {
    advice: {
        id: string;
        action: 'refuel_now' | 'wait' | 'refuel_abroad' | 'anytime';
        summary: string;
        body: string;
        reasons: string[];
        confidence: 'low' | 'medium' | 'high';
        country: Country | null;
        recommendedDate: string | null;
        expectedRefuelDate: string | null;
        tankPercentage: number | null;
        fuelType: string | null;
        // The next refueling is the one in four with Super Plus.
        superPlusDue: boolean;
        homePrice: (Country & { price: string }) | null;
        // The cheapest country worth the detour from where the driver lives.
        bestPrice:
            | (Country & { price: string; distance: string; savings: string })
            | null;
        // Where the driver pays least compared to the average of the country.
        cheapestStation: { name: string; difference: string } | null;
        // How the advice before the latest refueling turned out.
        review: {
            adviceDate: string;
            summary: string;
            // Null when there was nothing to follow ("anytime").
            followed: boolean | null;
            refuelDate: string;
            country: string;
            paid: string;
            average: string;
            difference: string;
            cheaper: boolean;
            savings: string;
        } | null;
        sources: { url: string; title: string }[];
        model: string;
        grounded: boolean;
        generatedAt: string;
        // Generated today: older advice is shown with its date.
        isCurrent: boolean;
    } | null;
    canGenerate: boolean;
    generateUrl: string;
};

const props = defineProps<{ data: FuelAdviceData }>();

const { t } = useT();

const open = ref(false);
const generating = ref(false);

// The icon and the color tell what to do at a glance.
const actions = {
    refuel_now: {
        icon: 'ic:round-local-gas-station',
        color: 'warning',
        text: 'text-warning',
        classes: 'border-warning/30 bg-warning-muted text-warning',
    },
    wait: {
        icon: 'mdi:timer-sand',
        color: 'info',
        text: 'text-info',
        classes: 'border-info/30 bg-info-muted text-info',
    },
    refuel_abroad: {
        icon: 'mdi:earth-arrow-right',
        color: 'success',
        text: 'text-success',
        classes: 'border-success/30 bg-success-muted text-success',
    },
    anytime: {
        icon: 'ic:round-check',
        color: 'gray',
        text: 'text-muted-foreground',
        classes: 'border-border bg-muted text-muted-foreground',
    },
} as const;

const action = computed(() =>
    props.data.advice ? actions[props.data.advice.action] : actions.anytime,
);

const confidences = {
    low: { label: 'Low confidence', color: 'gray' },
    medium: { label: 'Medium confidence', color: 'info' },
    high: { label: 'High confidence', color: 'success' },
} as const;

type Part = { text: string; bold: boolean };
type Block = { list: false; parts: Part[] } | { list: true; items: Part[][] };

// The advice is Markdown from the AI model. Only paragraphs, bullets and bold
// are rendered, as text: whatever HTML the model writes stays text.
function inline(text: string): Part[] {
    return text
        .split(/(\*\*[^*]+\*\*)/)
        .filter(Boolean)
        .map((part) =>
            part.startsWith('**') && part.endsWith('**')
                ? { text: part.slice(2, -2), bold: true }
                : { text: part, bold: false },
        );
}

const blocks = computed<Block[]>(() =>
    (props.data.advice?.body ?? '')
        .split(/\n\s*\n/)
        .map((chunk) => chunk.trim())
        .filter(Boolean)
        .map((chunk): Block => {
            const lines = chunk.split('\n').map((line) => line.trim());

            return lines.every((line) => /^[-*•]\s+/.test(line))
                ? {
                      list: true,
                      items: lines.map((line) =>
                          inline(line.replace(/^[-*•]\s+/, '')),
                      ),
                  }
                : { list: false, parts: inline(lines.join(' ')) };
        }),
);

function generate() {
    router.post(
        props.data.generateUrl,
        {},
        {
            preserveScroll: true,
            only: ['fuelAdvice', 'fuelPricesChart'],
            onStart: () => (generating.value = true),
            onFinish: () => (generating.value = false),
        },
    );
}

// The dashboard opens the advice from its notification under "Needs attention".
defineExpose({ show: () => (open.value = true) });
</script>

<template>
    <div class="border-b p-3">
        <button
            v-if="data.advice"
            type="button"
            :class="[
                'flex min-h-12 w-full cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-left transition hover:brightness-95 dark:hover:brightness-110',
                action.classes,
            ]"
            @click="open = true"
        >
            <AppIcon :name="action.icon" class="size-6 shrink-0" />
            <span class="flex min-w-0 flex-1 flex-col">
                <span
                    class="flex items-center gap-1.5 text-xs font-semibold tracking-wide uppercase opacity-80"
                >
                    <AppIcon name="ic:round-auto-awesome" class="size-3.5" />
                    {{ t('Fuel advice') }}
                    <span
                        v-if="!data.advice.isCurrent"
                        class="font-normal tracking-normal normal-case"
                    >
                        · {{ data.advice.generatedAt }}
                    </span>
                </span>
                <span class="font-medium text-foreground">
                    {{ data.advice.summary }}
                </span>
            </span>
            <ChevronRight class="size-5 shrink-0" />
        </button>

        <div
            v-else
            class="flex flex-wrap items-center gap-3 rounded-lg border border-dashed px-3 py-2"
        >
            <AppIcon
                name="ic:round-auto-awesome"
                class="size-6 shrink-0 text-muted-foreground"
            />
            <span class="min-w-0 flex-1 basis-56 text-sm text-muted-foreground">
                {{
                    t(
                        'Get an advice on when and where to refuel, based on the fuel prices and your refuelings.',
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
                {{ generating ? t('Generating…') : t('Generate advice') }}
            </Button>
        </div>
    </div>

    <Dialog v-if="data.advice" v-model:open="open">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <AppIcon
                        :name="action.icon"
                        :class="['size-6 shrink-0', action.text]"
                    />
                    {{ t('Fuel advice') }}
                </DialogTitle>
                <DialogDescription class="text-base text-foreground">
                    {{ data.advice.summary }}
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-wrap gap-2">
                <ColorBadge :color="confidences[data.advice.confidence].color">
                    {{ t(confidences[data.advice.confidence].label) }}
                </ColorBadge>
                <ColorBadge v-if="data.advice.fuelType">
                    <AppIcon
                        name="ic:baseline-local-gas-station"
                        class="size-3.5"
                    />
                    {{ data.advice.fuelType }}
                </ColorBadge>
                <ColorBadge v-if="data.advice.superPlusDue" color="info">
                    {{ t('Super Plus turn (1 in 4 times)') }}
                </ColorBadge>
                <ColorBadge v-if="data.advice.country" :color="action.color">
                    <CountryFlag
                        v-if="data.advice.country.isoCode"
                        :iso-code="data.advice.country.isoCode"
                        class="[&_svg]:size-3.5"
                    />
                    {{ data.advice.country.name }}
                </ColorBadge>
            </div>

            <!-- The numbers are calculated, not written by the AI model. -->
            <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
                <div
                    v-if="data.advice.recommendedDate"
                    class="rounded-lg border p-3"
                >
                    <dt class="text-xs text-muted-foreground">
                        {{ t('Recommended day') }}
                    </dt>
                    <dd class="font-semibold first-letter:uppercase">
                        {{ data.advice.recommendedDate }}
                    </dd>
                </div>
                <div
                    v-if="data.advice.expectedRefuelDate"
                    class="rounded-lg border p-3"
                >
                    <dt class="text-xs text-muted-foreground">
                        {{ t('Expected refueling') }}
                    </dt>
                    <dd class="font-semibold first-letter:uppercase">
                        {{ data.advice.expectedRefuelDate }}
                    </dd>
                </div>
                <div
                    v-if="data.advice.tankPercentage !== null"
                    class="rounded-lg border p-3"
                >
                    <dt class="text-xs text-muted-foreground">
                        {{ t('Estimated tank level') }}
                    </dt>
                    <dd class="font-semibold tabular-nums">
                        ± {{ data.advice.tankPercentage }}%
                    </dd>
                </div>
                <div v-if="data.advice.homePrice" class="rounded-lg border p-3">
                    <dt class="text-xs text-muted-foreground">
                        {{
                            t('Price in :country', {
                                country: data.advice.homePrice.name,
                            })
                        }}
                    </dt>
                    <dd class="font-semibold tabular-nums">
                        {{ data.advice.homePrice.price }}
                    </dd>
                </div>
                <div v-if="data.advice.bestPrice" class="rounded-lg border p-3">
                    <dt class="text-xs text-muted-foreground">
                        {{
                            t('Price in :country', {
                                country: data.advice.bestPrice.name,
                            })
                        }}
                    </dt>
                    <dd class="font-semibold tabular-nums">
                        {{ data.advice.bestPrice.price }}
                    </dd>
                </div>
                <div v-if="data.advice.bestPrice" class="rounded-lg border p-3">
                    <dt class="text-xs text-muted-foreground">
                        {{ t('Distance from home') }}
                    </dt>
                    <dd class="font-semibold tabular-nums">
                        {{ data.advice.bestPrice.distance }}
                    </dd>
                </div>
                <div v-if="data.advice.bestPrice" class="rounded-lg border p-3">
                    <dt class="text-xs text-muted-foreground">
                        {{ t('Net savings per full tank') }}
                    </dt>
                    <dd class="font-semibold text-success tabular-nums">
                        {{ data.advice.bestPrice.savings }}
                    </dd>
                </div>
                <div
                    v-if="data.advice.cheapestStation"
                    class="rounded-lg border p-3"
                >
                    <dt class="text-xs text-muted-foreground">
                        {{ t('Your cheapest gas station') }}
                    </dt>
                    <dd class="font-semibold">
                        {{ data.advice.cheapestStation.name }}
                    </dd>
                    <dd class="text-xs text-success tabular-nums">
                        {{
                            t(':difference below average', {
                                difference:
                                    data.advice.cheapestStation.difference,
                            })
                        }}
                    </dd>
                </div>
            </dl>

            <div class="flex flex-col gap-3 text-sm leading-relaxed">
                <template v-for="(block, index) in blocks" :key="index">
                    <ul v-if="block.list" class="list-disc space-y-1 pl-5">
                        <li v-for="(item, key) in block.items" :key="key">
                            <template v-for="(part, i) in item" :key="i">
                                <strong v-if="part.bold">{{
                                    part.text
                                }}</strong>
                                <template v-else>{{ part.text }}</template>
                            </template>
                        </li>
                    </ul>
                    <p v-else>
                        <template v-for="(part, i) in block.parts" :key="i">
                            <strong v-if="part.bold">{{ part.text }}</strong>
                            <template v-else>{{ part.text }}</template>
                        </template>
                    </p>
                </template>
            </div>

            <section
                v-if="data.advice.review"
                class="rounded-lg border bg-muted/40 p-3 text-sm"
            >
                <h3
                    class="mb-1 flex flex-wrap items-center gap-2 font-semibold"
                >
                    {{
                        t('Previous advice (:date)', {
                            date: data.advice.review.adviceDate,
                        })
                    }}
                    <ColorBadge
                        v-if="data.advice.review.followed !== null"
                        :color="
                            data.advice.review.followed ? 'success' : 'gray'
                        "
                    >
                        {{
                            data.advice.review.followed
                                ? t('Followed')
                                : t('Not followed')
                        }}
                    </ColorBadge>
                </h3>
                <p class="text-muted-foreground">
                    {{ data.advice.review.summary }}
                </p>
                <p class="mt-2">
                    {{
                        t('Refueled on :date in :country for :price.', {
                            date: data.advice.review.refuelDate,
                            country: data.advice.review.country,
                            price: data.advice.review.paid,
                        })
                    }}
                    <span
                        v-if="data.advice.review.cheaper"
                        class="text-success"
                    >
                        {{
                            t(
                                ':difference below the average price at the time of the advice (:average): :savings saved.',
                                {
                                    difference: data.advice.review.difference,
                                    average: data.advice.review.average,
                                    savings: data.advice.review.savings,
                                },
                            )
                        }}
                    </span>
                    <span v-else class="text-danger">
                        {{
                            t(
                                ':difference above the average price at the time of the advice (:average): :savings more.',
                                {
                                    difference: data.advice.review.difference,
                                    average: data.advice.review.average,
                                    savings: data.advice.review.savings,
                                },
                            )
                        }}
                    </span>
                </p>
            </section>

            <section v-if="data.advice.reasons.length">
                <h3 class="mb-2 text-sm font-semibold">{{ t('Reasons') }}</h3>
                <ul class="space-y-1.5 text-sm">
                    <li
                        v-for="reason in data.advice.reasons"
                        :key="reason"
                        class="flex gap-2"
                    >
                        <AppIcon
                            name="ic:round-check"
                            class="mt-0.5 size-4 shrink-0 text-success"
                        />
                        {{ reason }}
                    </li>
                </ul>
            </section>

            <section v-if="data.advice.sources.length">
                <h3 class="mb-2 text-sm font-semibold">{{ t('Sources') }}</h3>
                <ul class="flex flex-wrap gap-2 text-xs">
                    <li v-for="source in data.advice.sources" :key="source.url">
                        <a
                            :href="source.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex min-h-8 items-center rounded-md border px-2.5 underline-offset-2 hover:underline"
                        >
                            {{ source.title }}
                        </a>
                    </li>
                </ul>
            </section>

            <div
                class="flex flex-wrap items-center justify-end gap-3 border-t pt-4"
            >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="generating || !data.canGenerate"
                    :title="
                        data.canGenerate
                            ? undefined
                            : t('The fuel advice has just been generated.')
                    "
                    @click="generate"
                >
                    <RefreshCw
                        :class="['size-4', generating ? 'animate-spin' : '']"
                    />
                    {{ generating ? t('Generating…') : t('Generate again') }}
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
