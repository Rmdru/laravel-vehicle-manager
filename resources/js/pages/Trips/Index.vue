<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ChevronRight, Plus } from '@lucide/vue';
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import SectionCard from '@/components/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { useT } from '@/composables/useT';

defineProps<{
    trips: {
        id: string;
        name: string;
        vehicle: string | null;
        timeMode: 'departure' | 'arrival';
        timeAt: string;
        status: 'planning' | 'routed' | 'planned' | 'failed';
        distance: number | null;
        totalSeconds: number | null;
        url: string;
    }[];
    createUrl: string;
    timezone: string;
}>();

const { t } = useT();
const page = usePage();
const locale = computed(() => String(page.props.locale ?? 'en'));

function formatTime(iso: string, timezone: string): string {
    return new Intl.DateTimeFormat(locale.value, {
        timeZone: timezone,
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));
}

function duration(seconds: number): string {
    const minutes = Math.round(seconds / 60);

    return t(':hours h :minutes min', {
        hours: Math.floor(minutes / 60),
        minutes: String(minutes % 60).padStart(2, '0'),
    });
}
</script>

<template>
    <Head :title="t('My trips')" />

    <div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
        <div class="flex items-center gap-2">
            <h1 class="flex-1 text-2xl font-bold tracking-tight">
                {{ t('My trips') }}
            </h1>
            <Button as-child>
                <Link :href="createUrl">
                    <Plus class="size-4" />
                    {{ t('Plan a trip') }}
                </Link>
            </Button>
        </div>

        <SectionCard
            :title="t('Trips')"
            icon="mdi:map-search-outline"
            :badge="String(trips.length)"
            flush
        >
            <ul v-if="trips.length" class="divide-y">
                <li v-for="trip in trips" :key="trip.id">
                    <Link
                        :href="trip.url"
                        class="flex items-center gap-3 p-4 hover:bg-accent/50"
                    >
                        <AppIcon
                            name="mdi:map-marker-path"
                            class="size-5 shrink-0 text-muted-foreground"
                        />
                        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="truncate font-medium">{{
                                trip.name
                            }}</span>
                            <span class="text-sm text-muted-foreground">
                                {{
                                    trip.timeMode === 'departure'
                                        ? t('Depart at')
                                        : t('Arrive at')
                                }}
                                {{ formatTime(trip.timeAt, timezone) }}
                                <template v-if="trip.vehicle">
                                    · {{ trip.vehicle }}
                                </template>
                            </span>
                        </div>
                        <span
                            v-if="trip.status === 'planned' && trip.distance"
                            class="hidden text-right text-sm tabular-nums sm:block"
                        >
                            {{ Math.round(trip.distance / 1000) }} km<br />
                            <span class="text-muted-foreground">{{
                                duration(trip.totalSeconds ?? 0)
                            }}</span>
                        </span>
                        <span
                            v-else-if="
                                trip.status === 'planning' ||
                                trip.status === 'routed'
                            "
                            class="text-sm text-muted-foreground"
                        >
                            {{ t('Planning…') }}
                        </span>
                        <span
                            v-else-if="trip.status === 'failed'"
                            class="text-sm text-destructive"
                        >
                            {{ t('Not planned') }}
                        </span>
                        <ChevronRight
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                    </Link>
                </li>
            </ul>
            <p v-else class="p-4 text-sm text-muted-foreground">
                {{
                    t(
                        'Plan a trip to see the routes, the time it takes and where to stop for a break, a meal or fuel.',
                    )
                }}
            </p>
        </SectionCard>
    </div>
</template>
