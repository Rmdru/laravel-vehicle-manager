<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue';
import SectionCard from '@/components/SectionCard.vue';
import RoadBadge from '@/components/trips/RoadBadge.vue';
import { useT } from '@/composables/useT';
import { useTripFormat } from '@/composables/useTripFormat';
import { TRAFFIC } from '@/lib/trips';
import type { TrafficIncident } from '@/types/trips';

const props = defineProps<{
    traffic: TrafficIncident[];
    // The daily limit of TomTom was near: the traffic was left out.
    skipped: boolean;
    timezone: string;
}>();

const emit = defineEmits<{
    show: [incident: TrafficIncident];
}>();

const { t } = useT();
const { clock, day, kilometers } = useTripFormat(() => props.timezone);

function when(iso: string | null): string {
    return iso ? `${day(iso)} ${clock(iso)}` : '';
}
</script>

<template>
    <SectionCard
        :title="t('Traffic')"
        icon="mdi:traffic-cone"
        :badge="String(traffic.length)"
        collapsible
        persist-key="trip-traffic"
        flush
    >
        <p
            v-if="skipped"
            class="flex gap-2 border-b bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-900/20 dark:text-amber-100"
        >
            <AppIcon name="mdi:alert-outline" class="mt-0.5 size-4 shrink-0" />
            {{
                t(
                    'The traffic is left out today: the daily limit of the traffic service is almost reached. Refresh tomorrow to see it.',
                )
            }}
        </p>
        <ul v-if="traffic.length" class="divide-y">
            <li v-for="(incident, index) in traffic" :key="index">
                <button
                    type="button"
                    class="flex w-full gap-3 px-4 py-2.5 text-left hover:bg-accent/50"
                    @click="emit('show', incident)"
                >
                    <span
                        class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full text-white"
                        :style="{
                            background: TRAFFIC[incident.category]?.color,
                        }"
                    >
                        <AppIcon
                            :name="
                                TRAFFIC[incident.category]?.icon ?? 'mdi:alert'
                            "
                            class="size-3.5"
                        />
                    </span>
                    <span class="flex min-w-0 flex-col text-sm">
                        <span class="font-medium">
                            {{
                                t(
                                    TRAFFIC[incident.category]?.label ??
                                        'Traffic',
                                )
                            }}
                            <RoadBadge
                                v-if="incident.road_badge"
                                :road="incident.road_badge"
                                class="ml-1 align-middle"
                            />
                            <template v-else-if="incident.road">
                                · {{ incident.road }}</template
                            >
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{ kilometers(incident.along) }}
                            <template v-if="incident.description">
                                · {{ incident.description }}
                            </template>
                            <template v-if="incident.delay">
                                ·
                                {{
                                    t('+:minutes min', {
                                        minutes: Math.round(
                                            incident.delay / 60,
                                        ),
                                    })
                                }}
                            </template>
                        </span>
                        <span
                            v-if="incident.start || incident.end"
                            class="text-xs text-muted-foreground"
                        >
                            {{ when(incident.start) }} –
                            {{
                                incident.end
                                    ? when(incident.end)
                                    : t('until further notice')
                            }}
                        </span>
                    </span>
                </button>
            </li>
        </ul>
        <p v-else-if="!skipped" class="p-4 text-sm text-muted-foreground">
            {{
                t(
                    'No road works, closures or other traffic are known on this route.',
                )
            }}
        </p>
    </SectionCard>
</template>
