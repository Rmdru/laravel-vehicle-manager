<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import RoadBadge from '@/components/trips/RoadBadge.vue';
import { useT } from '@/composables/useT';
import { TRAFFIC } from '@/lib/trips';
import type { TrafficIncident } from '@/types/trips';

// A road work, closure or jam on the route, in its popup on the map.
const props = defineProps<{ incident: TrafficIncident; timezone: string }>();

const { t } = useT();
const page = usePage();
const locale = computed(() => String(page.props.locale ?? 'en'));
const kind = computed(() => TRAFFIC[props.incident.category]);

function moment(iso: string): string {
    return new Intl.DateTimeFormat(locale.value, {
        timeZone: props.timezone,
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));
}
</script>

<template>
    <div class="flex w-72 flex-col gap-2 p-4 text-sm">
        <div class="flex items-start gap-3 pr-5">
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-lg text-white"
                :style="{ background: kind?.color }"
            >
                <AppIcon :name="kind?.icon ?? 'mdi:alert'" class="size-5" />
            </span>
            <div class="min-w-0">
                <div class="leading-snug font-semibold">
                    {{ t(kind?.label ?? 'Traffic') }}
                    <RoadBadge
                        v-if="incident.road_badge"
                        :road="incident.road_badge"
                        class="ml-1 align-middle"
                    />
                    <template v-else-if="incident.road">
                        · {{ incident.road }}</template
                    >
                </div>
                <div v-if="incident.description" class="text-muted-foreground">
                    {{ incident.description }}
                </div>
            </div>
        </div>
        <div
            v-if="incident.from || incident.to"
            class="text-xs text-muted-foreground"
        >
            {{ incident.from }} → {{ incident.to }}
        </div>
        <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs">
            <span v-if="incident.length">
                {{
                    t(':distance km', {
                        distance: (incident.length / 1000).toFixed(1),
                    })
                }}
            </span>
            <span v-if="incident.delay" class="font-medium text-destructive">
                {{
                    t('+:minutes min', {
                        minutes: Math.round(incident.delay / 60),
                    })
                }}
            </span>
            <span v-if="incident.start || incident.end">
                {{ incident.start ? moment(incident.start) : '' }} –
                {{
                    incident.end
                        ? moment(incident.end)
                        : t('until further notice')
                }}
            </span>
        </div>
    </div>
</template>
