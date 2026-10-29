<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ChevronRight,
    Info,
    MoreHorizontal,
    Pencil,
    Plus,
    Trash2,
} from '@lucide/vue';
import Form from '@form-builder/components/Form.vue';
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
    DialogDescription,
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
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
    // Whether duplicating asks for fields, such as the period of the copy.
    duplicateAsks?: boolean;
}>();

type TableFilter = {
    key: string;
    label: string;
    options: Record<string, string>;
    value: string | null;
};

const { t } = useT();

const showDescription = ref(false);

// A select cannot have an empty value, so "no filter" gets one of its own.
const ALL = '__all__';

// The columns of the table, to give each cell its label on a phone.
const columns = computed(
    () => (props.table.columns ?? []) as { key: string; label: string }[],
);

// The table builder would offer the filters in a menu of its own.
const tableWithoutFilters = computed(() => ({ ...props.table, filters: [] }));

const tableFilters = computed(() =>
    Object.values((props.table.filters ?? []) as TableFilter[]).map(
        (filter) => ({
            ...filter,
            // Without the builder's own "no filter" entry.
            options: Object.entries(filter.options).filter(
                ([value]) => value !== '',
            ),
        }),
    ),
);

const period = computed(() =>
    periodLabel(
        usePage().props.locale,
        props.filters.date_from,
        props.filters.date_until,
    ),
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

    router.get(
        `${props.baseUrl}?${query.toString()}`,
        {},
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
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
        tableFilters.value.forEach((filter) =>
            query.delete(tableKey(`filter[${filter.key}]`)),
        );
    });
}

// The record being duplicated, and the form of what is asked for its copy.
const duplicating = ref<string | null>(null);
const duplicateForm = ref<Record<string, unknown> | null>(null);
const duplicateFormRef = ref<InstanceType<typeof Form>>();
const duplicatePosting = ref(false);

async function duplicate(id: string) {
    duplicateForm.value = null;
    duplicating.value = id;

    if (props.duplicateAsks) {
        const response = await fetch(`${props.baseUrl}/${id}/duplicate`, {
            headers: { Accept: 'application/json' },
        });

        duplicateForm.value = await response.json();
    }
}

function confirmDuplicate() {
    if (duplicateForm.value) {
        duplicateFormRef.value?.submit();

        return;
    }

    router.post(
        `${props.baseUrl}/${duplicating.value}/duplicate`,
        {},
        {
            preserveScroll: true,
            onStart: () => (duplicatePosting.value = true),
            onFinish: () => (duplicatePosting.value = false),
            onSuccess: () => (duplicating.value = null),
        },
    );
}

