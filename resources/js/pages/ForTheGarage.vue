<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Check, Copy, Pencil, Plus, Printer, Trash2, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import ColorBadge from '@/components/ColorBadge.vue';
import SectionCard from '@/components/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useT } from '@/composables/useT';

type Finding = {
    id: string;
    title: string;
    description: string | null;
    source: string;
    sourceLabel: string;
    sourceIcon: string;
    severity: 'urgent' | 'soon' | 'info';
    severityLabel: string;
    date: string;
    garage: string | null;
    estimatedCost: string | null;
    components: string[];
    forGarage: boolean;
    url: string;
    editUrl: string;
    // Puts it on the list, or takes it off.
    garageUrl: string;
    resolveUrl: string;
};

const props = defineProps<{
    vehicle: {
        name: string;
        licensePlate: string | null;
        mileage: string | null;
    };
    // On the list, the most urgent first.
    items: Finding[];
    // Open, but not on the list.
    others: Finding[];
    // Components of the maintenance plan that are due and not on the list.
    plan: {
        key: string;
        label: string;
        icon: string;
        state: string;
        due: string | null;
        addUrl: string;
    }[];
    // Lines of the owner's own, without being a finding.
    lines: { id: number; text: string; deleteUrl: string }[];
    linesUrl: string;
    createUrl: string;
    // Records the maintenance with what is on the list as its tasks.
    recordUrl: string;
}>();

const { t } = useT();

const severityColors: Record<Finding['severity'], string> = {
    urgent: 'danger',
    soon: 'warning',
    info: 'info',
};

const busy = ref<string | null>(null);

function post(url: string) {
    router.post(
        url,
        {},
        {
            preserveScroll: true,
            onStart: () => (busy.value = url),
            onFinish: () => (busy.value = null),
        },
    );
}

const line = useForm({ text: '' });

function addLine() {
    line.post(props.linesUrl, {
        preserveScroll: true,
        onSuccess: () => line.reset(),
    });
}

function removeLine(url: string) {
    router.delete(url, {
        preserveScroll: true,
        onStart: () => (busy.value = url),
        onFinish: () => (busy.value = null),
    });
}

const empty = computed(() => !props.items.length && !props.lines.length);

// The list as plain text, to send to the garage.
const text = computed(() =>
    [
        [props.vehicle.name, props.vehicle.licensePlate, props.vehicle.mileage]
            .filter(Boolean)
            .join(' · '),
        '',
        ...props.items.map((item) =>
            [
                `- ${item.title}`,
                item.description ? `  ${item.description}` : null,
            ]
                .filter(Boolean)
                .join('\n'),
        ),
        ...props.lines.map((line) => `- ${line.text}`),
    ].join('\n'),
);

const copied = ref(false);

