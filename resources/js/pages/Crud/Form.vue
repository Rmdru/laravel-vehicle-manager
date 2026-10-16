<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Form from '@form-builder/components/Form.vue';
import { ArrowLeft, Trash2 } from '@lucide/vue';
import ToggleButtons from '@/components/ToggleButtons.vue';
import { Button } from '@/components/ui/button';
import { useT } from '@/composables/useT';

const props = defineProps<{
    // The schema produced by the form builder; rendered as-is.
    form: Record<string, unknown>;
    title: string;
    indexUrl: string;
    indexTitle: string;
    destroyUrl?: string;
    intro?: string | null;
    // Forms with behaviour beyond what the schema can express.
    variant?: 'refueling' | 'vehicle';
    rdwLookupUrl?: string;
    // What the RDW knows about the vehicle that needs attention.
    warning?: string | null;
}>();

const { t } = useT();

function destroy() {
    if (confirm(t('Are you sure you want to delete this?'))) {
        router.delete(props.destroyUrl!);
    }
}

type FormState = Record<string, any>;

// Keep amount, unit price and total price of a refueling consistent.
function calculateRefueling(field: string, form: FormState) {
    const amount = Number(form.amount);
    const unitPrice = Number(form.unit_price);
    const totalPrice = Number(form.total_price);

    if (
        ['amount', 'unit_price'].includes(field) &&
        amount > 0 &&
        unitPrice > 0
    ) {
        form.total_price = Math.round(amount * unitPrice * 100) / 100;
    } else if (
        field === 'total_price' &&
        !amount &&
        unitPrice > 0 &&
        totalPrice > 0
    ) {
        form.amount = Math.round((totalPrice / unitPrice) * 100) / 100;
    }
}

let lookup: ReturnType<typeof setTimeout> | undefined;

const warning = ref(props.warning ?? null);

// Prefill the vehicle form from the RDW registration once typing stops.
function lookupLicensePlate(licensePlate: string, form: FormState) {
    clearTimeout(lookup);

    if (!props.rdwLookupUrl || licensePlate.trim().length < 6) {
        return;
    }

    lookup = setTimeout(async () => {
        const url = `${props.rdwLookupUrl}?license_plate=${encodeURIComponent(licensePlate)}`;
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            return;
        }

        const { values = {}, warning: rdwWarning = null } =
            await response.json();

        // Lists (fuel types, specifications) are only filled when still empty.
        for (const [field, value] of Object.entries(values)) {
            if (
                !Array.isArray(value) ||
                !Array.isArray(form[field]) ||
                !form[field].length
            ) {
                form[field] = value;
            }
        }

        warning.value = rdwWarning;
    }, 600);
}

function onFieldChange(field: string, value: unknown, form: FormState) {
    if (props.variant === 'refueling') {
        calculateRefueling(field, form);
    }

    if (props.variant === 'vehicle' && field === 'license_plate') {
        lookupLicensePlate(String(value ?? ''), form);
    }
}
</script>

<template>
    <Head :title="title" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <Link
                    :href="indexUrl"
                    class="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft class="size-4" />
                    {{ indexTitle }}
                </Link>
                <h1 class="text-2xl font-bold tracking-tight">{{ title }}</h1>
            </div>
            <Button
                v-if="destroyUrl"
                variant="destructive"
                data-test="delete-button"
                @click="destroy"
            >
                <Trash2 class="size-4" />
                {{ t('Delete') }}
            </Button>
        </div>

        <p v-if="intro" class="rounded-lg bg-info-muted p-3 text-sm text-info">
            {{ intro }}
        </p>

        <p
            v-if="warning"
            role="alert"
            class="rounded-lg bg-warning-muted p-3 text-sm text-warning"
        >
            {{ warning }}
        </p>

        <!-- A full-width select button may not shrink in the form builder, which pushes
             the clear button of a searchable select into the next column. -->
        <div
            class="rounded-xl border bg-card p-4 md:p-6 [&_button.w-full]:min-w-0 [&_button.w-full]:shrink"
        >
            <Form :schema="form" :columns="2" :on-field-change="onFieldChange">
                <template #heading="{ field }">
                    <h2 class="mt-4 border-b pb-2 text-lg font-semibold">
                        {{ field.label }}
                    </h2>
                </template>
                <template #checkboxes="slotProps">
                    <ToggleButtons v-bind="slotProps" />
                </template>
            </Form>
        </div>
    </div>
</template>
