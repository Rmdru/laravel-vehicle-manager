<script setup lang="ts">
import BaseField from '@form-builder/components/Fields/BaseField.vue';
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';

type Option = { value: string; label: string; icon?: string | null };

// A multiple choice field of the form builder, as a row of buttons to tap.
const props = defineProps<{
    field: {
        name: string;
        label?: string;
        help?: string;
        disabled?: boolean;
        options: Option[];
    };
    error?: string;
    // An empty string as long as nothing has been chosen.
    modelValue?: string[] | string | null;
}>();

const emit = defineEmits<{ 'update:modelValue': [string[]] }>();

const selected = computed(() =>
    Array.isArray(props.modelValue) ? props.modelValue.map(String) : [],
);

const isSelected = (value: string) => selected.value.includes(value);

function toggle(value: string) {
    emit(
        'update:modelValue',
        isSelected(value)
            ? selected.value.filter((item) => item !== value)
            : [...selected.value, value],
    );
}
</script>

<template>
    <BaseField
        :label="field.label"
        :name="field.name"
        :error="error"
        :help="field.help"
    >
        <div class="flex flex-wrap gap-2">
            <button
                v-for="option in field.options"
                :key="option.value"
                type="button"
                :disabled="field.disabled"
                :aria-pressed="isSelected(option.value)"
                :class="[
                    'inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3 py-1 text-sm font-medium transition disabled:opacity-50',
                    isSelected(option.value)
                        ? 'border-transparent bg-primary text-primary-foreground'
                        : 'border-input text-muted-foreground hover:border-ring/50 hover:text-foreground',
                ]"
                @click="toggle(option.value)"
            >
                <AppIcon
                    v-if="option.icon"
                    :name="option.icon"
                    class="size-4 shrink-0"
                />
                {{ option.label }}
            </button>
        </div>
    </BaseField>
</template>
