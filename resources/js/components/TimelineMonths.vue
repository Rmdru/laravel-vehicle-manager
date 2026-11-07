<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import AppIcon from '@/components/AppIcon.vue';
import ColorBadge from '@/components/ColorBadge.vue';
import CountryFlag from '@/components/CountryFlag.vue';
import { useT } from '@/composables/useT';
import { timelineTint } from '@/lib/timeline';

export type TimelineItem = {
    key: string;
    heading: string;
    icon: string;
    // The navigation group of the record: maintenance, road or fixed.
    group: string;
    logo: string | null;
    flag: { isoCode: string; name: string } | null;
    date: string | null;
    price: string | null;
    mileage: string | null;
    location: string | null;
    badges: { title: string; color: string; icon: string | null }[];
    roads: { label: string; classes: string }[];
    url: string | null;
};

export type TimelineMonth = {
    month: string;
    items: TimelineItem[];
    // Of the predictions: the costs to expect that month.
    expected?: string | null;
};

defineProps<{ months: TimelineMonth[] }>();

const { t } = useT();

const meta = (item: TimelineItem) =>
    [item.date, item.location, item.mileage].filter(Boolean).join(' · ');
</script>

<template>
    <section v-for="group in months" :key="group.month" class="flex flex-col">
        <h2
            class="flex flex-wrap items-baseline justify-between gap-x-3 pb-3 text-lg font-bold"
        >
            {{ group.month }}
            <span
                v-if="group.expected"
                class="text-sm font-medium text-muted-foreground tabular-nums"
                :title="
                    t(
                        'The fixed costs of this month, and of the other costs the average of the past year.',
                    )
                "
            >
                {{ t('Expected :amount', { amount: group.expected }) }}
            </span>
        </h2>
        <div v-for="item in group.items" :key="item.key" class="flex gap-3">
            <div
                class="flex w-10 shrink-0 flex-col items-center"
                aria-hidden="true"
            >
                <span
                    :class="[
                        'flex size-10 shrink-0 items-center justify-center rounded-full',
                        timelineTint(item.group),
                    ]"
                >
                    <AppIcon :name="item.icon" class="size-5" />
                </span>
                <span class="w-0.5 flex-1 bg-input" />
            </div>
            <component
                :is="item.url ? Link : 'div'"
                :href="item.url ?? undefined"
                class="mb-3 flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border bg-card px-4 py-3"
                :class="{ 'hover:border-ring/50': item.url }"
            >
                <span
                    v-if="item.logo"
                    class="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-white p-1"
                >
                    <img
                        :src="item.logo"
                        alt=""
                        class="max-h-full max-w-full"
                        loading="lazy"
                    />
                </span>
                <span class="flex min-w-0 flex-1 basis-64 flex-col gap-1">
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold">{{ item.heading }}</span>
                        <CountryFlag
                            v-if="item.flag"
                            :iso-code="item.flag.isoCode"
                            :name="item.flag.name"
                            class="[&>svg]:size-5"
                        />
                        <span
                            v-for="road in item.roads"
                            :key="road.label"
                            :class="[
                                'rounded px-1 text-xs font-bold',
                                road.classes,
                            ]"
                        >
                            {{ road.label }}
                        </span>
                        <ColorBadge
                            v-for="badge in item.badges"
                            :key="badge.title"
                            :color="badge.color"
                        >
                            <AppIcon
                                v-if="badge.icon"
                                :name="badge.icon"
                                class="size-3.5"
                            />
                            {{ badge.title }}
                        </ColorBadge>
                    </span>
                    <span class="text-sm text-muted-foreground">{{
                        meta(item)
                    }}</span>
                </span>
                <span
                    v-if="item.price"
                    class="font-semibold whitespace-nowrap tabular-nums"
                >
                    {{ item.price }}
                </span>
                <ChevronRight
                    v-if="item.url"
                    class="size-4 shrink-0 text-muted-foreground"
                />
            </component>
        </div>
    </section>
</template>
