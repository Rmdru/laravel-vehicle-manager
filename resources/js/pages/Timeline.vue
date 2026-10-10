<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppIcon from '@/components/AppIcon.vue';
import ColorBadge from '@/components/ColorBadge.vue';
import CountryFlag from '@/components/CountryFlag.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useT } from '@/composables/useT';

type TimelineItem = {
    key: string;
    heading: string;
    icon: string;
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

type TimelineMonth = { month: string; items: TimelineItem[] };

defineProps<{
    history: TimelineMonth[];
    predictions: TimelineMonth[];
}>();

const { t } = useT();
</script>

<template>
    <Head :title="t('Timeline')" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-2xl font-semibold">{{ t('Timeline') }}</h1>

        <template
            v-for="(months, kind) in { predictions, history }"
            :key="kind"
        >
            <component
                :is="kind === 'predictions' ? SectionCard : 'div'"
                v-if="months.length"
                v-bind="
                    kind === 'predictions'
                        ? {
                              title: t('Predictions'),
                              icon: 'gmdi-auto-awesome-r',
                              collapsed: true,
                          }
                        : {}
                "
                class="flex flex-col gap-4"
            >
                <section
                    v-for="group in months"
                    :key="group.month"
                    class="flex flex-col gap-2"
                >
                    <h2 class="text-xl font-bold">{{ group.month }}</h2>
                    <article
                        v-for="item in group.items"
                        :key="item.key"
                        class="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl border border-sidebar-border/70 p-3"
                    >
                        <CountryFlag
                            v-if="item.flag"
                            :iso-code="item.flag.isoCode"
                            :name="item.flag.name"
                        />
                        <span
                            v-else
                            class="flex size-10 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-white p-2 text-black"
                        >
                            <img
                                v-if="item.logo"
                                :src="item.logo"
                                alt=""
                                class="max-h-full"
                                loading="lazy"
                            />
                            <AppIcon v-else :name="item.icon" class="size-5" />
                        </span>
                        <span class="flex gap-1">
                            <span
                                v-for="road in item.roads"
                                :key="road.label"
                                :class="['rounded px-1 font-bold', road.classes]"
                                >{{ road.label }}</span
                            >
                        </span>
                        <h3 class="flex min-w-32 items-center gap-2 font-semibold">
                            <AppIcon
                                :name="item.icon"
                                class="size-5 shrink-0 text-muted-foreground"
                            />
                            {{ item.heading }}
                        </h3>
                        <div
                            class="flex flex-1 flex-wrap gap-x-4 gap-y-1 text-sm"
                        >
                            <span
                                v-if="item.date"
                                class="flex items-center gap-1.5"
                            >
                                <AppIcon
                                    name="gmdi-calendar-month-r"
                                    class="size-5 text-muted-foreground"
                                />
                                {{ item.date }}
                            </span>
                            <span
                                v-if="item.price"
                                class="flex items-center gap-1.5"
                            >
                                <AppIcon
                                    name="mdi-hand-coin-outline"
                                    class="size-5 text-muted-foreground"
                                />
                                {{ item.price }}
                            </span>
                            <span
                                v-if="item.mileage"
                                class="flex items-center gap-1.5"
                            >
                                <AppIcon
                                    name="gmdi-route-r"
                                    class="size-5 text-muted-foreground"
                                />
                                {{ item.mileage }}
                            </span>
                            <span
                                v-if="item.location"
                                class="flex items-center gap-1.5"
                            >
                                <AppIcon
                                    name="gmdi-location-on-r"
                                    class="size-5 text-muted-foreground"
                                />
                                {{ item.location }}
                            </span>
                        </div>
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
                        <Link
                            v-if="item.url"
                            :href="item.url"
                            class="ml-auto flex items-center gap-1 text-sm hover:underline"
                        >
                            <AppIcon name="gmdi-remove-red-eye-r" class="size-4" />
                            {{ t('Show') }}
                        </Link>
                    </article>
                </section>
            </component>
        </template>

        <p
            v-if="!history.length"
            class="rounded-xl border border-sidebar-border/70 p-4 text-xl font-bold"
        >
            {{ t('Nothing to show') }}
        </p>
    </div>
</template>