<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import AppIcon from '@/components/AppIcon.vue';
import SectionCard from '@/components/SectionCard.vue';
import PlaceInput from '@/components/trips/PlaceInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/composables/useT';
import type { TripFormData } from '@/types/trips';

// The start, the destinations along the way and the destination, the time and
// the tank: the form is the page's, so that the map shows the places entered.
const props = defineProps<{
    form: InertiaForm<TripFormData>;
    // The name of the trip, when it is planned already.
    tripName: string | null;
    vehicle: string | null;
    geocodeUrl: string;
    reverseGeocodeUrl: string;
    timezone: string;
    deleting: boolean;
}>();

const emit = defineEmits<{
    submit: [];
    destroy: [];
}>();

const { t } = useT();

// The errors of the waypoints are keyed by their position.
function waypointError(index: number): boolean {
    const errors = props.form.errors as Record<string, string | undefined>;

    return !!(
        errors[`waypoints.${index}`] ?? errors[`waypoints.${index}.latitude`]
    );
}

// The current time, in the time zone the times are entered in.
function setNow() {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-GB', {
            timeZone: props.timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        })
            .formatToParts(new Date())
            .map((part) => [part.type, part.value]),
    );

    props.form.timeAt = `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

function addWaypoint() {
    props.form.waypoints.splice(props.form.waypoints.length - 1, 0, null);
}

function removeWaypoint(index: number) {
    props.form.waypoints.splice(index, 1);
}

function reverse() {
    props.form.waypoints.reverse();
}
</script>

<template>
    <SectionCard :title="t('Trip')" icon="mdi:map-marker-path">
        <form class="flex flex-col gap-4" @submit.prevent="emit('submit')">
            <div class="flex flex-col gap-2">
                <div
                    v-for="(waypoint, index) in form.waypoints"
                    :key="index"
                    class="flex flex-col gap-1"
                >
                    <div class="flex items-start gap-2">
                        <PlaceInput
                            v-model="form.waypoints[index]"
                            :geocode-url="geocodeUrl"
                            :reverse-geocode-url="reverseGeocodeUrl"
                            :icon="
                                index === 0
                                    ? 'mdi:map-marker'
                                    : index === form.waypoints.length - 1
                                      ? 'mdi:flag-checkered'
                                      : 'mdi:map-marker-radius'
                            "
                            :placeholder="
                                index === 0
                                    ? t('Start')
                                    : index === form.waypoints.length - 1
                                      ? t('Destination')
                                      : t('Stop along the way')
                            "
                            :invalid="waypointError(index)"
                        />
                        <Button
                            v-if="
                                index > 0 && index < form.waypoints.length - 1
                            "
                            type="button"
                            variant="ghost"
                            size="icon"
                            :aria-label="t('Remove')"
                            @click="removeWaypoint(index)"
                        >
                            <AppIcon name="mdi:close" class="size-4" />
                        </Button>
                        <Button
                            v-else-if="index === 0"
                            type="button"
                            variant="ghost"
                            size="icon"
                            :aria-label="t('Reverse')"
                            @click="reverse"
                        >
                            <AppIcon name="mdi:swap-vertical" class="size-4" />
                        </Button>
                        <span v-else class="size-9 shrink-0" />
                    </div>
                    <div
                        v-if="
                            index > 0 &&
                            index < form.waypoints.length - 1 &&
                            waypoint
                        "
                        class="flex items-center gap-2 pl-8 text-sm text-muted-foreground"
                    >
                        <Label :for="`stay-${index}`">{{
                            t('Stay (minutes)')
                        }}</Label>
                        <Input
                            :id="`stay-${index}`"
                            v-model.number="waypoint.stayMinutes"
                            type="number"
                            min="0"
                            max="1440"
                            class="h-8 w-24 pointer-coarse:h-10"
                        />
                    </div>
                    <p
                        v-if="waypointError(index)"
                        class="pl-8 text-sm text-destructive"
                    >
                        {{ t('Choose a place from the list.') }}
                    </p>
                </div>
                <Button
                    v-if="form.waypoints.length < 10"
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="self-start"
                    @click="addWaypoint"
                >
                    <AppIcon name="mdi:plus" class="size-4" />
                    {{ t('Add a stop along the way') }}
                </Button>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2 flex rounded-md border p-0.5">
                    <button
                        v-for="mode in ['departure', 'arrival'] as const"
                        :key="mode"
                        type="button"
                        class="flex-1 rounded px-3 py-1.5 text-sm font-medium"
                        :class="
                            form.timeMode === mode
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground'
                        "
                        @click="form.timeMode = mode"
                    >
                        {{
                            mode === 'departure'
                                ? t('Depart at')
                                : t('Arrive at')
                        }}
                    </button>
                </div>
                <div class="col-span-2 flex flex-col gap-1.5">
                    <Label for="time-at">{{
                        form.timeMode === 'departure'
                            ? t('Depart at')
                            : t('Arrive at')
                    }}</Label>
                    <div class="flex gap-2">
                        <Input
                            id="time-at"
                            v-model="form.timeAt"
                            type="datetime-local"
                            class="dark:scheme-dark"
                            :aria-invalid="!!form.errors.timeAt || undefined"
                        />
                        <Button type="button" variant="outline" @click="setNow">
                            {{ t('Now') }}
                        </Button>
                    </div>
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="fuel-level">{{
                        t('Tank at departure (%)')
                    }}</Label>
                    <Input
                        id="fuel-level"
                        v-model.number="form.fuelLevel"
                        type="number"
                        min="5"
                        max="100"
                        step="5"
                    />
                </div>
                <p
                    v-if="form.errors.timeAt"
                    class="col-span-2 text-sm text-destructive"
                >
                    {{ form.errors.timeAt }}
                </p>
                <div class="flex flex-col gap-1.5">
                    <Label for="name">{{ t('Name (optional)') }}</Label>
                    <Input id="name" v-model="form.name" maxlength="100" />
                </div>
                <p
                    v-if="vehicle"
                    class="col-span-2 flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <AppIcon name="mdi:car" class="size-4 shrink-0" />
                    {{ t('For :vehicle', { vehicle }) }}
                </p>
            </div>

            <div class="flex items-center gap-2">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    class="flex-1"
                >
                    {{ tripName ? t('Plan again') : t('Plan trip') }}
                </Button>
                <Dialog v-if="tripName">
                    <DialogTrigger as-child>
                        <Button type="button" variant="outline">
                            {{ t('Delete') }}
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{{
                                t('Delete this trip?')
                            }}</DialogTitle>
                            <DialogDescription>
                                {{
                                    t(
                                        ':name is deleted with its routes and stops. This cannot be undone.',
                                        { name: tripName },
                                    )
                                }}
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button type="button" variant="secondary">
                                    {{ t('Cancel') }}
                                </Button>
                            </DialogClose>
                            <Button
                                type="button"
                                variant="destructive"
                                :disabled="deleting"
                                @click="emit('destroy')"
                            >
                                {{ t('Delete') }}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </form>
    </SectionCard>
</template>
