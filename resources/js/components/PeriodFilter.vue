<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/composables/useT';
import { activePreset, periodPresets, presetRange } from '@/lib/period';
import type { DateRange } from '@/lib/period';

// The range that is currently applied.
const props = defineProps<{
    start?: string | null;
    end?: string | null;
    // Whether a range needs both dates before it is applied.
    complete?: boolean;
}>();

const emit = defineEmits<{ apply: [range: DateRange] }>();

const { t } = useT();

const from = ref(props.start ?? '');
const until = ref(props.end ?? '');

watch(
    () => [props.start, props.end],
    ([start, end]) => {
        from.value = start ?? '';
        until.value = end ?? '';
    },
);

const preset = computed(() => activePreset(props.start, props.end));
const invalid = computed(() =>
    Boolean(from.value && until.value && from.value > until.value),
);

function apply(range?: DateRange) {
    if (range) {
        from.value = range.start;
        until.value = range.end;
    }

    // Wait until the other date is picked as well.
    const incomplete =
        props.complete && Boolean(from.value) !== Boolean(until.value);

    if (invalid.value || incomplete) {
        return;
    }

    emit('apply', { start: from.value, end: until.value });
}
</script>

<template>
    <div class="grid gap-4">
        <div class="grid grid-cols-2 gap-2">
            <Button
                v-for="option in periodPresets"
                :key="option.name"
                type="button"
                size="sm"
                :variant="preset === option.name ? 'default' : 'outline'"
                :aria-pressed="preset === option.name"
                @click="apply(presetRange(option.name))"
            >
                {{ t(option.label) }}
            </Button>
        </div>
        <div class="grid gap-2">
            <Label for="period-from">{{ t('Date from') }}</Label>
            <Input
                id="period-from"
                v-model="from"
                type="date"
                :max="until || undefined"
                :aria-invalid="invalid"
                @change="apply()"
            />
        </div>
        <div class="grid gap-2">
            <Label for="period-until">{{ t('Date until') }}</Label>
            <Input
                id="period-until"
                v-model="until"
                type="date"
                :min="from || undefined"
                :aria-invalid="invalid"
                @change="apply()"
            />
        </div>
    </div>
</template>
