<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Info, MoreHorizontal, Pencil, Plus, Trash2 } from '@lucide/vue';
import TableBuilder from '@table-builder/components/TableBuilder.vue';
import { computed, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import FilterChips from '@/components/FilterChips.vue';
import type { FilterChip } from '@/components/FilterChips.vue';
import FilterSheet from '@/components/FilterSheet.vue';
import PeriodFilter from '@/components/PeriodFilter.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/composables/useT';
import { periodLabel } from '@/lib/period';
import type { DateRange } from '@/lib/period';

const props = defineProps<{
    // The table produced by the table builder; rendered as-is.
    table: Record<string, unknown> & { name?: string };
    title: string;
    icon?: string | null;
    description?: string | null;
    // Whether records have a page of their own, besides the edit form.
    showable?: boolean;
    baseUrl: string;
    createUrl: string;
    summary: { label: string; value: string }[];
    filters: { date_from?: string; date_until?: string };
    smallCheck?: { url: string; options: Record<string, string> };
}>();

type TableFilter = {
    key: string;
    label: string;
    options: Record<string, string>;
    value: string | null;
};

const { t } = useT();

const showDescription = ref(false);

// The table builder would offer the filters in a menu of its own.
const tableWithoutFilters = computed(() => ({ ...props.table, filters: [] }));

const tableFilters = computed(() =>
    Object.values((props.table.filters ?? []) as TableFilter[]).map((filter) => ({
        ...filter,
        // Without the builder's own "no filter" entry.
        options: Object.entries(filter.options).filter(([value]) => value !== ''),
    })),
);

const period = computed(() =>
    periodLabel(usePage().props.locale, props.filters.date_from, props.filters.date_until),
);

const chips = computed<FilterChip[]>(() => [
    ...(period.value ? [{ key: 'period', label: period.value }] : []),
    ...tableFilters.value
        .filter((filter) => filter.value)
        .map((filter) => ({
            key: filter.key,
            label: `${filter.label}: ${filter.options.find(([value]) => value === filter.value)?.[1] ?? filter.value}`,
        })),
]);

// The table builder prefixes its query parameters with the name of the table.
const tableKey = (key: string) =>
    props.table.name && props.table.name !== 'default'
        ? `${props.table.name}_${key}`
        : key;

function visit(change: (query: URLSearchParams) => void) {
    const query = new URLSearchParams(window.location.search);

    query.delete(tableKey('page'));
    change(query);

    router.get(`${props.baseUrl}?${query.toString()}`, {}, {
        preserveState: true,
        preserveScroll: true,
    });
}

const setParam = (query: URLSearchParams, key: string, value: string) =>
    value ? query.set(key, value) : query.delete(key);

function applyPeriod(range: DateRange) {
    visit((query) => {
        setParam(query, 'date_from', range.start);
        setParam(query, 'date_until', range.end);
    });
}

function applyFilter(key: string, value: string) {
    visit((query) => setParam(query, tableKey(`filter[${key}]`), value));
}

function removeChip(key: string) {
    if (key === 'period') {
        applyPeriod({ start: '', end: '' });
    } else {
        applyFilter(key, '');
    }
}

function resetFilters() {
    visit((query) => {
        query.delete('date_from');
        query.delete('date_until');
        tableFilters.value.forEach((filter) => query.delete(tableKey(`filter[${filter.key}]`)));
    });
}

function duplicate(id: string) {
    router.post(`${props.baseUrl}/${id}/duplicate`, {}, { preserveScroll: true });
}

function destroy(id: string) {
    if (confirm(t('Are you sure you want to delete this?'))) {
        router.delete(`${props.baseUrl}/${id}`, { preserveScroll: true });
    }
}

const smallCheckOpen = ref(false);
const smallCheckForm = useForm({
    type_maintenance: Object.keys(props.smallCheck?.options ?? {})[0] ?? '',
    date: new Date().toISOString().slice(0, 10),
});

function submitSmallCheck() {
    smallCheckForm.post(props.smallCheck!.url, {
        preserveScroll: true,
        onSuccess: () => (smallCheckOpen.value = false),
    });
}
</script>

<template>
    <Head :title="title" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <AppIcon
                    v-if="icon"
                    :name="icon"
                    class="size-6 text-muted-foreground"
                />
                <h1 class="text-2xl font-semibold">{{ title }}</h1>
                <Button
                    v-if="description"
                    variant="ghost"
                    size="icon"
                    :aria-label="t('More information')"
                    :aria-expanded="showDescription"
                    @click="showDescription = !showDescription"
                >
                    <Info class="size-4" />
                </Button>
            </div>
            <div class="flex gap-2">
                <Button
                    v-if="smallCheck"
                    variant="outline"
                    @click="smallCheckOpen = true"
                >
                    {{ t('Add small check') }}
                </Button>
                <Button as-child>
                    <Link :href="createUrl" data-test="create-button">
                        <Plus class="size-4" />
                        {{ t('Create') }}
                    </Link>
                </Button>
            </div>
        </div>

        <p
            v-if="description && showDescription"
            class="max-w-3xl text-sm text-muted-foreground"
        >
            {{ description }}
        </p>

        <FilterChips :chips="chips" @remove="removeChip" @reset="resetFilters" />

        <TableBuilder :table="tableWithoutFilters">
            <template #toolbar>
                <FilterSheet :active="chips.length" @reset="resetFilters">
                    <PeriodFilter
                        :start="filters.date_from"
                        :end="filters.date_until"
                        @apply="applyPeriod"
                    />
                    <div
                        v-for="filter in tableFilters"
                        :key="filter.key"
                        class="grid gap-2"
                    >
                        <Label :for="`filter-${filter.key}`">{{ filter.label }}</Label>
                        <select
                            :id="`filter-${filter.key}`"
                            :value="filter.value ?? ''"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                            @change="applyFilter(filter.key, ($event.target as HTMLSelectElement).value)"
                        >
                            <option value="">{{ t('All') }}</option>
                            <option
                                v-for="[value, label] in filter.options"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </div>
                </FilterSheet>
            </template>

            <template #actions="{ row }">
                <div class="flex items-center justify-end">
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost" size="icon" aria-label="…">
                            <MoreHorizontal class="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem as-child>
                            <Link
                                :href="`${baseUrl}/${row.id}/edit`"
                                class="cursor-pointer"
                            >
                                <Pencil class="size-4" />
                                {{ t('Edit') }}
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            class="cursor-pointer"
                            @select="duplicate(row.id)"
                        >
                            <AppIcon name="gmdi-file-copy-r" class="size-4" />
                            {{ t('Duplicate') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            class="cursor-pointer text-destructive"
                            @select="destroy(row.id)"
                        >
                            <Trash2 class="size-4" />
                            {{ t('Delete') }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                    <Button
                        v-if="showable"
                        variant="ghost"
                        size="icon"
                        as-child
                    >
                        <Link
                            :href="`${baseUrl}/${row.id}`"
                            :aria-label="t('Show')"
                        >
                            <AppIcon
                                name="gmdi-keyboard-arrow-right-r"
                                class="size-5 text-primary"
                            />
                        </Link>
                    </Button>
                </div>
            </template>
        </TableBuilder>

        <dl
            v-if="summary.length"
            class="grid gap-3 rounded-xl border border-sidebar-border/70 p-4 text-sm sm:grid-cols-2 lg:grid-cols-4"
        >
            <div v-for="item in summary" :key="item.label">
                <dt class="text-muted-foreground">{{ item.label }}</dt>
                <dd class="font-medium">{{ item.value }}</dd>
            </div>
        </dl>

        <Dialog v-if="smallCheck" v-model:open="smallCheckOpen">
            <DialogContent>
                <form class="space-y-4" @submit.prevent="submitSmallCheck">
                    <DialogHeader>
                        <DialogTitle>{{ t('Add small check') }}</DialogTitle>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="small-check-type">{{ t('Type') }}</Label>
                        <select
                            id="small-check-type"
                            v-model="smallCheckForm.type_maintenance"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option
                                v-for="(label, value) in smallCheck.options"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="small-check-date">{{ t('Date') }}</Label>
                        <Input
                            id="small-check-date"
                            v-model="smallCheckForm.date"
                            type="date"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="smallCheckOpen = false"
                        >
                            {{ t('Cancel') }}
                        </Button>
                        <Button
                            type="submit"
                            :disabled="smallCheckForm.processing"
                        >
                            {{ t('Save') }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>