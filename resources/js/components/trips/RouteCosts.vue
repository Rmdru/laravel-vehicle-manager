<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useT } from '@/composables/useT';
import { useTripFormat } from '@/composables/useTripFormat';
import type { TripCosts } from '@/types/trips';

const props = defineProps<{
    costs: TripCosts;
    tollDistance: number;
    electric: boolean;
    // The stops are not known yet: neither are the fuel prices.
    loadingStops: boolean;
    timezone: string;
}>();

const { t } = useT();
const { kilometers, money, perLiter, countryName } = useTripFormat(
    () => props.timezone,
);
</script>

<template>
    <SectionCard
        :title="t('Costs')"
        icon="mdi:cash-multiple"
        collapsible
        persist-key="trip-costs"
        flush
    >
        <ul class="divide-y text-sm">
            <li class="flex items-start gap-3 px-4 py-2.5">
                <AppIcon
                    :name="
                        electric
                            ? 'mdi:battery-charging'
                            : 'ic:round-local-gas-station'
                    "
                    class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                />
                <div class="flex flex-1 flex-col">
                    <span>{{ electric ? t('Energy') : t('Fuel') }}</span>
                    <span
                        v-if="costs.energy != null"
                        class="text-xs text-muted-foreground"
                    >
                        <template v-if="electric">
                            {{
                                t('about :kwh kWh', {
                                    kwh: Math.round(costs.energy),
                                })
                            }}
                            · {{ t('charging prices are not known') }}
                        </template>
                        <template v-else-if="costs.fuel_price != null">
                            {{
                                t(':liters l at :price', {
                                    liters: Math.round(costs.energy),
                                    price: perLiter(costs.fuel_price),
                                })
                            }}
                        </template>
                        <template v-else-if="loadingStops">
                            {{ t('The fuel prices are looked up…') }}
                        </template>
                        <template v-else>
                            {{
                                t('about :liters l · no fuel prices known', {
                                    liters: Math.round(costs.energy),
                                })
                            }}
                        </template>
                    </span>
                </div>
                <span class="font-medium tabular-nums">{{
                    costs.fuel != null ? money(costs.fuel) : '–'
                }}</span>
            </li>
            <li
                v-if="tollDistance > 0"
                class="flex items-start gap-3 px-4 py-2.5"
            >
                <AppIcon
                    name="mdi:boom-gate"
                    class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                />
                <div class="flex flex-1 flex-col">
                    <span>{{ t('Toll') }}</span>
                    <span class="text-xs text-muted-foreground">
                        {{
                            t(':distance of toll road · amount not known', {
                                distance: kilometers(tollDistance),
                            })
                        }}
                    </span>
                </div>
                <span class="font-medium tabular-nums">?</span>
            </li>
            <li
                v-for="vignette in costs.vignettes"
                :key="vignette.country"
                class="flex items-start gap-3 px-4 py-2.5"
            >
                <AppIcon
                    name="mdi:sticker-text"
                    class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                />
                <div class="flex flex-1 flex-col">
                    <span>{{
                        t('Vignette :country', {
                            country: countryName(vignette.country),
                        })
                    }}</span>
                    <span class="text-xs text-muted-foreground">
                        {{
                            vignette.owned
                                ? t('You have one for the dates of this trip')
                                : vignette.price != null
                                  ? t('As much as the last one you bought')
                                  : t('Price not known: none registered yet')
                        }}
                    </span>
                </div>
                <span class="font-medium tabular-nums">{{
                    vignette.price != null ? money(vignette.price) : '?'
                }}</span>
            </li>
            <li
                v-if="costs.total != null"
                class="flex items-center gap-3 px-4 py-2.5 font-semibold"
            >
                <span class="size-4 shrink-0" />
                <span class="flex-1">{{
                    costs.complete ? t('Total') : t('Known costs')
                }}</span>
                <span class="tabular-nums">{{ money(costs.total) }}</span>
            </li>
        </ul>
    </SectionCard>
</template>
