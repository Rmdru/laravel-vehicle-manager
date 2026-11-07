<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/composables/useT';

export type TipComponent = { key: string; label: string; icon: string };

// What was done, recorded with the components of the maintenance plan.
export type TipRecord = {
    kind: 'checked' | 'replaced';
    components: string[];
    date: string;
    km: number | null;
};

const props = defineProps<{
    title: string;
    components: TipComponent[];
    today: string;
    mileage: number | null;
}>();

const open = defineModel<boolean>('open', { required: true });

// Done with what was done, or done without (skipped).
const emit = defineEmits<{ done: [record: TipRecord | null] }>();

const { t } = useT();

const kind = ref<TipRecord['kind']>('checked');
const selected = ref<string[]>([]);
const date = ref(props.today);
// Empty when it is not known.
const km = ref<number | string | undefined>(props.mileage ?? undefined);

// A single component is the one it is about; of more, the owner picks.
watch(
    open,
    (isOpen) => {
        if (isOpen) {
            kind.value = 'checked';
            selected.value =
                props.components.length === 1 ? [props.components[0].key] : [];
            date.value = props.today;
            km.value = props.mileage ?? undefined;
        }
    },
    { immediate: true },
);

const valid = computed(
    () =>
        selected.value.length > 0 &&
        date.value !== '' &&
        date.value <= props.today,
);

function toggle(key: string, checked: boolean) {
    selected.value = checked
        ? [...selected.value, key]
        : selected.value.filter((item) => item !== key);
}

function save() {
    emit('done', {
        kind: kind.value,
        components: selected.value,
        date: date.value,
        km:
            typeof km.value === 'number' && !Number.isNaN(km.value)
                ? km.value
                : null,
    });
    open.value = false;
}

function skip() {
    emit('done', null);
    open.value = false;
}

const kinds = [
    {
        value: 'checked',
        icon: 'mdi:clipboard-check-outline',
        label: 'Checked, in order',
        hint: 'The interval does not start again.',
    },
    {
        value: 'replaced',
        icon: 'mdi:wrench',
        label: 'Replaced or refreshed',
        hint: 'The interval starts again from this date.',
    },
] as const;
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('What did you do?') }}</DialogTitle>
                <DialogDescription>{{ title }}</DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="save">
                <div class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="option in kinds"
                        :key="option.value"
                        type="button"
                        :aria-pressed="kind === option.value"
                        :class="[
                            'flex cursor-pointer flex-col items-start gap-1 rounded-lg border p-3 text-left text-sm transition-colors',
                            kind === option.value
                                ? 'border-primary bg-primary/5'
                                : 'hover:bg-muted/60',
                        ]"
                        @click="kind = option.value"
                    >
                        <span class="flex items-center gap-2 font-semibold">
                            <AppIcon :name="option.icon" class="size-4" />
                            {{ t(option.label) }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{ t(option.hint) }}
                        </span>
                    </button>
                </div>

                <div v-if="components.length > 1" class="grid gap-2">
                    <span class="text-sm font-medium">{{ t('Which?') }}</span>
                    <label
                        v-for="component in components"
                        :key="component.key"
                        class="flex min-h-9 items-center gap-2 text-sm"
                    >
                        <Checkbox
                            :model-value="selected.includes(component.key)"
                            @update:model-value="
                                (checked) =>
                                    toggle(component.key, checked === true)
                            "
                        />
                        <AppIcon
                            :name="component.icon"
                            class="size-4 text-muted-foreground"
                        />
                        {{ component.label }}
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <Label for="tip_done_date">{{ t('Date') }}</Label>
                        <Input
                            id="tip_done_date"
                            v-model="date"
                            type="date"
                            :max="today"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="tip_done_km">{{ t('At (km)') }}</Label>
                        <Input
                            id="tip_done_km"
                            v-model.number="km"
                            type="number"
                            min="0"
                        />
                    </div>
                </div>

                <div class="flex flex-wrap justify-between gap-2">
                    <Button type="button" variant="ghost" @click="skip">
                        {{ t('Skip') }}
                    </Button>
                    <Button type="submit" :disabled="!valid">
                        {{ t('Save') }}
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
