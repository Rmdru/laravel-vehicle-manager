<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, RefreshCw } from '@lucide/vue';
import { ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import ColorBadge from '@/components/ColorBadge.vue';
import SectionCard from '@/components/SectionCard.vue';
import { Button } from '@/components/ui/button';
import Checkbox from '@/components/ui/checkbox/Checkbox.vue';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/composables/useT';

type State = 'overdue' | 'due' | 'unknown' | 'soon' | 'ok' | 'no_interval';

type Component = {
    key: string;
    label: string;
    icon: string;
    state: State;
    stateLabel: string;
    interval: string | null;
    intervalKm: number | null;
    intervalMonths: number | null;
    // Where the intervals come from: the defaults, the AI model or the owner.
    source: 'default' | 'ai' | 'manual';
    note: string | null;
    // When it was done last as the owner filled it in, for the form.
    ownDoneDate: string | null;
    ownDoneKm: number | null;
    done: {
        date: string;
        km: string | null;
        garage: string | null;
        price: string | null;
        // The maintenance it was done at; null when the owner filled it in.
        url: string | null;
    } | null;
    due: {
        date: string;
        relative: string;
        km: string | null;
        remainingKm: number | null;
        byDistance: boolean;
        estimated: boolean;
    } | null;
};

const props = defineProps<{
    // The most urgent first.
    components: Component[];
    inactive: {
        key: string;
        label: string;
        icon: string;
        note: string | null;
    }[];
    // Whether the intervals were ever proposed or changed.
    proposed: boolean;
    options: Record<string, string>;
    // What the AI model proposed for the tasks, for the owner to check.
    proposals: { task: string; components: string[] }[];
    unsortedTasks: number;
}>();

const { t } = useT();

const colors: Record<State, string> = {
    overdue: 'danger',
    due: 'warning',
    unknown: 'info',
    soon: 'warning',
    ok: 'success',
    no_interval: 'gray',
};

const busy = ref<'propose' | 'classify' | null>(null);

function ask(action: 'propose' | 'classify') {
    router.post(
        `/maintenance-plan/${action}`,
        {},
        {
            preserveScroll: true,
            onStart: () => (busy.value = action),
            onFinish: () => (busy.value = null),
        },
    );
}

// The proposals for the tasks, as the owner checks them.
const assignments = ref(props.proposals.map((item) => ({ ...item })));
watch(
    () => props.proposals,
    (proposals) => (assignments.value = proposals.map((item) => ({ ...item }))),
);

function toggle(index: number, key: string, on: boolean) {
    const item = assignments.value[index];

    item.components = on
        ? [...item.components, key]
        : item.components.filter((component) => component !== key);
}

const assigning = ref(false);

function assign() {
    router.post(
        '/maintenance-plan/assign',
        { assignments: assignments.value },
        {
            preserveScroll: true,
            onStart: () => (assigning.value = true),
            onFinish: () => (assigning.value = false),
        },
    );
}

// The component being edited.
const editing = ref<Component | null>(null);
// Empty is undefined in the inputs, and sent as null: no interval, or not known.
const form = useForm({
    active: true,
    interval_km: undefined as number | undefined,
    interval_months: undefined as number | undefined,
    done_date: undefined as string | undefined,
    done_km: undefined as number | undefined,
});

function edit(component: Component) {
    editing.value = component;
    form.defaults({
        active: true,
        interval_km: component.intervalKm ?? undefined,
        interval_months: component.intervalMonths ?? undefined,
        done_date: component.ownDoneDate ?? undefined,
        done_km: component.ownDoneKm ?? undefined,
    });
    form.reset();
    form.clearErrors();
}

function save(active = form.active) {
    form.transform((data) => ({
        active,
        interval_km: data.interval_km || null,
        interval_months: data.interval_months || null,
        done_date: data.done_date || null,
        done_km: data.done_km ?? null,
    })).patch(`/maintenance-plan/${editing.value!.key}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

function activate(key: string) {
    router.patch(
        `/maintenance-plan/${key}`,
        { active: true },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="t('Maintenance plan')" />

    <div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-2xl font-bold tracking-tight">
                {{ t('Maintenance plan') }}
            </h1>
            <Button
                v-if="proposed"
                variant="outline"
                size="sm"
                :disabled="busy !== null"
                @click="ask('propose')"
            >
                <RefreshCw
                    :class="[
                        'size-4',
                        busy === 'propose' ? 'animate-spin' : '',
                    ]"
                />
                {{ t('Propose the intervals again') }}
            </Button>
        </div>

        <SectionCard
            v-if="!proposed"
            :title="t('Fit the plan to your vehicle')"
            icon="ic:round-auto-awesome"
        >
            <div class="flex flex-wrap items-center gap-3">
                <p
                    class="min-w-0 flex-1 basis-64 text-sm text-muted-foreground"
                >
                    {{
                        t(
                            'The intervals below are the usual ones. Have them proposed for the make, model and engine of your vehicle; the ones you change yourself are kept.',
                        )
                    }}
                </p>
                <Button
                    size="sm"
                    :disabled="busy !== null"
                    @click="ask('propose')"
                >
                    <RefreshCw
                        :class="[
                            'size-4',
                            busy === 'propose' ? 'animate-spin' : '',
                        ]"
                    />
                    {{ t('Propose intervals') }}
                </Button>
            </div>
        </SectionCard>

        <SectionCard
            v-if="proposals.length"
            :title="t('Check the components of your tasks')"
            icon="ic:round-auto-awesome"
            flush
        >
            <p class="border-b p-4 text-sm text-muted-foreground">
                {{
                    t(
                        'These components were found in the tasks of your maintenance history. Untick what is not right; nothing is stored before you save.',
                    )
                }}
            </p>
            <ul class="divide-y">
                <li
                    v-for="(item, index) in assignments"
                    :key="item.task"
                    class="flex flex-col gap-2 px-4 py-3"
                >
                    <span class="font-medium">{{ item.task }}</span>
                    <div class="flex flex-wrap gap-x-4 gap-y-2">
                        <label
                            v-for="key in proposals[index].components"
                            :key="key"
                            class="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                :model-value="item.components.includes(key)"
                                @update:model-value="
                                    (checked) =>
                                        toggle(index, key, checked === true)
                                "
                            />
                            {{ options[key] ?? key }}
                        </label>
                    </div>
                </li>
            </ul>
            <div class="flex justify-end border-t p-3">
                <Button size="sm" :disabled="assigning" @click="assign">
                    {{ t('Save') }}
                </Button>
            </div>
        </SectionCard>

        <SectionCard
            v-else-if="unsortedTasks"
            :title="t('Link your maintenance history')"
            icon="ic:round-auto-awesome"
        >
            <div class="flex flex-wrap items-center gap-3">
                <p
                    class="min-w-0 flex-1 basis-64 text-sm text-muted-foreground"
                >
                    {{
                        t(
                            ':count tasks of your maintenance history are not linked to a component yet. Have them sorted out, and check the result before it is stored.',
                            { count: unsortedTasks },
                        )
                    }}
                </p>
                <Button
                    size="sm"
                    :disabled="busy !== null"
                    @click="ask('classify')"
                >
                    <RefreshCw
                        :class="[
                            'size-4',
                            busy === 'classify' ? 'animate-spin' : '',
                        ]"
                    />
                    {{ t('Sort out tasks') }}
                </Button>
            </div>
        </SectionCard>

        <SectionCard :title="t('Components')" flush>
            <ul class="divide-y">
                <li
                    v-for="component in components"
                    :key="component.key"
                    class="flex gap-3 px-4 py-3"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted"
                    >
                        <AppIcon :name="component.icon" class="size-5" />
                    </span>
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold">
                                {{ component.label }}
                            </span>
                            <ColorBadge :color="colors[component.state]">
                                {{ component.stateLabel }}
                            </ColorBadge>
                            <span
                                v-if="component.interval"
                                class="text-xs text-muted-foreground"
                                :title="
                                    component.source === 'ai'
                                        ? t('Proposed for your vehicle')
                                        : component.source === 'manual'
                                          ? t('Set by you')
                                          : t('Usual interval')
                                "
                            >
                                {{
                                    t('Every :interval', {
                                        interval: component.interval,
                                    })
                                }}
                            </span>
                        </div>
                        <div class="text-sm text-muted-foreground">
                            <template v-if="component.done">
                                {{ t('Last:') }}
                                <component
                                    :is="component.done.url ? Link : 'span'"
                                    :href="component.done.url ?? undefined"
                                    :class="{ underline: component.done.url }"
                                >
                                    {{ component.done.date }}
                                </component>
                                <template v-if="component.done.km">
                                    · {{ component.done.km }}
                                </template>
                                <template v-if="component.done.garage">
                                    · {{ component.done.garage }}
                                </template>
                                <template v-if="component.done.price">
                                    · {{ component.done.price }}
                                </template>
                            </template>
                            <template v-else>
                                {{
                                    t(
                                        'Not recorded yet: check it, or fill in when it was done.',
                                    )
                                }}
                            </template>
                        </div>
                        <div v-if="component.due" class="text-sm">
                            {{ t('Next:') }}
                            <span class="font-medium">
                                {{ component.due.estimated ? '± ' : ''
                                }}{{ component.due.date }}
                            </span>
                            ({{ component.due.relative }})
                            <template v-if="component.due.km">
                                · {{ t('at :km', { km: component.due.km }) }}
                            </template>
                        </div>
                        <p
                            v-if="component.note"
                            class="text-xs text-muted-foreground italic"
                        >
                            {{ component.note }}
                        </p>
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="t('Edit')"
                        :title="t('Edit')"
                        @click="edit(component)"
                    >
                        <Pencil class="size-4" />
                    </Button>
                </li>
            </ul>
        </SectionCard>

        <SectionCard
            v-if="inactive.length"
            :title="t('Not on this vehicle')"
            :badge="String(inactive.length)"
            collapsible
            collapsed
            persist-key="maintenance-plan-inactive"
            flush
        >
            <ul class="divide-y">
                <li
                    v-for="component in inactive"
                    :key="component.key"
                    class="flex flex-wrap items-center gap-3 px-4 py-3"
                >
                    <AppIcon
                        :name="component.icon"
                        class="size-5 shrink-0 text-muted-foreground"
                    />
                    <div class="flex min-w-0 flex-1 basis-48 flex-col">
                        <span class="font-medium">{{ component.label }}</span>
                        <span
                            v-if="component.note"
                            class="text-xs text-muted-foreground"
                        >
                            {{ component.note }}
                        </span>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="activate(component.key)"
                    >
                        {{ t('Add to the plan') }}
                    </Button>
                </li>
            </ul>
        </SectionCard>

        <Dialog :open="editing !== null" @update:open="editing = null">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ editing?.label }}</DialogTitle>
                </DialogHeader>
                <form class="grid gap-4" @submit.prevent="save()">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-1.5">
                            <Label for="interval_km">
                                {{ t('Interval (km)') }}
                            </Label>
                            <Input
                                id="interval_km"
                                v-model.number="form.interval_km"
                                type="number"
                                min="1"
                            />
                            <span
                                v-if="form.errors.interval_km"
                                class="text-xs text-danger"
                            >
                                {{ form.errors.interval_km }}
                            </span>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="interval_months">
                                {{ t('Interval (months)') }}
                            </Label>
                            <Input
                                id="interval_months"
                                v-model.number="form.interval_months"
                                type="number"
                                min="1"
                            />
                            <span
                                v-if="form.errors.interval_months"
                                class="text-xs text-danger"
                            >
                                {{ form.errors.interval_months }}
                            </span>
                        </div>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'Done before it was recorded here? Fill in when; a later maintenance with this component goes first.',
                            )
                        }}
                    </p>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-1.5">
                            <Label for="done_date">{{ t('Last done') }}</Label>
                            <Input
                                id="done_date"
                                v-model="form.done_date"
                                type="date"
                            />
                            <span
                                v-if="form.errors.done_date"
                                class="text-xs text-danger"
                            >
                                {{ form.errors.done_date }}
                            </span>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="done_km">{{ t('At (km)') }}</Label>
                            <Input
                                id="done_km"
                                v-model.number="form.done_km"
                                type="number"
                                min="0"
                            />
                        </div>
                    </div>
                    <div class="flex flex-wrap justify-between gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            :disabled="form.processing"
                            @click="save(false)"
                        >
                            {{ t('Not on this vehicle') }}
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            {{ t('Save') }}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
