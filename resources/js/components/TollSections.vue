<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import CountryFlag from '@/components/CountryFlag.vue';
import SectionCard from '@/components/SectionCard.vue';
import RoadBadge from '@/components/trips/RoadBadge.vue';
import { useT } from '@/composables/useT';

export type TollSectionsData = {
    key: string;
    label: string;
    company: string | null;
    isoCode: string | null;
    roads: { label: string; classes: string }[];
    // In the period.
    passages: number;
    total: string;
    // The median of the recent passages.
    usual: string;
    last: string;
    // At the pace of the last months.
    perMonth: string;
    perYear: string | null;
}[];

defineProps<{ sections: TollSectionsData; badge?: string }>();

const { t } = useT();
</script>

<template>
    <SectionCard
        :title="t('Toll by section')"
        :badge="badge"
        icon="mdi:boom-gate"
        flush
    >
        <template #actions>
            <Link
                href="/toll"
                class="mr-2 inline-flex min-h-9 items-center text-sm font-semibold"
            >
                {{ t('Toll') }}
            </Link>
        </template>
        <ul class="divide-y">
            <li
                v-for="section in sections"
                :key="section.key"
                class="flex items-start gap-3 px-4 py-3"
            >
                <CountryFlag
                    v-if="section.isoCode"
                    :iso-code="section.isoCode"
                    class="mt-0.5 [&_svg]:size-5"
                />
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <RoadBadge
                            v-for="road in section.roads"
                            :key="road.label"
                            :road="road"
                        />
                        <span class="font-semibold">{{ section.label }}</span>
                        <span
                            v-if="section.company"
                            class="text-sm text-muted-foreground"
                        >
                            · {{ section.company }}
                        </span>
                    </div>
                    <span class="text-sm text-muted-foreground tabular-nums">
                        {{
                            t(':count× · usually :price · last on :date', {
                                count: section.passages,
                                price: section.usual,
                                date: section.last,
                            })
                        }}
                    </span>
                    <span
                        v-if="section.perYear"
                        class="text-sm text-muted-foreground tabular-nums"
                    >
                        {{
                            t(
                                'At the pace of the last 3 months: :count× a month, about :amount a year.',
                                {
                                    count: section.perMonth,
                                    amount: section.perYear,
                                },
                            )
                        }}
                    </span>
                </div>
                <span class="font-semibold whitespace-nowrap tabular-nums">
                    {{ section.total }}
                </span>
            </li>
        </ul>
    </SectionCard>
</template>