async function copy() {
    try {
        await navigator.clipboard.writeText(text.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}

function print() {
    window.print();
}
</script>

<template>
    <Head :title="t('For the garage')" />

    <div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">
                    {{ t('For the garage') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        [vehicle.name, vehicle.licensePlate, vehicle.mileage]
                            .filter(Boolean)
                            .join(' · ')
                    }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2 print:hidden">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="empty"
                    @click="copy"
                >
                    <Check v-if="copied" class="size-4" />
                    <Copy v-else class="size-4" />
                    {{ copied ? t('Copied') : t('Copy as text') }}
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="empty"
                    @click="print"
                >
                    <Printer class="size-4" />
                    {{ t('Print') }}
                </Button>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="createUrl">
                        <Plus class="size-4" />
                        {{ t('Add a finding') }}
                    </Link>
                </Button>
                <Button v-if="!empty" size="sm" as-child>
                    <Link :href="recordUrl">
                        <AppIcon name="mdi:car-wrench" class="size-4" />
                        {{ t('Record the maintenance') }}
                    </Link>
                </Button>
            </div>
        </div>

        <SectionCard
            :title="t('On the list')"
            icon="mdi:garage-alert"
            :badge="String(items.length + lines.length)"
            flush
        >
            <ul v-if="!empty" class="divide-y">
                <li
                    v-for="item in items"
                    :key="item.id"
                    class="flex flex-wrap items-start gap-3 px-4 py-3"
                >
                    <AppIcon
                        :name="item.sourceIcon"
                        class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                    />
                    <div class="flex min-w-0 flex-1 basis-64 flex-col gap-1">
                        <Link
                            :href="item.url"
                            class="font-medium hover:underline"
                        >
                            {{ item.title }}
                        </Link>
                        <p
                            v-if="item.description"
                            class="text-sm whitespace-pre-line text-muted-foreground"
                        >
                            {{ item.description }}
                        </p>
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <ColorBadge :color="severityColors[item.severity]">
                                {{ item.severityLabel }}
                            </ColorBadge>
                            <ColorBadge>{{ item.sourceLabel }}</ColorBadge>
                            <span class="text-muted-foreground">{{
                                item.date
                            }}</span>
                            <span
                                v-if="item.estimatedCost"
                                class="text-muted-foreground"
                            >
                                · {{ item.estimatedCost }}
                            </span>
                            <span
                                v-if="item.components.length"
                                class="text-muted-foreground"
                            >
                                · {{ item.components.join(', ') }}
                            </span>
                        </div>
                    </div>
                    <div class="flex gap-1 print:hidden">
                        <Button
                            variant="ghost"
                            size="sm"
                            :title="t('Edit')"
                            as-child
                        >
                            <Link :href="item.editUrl">
                                <Pencil class="size-4" />
                            </Link>
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            :disabled="busy !== null"
                            @click="post(item.resolveUrl)"
                        >
                            <Check class="size-4" />
                            {{ t('Resolved') }}
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            :title="t('Take off the list for the garage')"
                            :disabled="busy !== null"
                            @click="post(item.garageUrl)"
                        >
                            <X class="size-4" />
                        </Button>
                    </div>
                </li>
                <li
                    v-for="item in lines"
                    :key="`line-${item.id}`"
                    class="flex items-start gap-3 px-4 py-3"
                >
                    <AppIcon
                        name="mdi:wrench"
                        class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                    />
                    <span class="min-w-0 flex-1 font-medium">
                        {{ item.text }}
                    </span>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="print:hidden"
                        :title="t('Remove')"
                        :disabled="busy !== null"
                        @click="removeLine(item.deleteUrl)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </li>
            </ul>
            <p v-else class="p-4 text-sm text-muted-foreground">
                {{
                    t(
                        'Nothing is on the list yet. Add what the garage should look at, or put open findings and components that are due on it below.',
                    )
                }}
            </p>
            <form
                class="flex gap-2 border-t p-4 print:hidden"
                @submit.prevent="addLine"
            >
                <div class="grid flex-1 gap-1">
                    <Input
                        v-model="line.text"
                        maxlength="255"
                        :placeholder="t('Something else to ask or have done')"
                        :aria-label="t('Something else to ask or have done')"
                    />
                    <span v-if="line.errors.text" class="text-xs text-danger">
                        {{ line.errors.text }}
                    </span>
                </div>
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="line.processing || !line.text.trim()"
                >
                    <Plus class="size-4" />
                    {{ t('Add') }}
                </Button>
            </form>
        </SectionCard>

        <SectionCard
            v-if="plan.length"
            :title="t('Due according to the maintenance plan')"
            icon="mdi:clipboard-list-outline"
            :badge="String(plan.length)"
            class="print:hidden"
            flush
        >
            <ul class="divide-y">
                <li
                    v-for="item in plan"
                    :key="item.key"
                    class="flex items-center gap-3 px-4 py-3"
                >
                    <AppIcon
                        :name="item.icon"
                        class="size-5 shrink-0 text-muted-foreground"
                    />
                    <div class="min-w-0 flex-1">
                        <span class="font-medium">{{ item.label }}</span>
                        <span
                            v-if="item.due"
                            class="block text-sm text-muted-foreground"
                        >
                            {{ item.due }}
                        </span>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="busy !== null"
                        @click="post(item.addUrl)"
                    >
                        <Plus class="size-4" />
                        {{ t('Put on the list') }}
                    </Button>
                </li>
            </ul>
        </SectionCard>

        <SectionCard
            v-if="others.length"
            :title="t('Other open findings')"
            icon="mdi:alert-circle-outline"
            :badge="String(others.length)"
            class="print:hidden"
            flush
        >
            <ul class="divide-y">
                <li
                    v-for="item in others"
                    :key="item.id"
                    class="flex items-center gap-3 px-4 py-3"
                >
                    <AppIcon
                        :name="item.sourceIcon"
                        class="size-5 shrink-0 text-muted-foreground"
                    />
                    <div class="min-w-0 flex-1">
                        <Link
                            :href="item.url"
                            class="font-medium hover:underline"
                        >
                            {{ item.title }}
                        </Link>
                        <span class="block text-sm text-muted-foreground">
                            {{ item.sourceLabel }} · {{ item.date }}
                        </span>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="busy !== null"
                        @click="post(item.garageUrl)"
                    >
                        <Plus class="size-4" />
                        {{ t('Put on the list') }}
                    </Button>
                </li>
            </ul>
        </SectionCard>
    </div>
</template>