function destroy(id: string) {
    if (confirm(t('Are you sure you want to delete this?'))) {
        router.delete(`${props.baseUrl}/${id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="title" />

    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-3">
                <span
                    v-if="icon"
                    class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-sidebar-accent text-sidebar-accent-foreground"
                >
                    <AppIcon :name="icon" class="size-5" />
                </span>
                <h1 class="text-2xl font-bold tracking-tight">{{ title }}</h1>
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

        <FilterChips
            :chips="chips"
            @remove="removeChip"
            @reset="resetFilters"
        />

        <dl v-if="summary.length" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div
                v-for="item in summary"
                :key="item.label"
                class="rounded-xl border bg-card px-4 py-3"
            >
                <dt class="text-sm text-muted-foreground">{{ item.label }}</dt>
                <dd class="text-xl font-bold tracking-tight tabular-nums">
                    {{ item.value }}
                </dd>
            </div>
        </dl>

        <div class="records rounded-xl border bg-card p-3">
            <TableBuilder :table="tableWithoutFilters">
                <!-- On a phone a row is a card, its cells labelled as the header is left out. -->
                <template
                    v-for="column in columns"
                    :key="column.key"
                    #[`cell-${column.key}`]="{ value }"
                >
                    <span
                        v-if="column.label"
                        class="mb-0.5 block text-sm text-muted-foreground sm:hidden"
                    >
                        {{ column.label }}
                    </span>
                    <span v-html="value" />
                </template>

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
                            <Label :for="`filter-${filter.key}`">{{
                                filter.label
                            }}</Label>
                            <Select
                                :model-value="filter.value || ALL"
                                @update:model-value="
                                    (value) =>
                                        applyFilter(
                                            filter.key,
                                            value === ALL ? '' : String(value),
                                        )
                                "
                            >
                                <SelectTrigger
                                    :id="`filter-${filter.key}`"
                                    class="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="ALL">{{
                                        t('All')
                                    }}</SelectItem>
                                    <SelectItem
                                        v-for="[value, label] in filter.options"
                                        :key="value"
                                        :value="value"
                                    >
                                        {{ label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </FilterSheet>
                </template>

                <template #actions="{ row }">
                    <div class="flex items-center justify-end">
                        <!-- Not modal: it opens the duplicate dialog, which takes over the focus. -->
                        <DropdownMenu :modal="false">
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="…"
                                >
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
                                    <AppIcon
                                        name="ic:round-file-copy"
                                        class="size-4"
                                    />
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
                                <ChevronRight
                                    class="size-5 text-muted-foreground"
                                />
                            </Link>
                        </Button>
                    </div>
                </template>
            </TableBuilder>
        </div>

        <Dialog
            :open="duplicating !== null"
            @update:open="(open) => !open && (duplicating = null)"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader class="items-center text-center sm:text-center">
                    <span
                        class="flex size-12 items-center justify-center rounded-full bg-sidebar-accent text-sidebar-accent-foreground"
                    >
                        <AppIcon name="ic:round-file-copy" class="size-6" />
                    </span>
                    <DialogTitle>{{ t('Duplicate') }}</DialogTitle>
                    <DialogDescription>
                        {{
                            duplicateAsks
                                ? t('A copy is made with the details below.')
                                : t(
                                      'A copy is made, with the date set to today.',
                                  )
                        }}
                    </DialogDescription>
                </DialogHeader>
                <template v-if="duplicateAsks">
                    <Form
                        v-if="duplicateForm"
                        ref="duplicateFormRef"
                        :schema="duplicateForm"
                        :options="{ preserveScroll: true, preserveState: true }"
                        @success="duplicating = null"
                    />
                    <Skeleton v-else class="h-32 w-full" />
                </template>
                <DialogFooter>
                    <Button variant="outline" @click="duplicating = null">
                        {{ t('Cancel') }}
                    </Button>
                    <Button
                        :disabled="
                            duplicatePosting ||
                            (duplicateAsks && !duplicateForm) ||
                            duplicateFormRef?.form.processing
                        "
                        @click="confirmDuplicate"
                    >
                        {{ t('Duplicate') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>

<style scoped>
/* On a phone a row of the table becomes a card: two columns of labelled cells,
   with the actions in its top right corner, so nothing scrolls sideways. */
@media (width < 40rem) {
    .records :deep(thead) {
        display: none;
    }

    .records :deep(table),
    .records :deep(tbody) {
        display: block;
    }

    .records :deep(tbody tr) {
        position: relative;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem 1rem;
        padding: 0.75rem 3rem 0.75rem 0.25rem;
    }

    .records :deep(tbody td) {
        padding: 0;
        white-space: normal;
    }

    /* The "nothing found" row. */
    .records :deep(tbody td[colspan]) {
        grid-column: 1 / -1;
    }

    .records :deep(tbody td:last-child:not([colspan])) {
        position: absolute;
        top: 0.25rem;
        right: 0;
    }

    /* Edit menu and "show" above each other, to leave the cells their room. */
    .records :deep(tbody td:last-child:not([colspan]) > div) {
        flex-direction: column;
    }

    /* The pagination below it: the count above the buttons, which take the
       full width, rather than everything squeezed on one line. */
    .records :deep(.justify-between.mt-3) {
        flex-direction: column;
        align-items: stretch;
    }

    .records :deep(.justify-between.mt-3 > div) {
        justify-content: space-between;
    }
}
</style>
