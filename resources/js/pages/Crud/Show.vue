<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Pencil } from '@lucide/vue';
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import BrandLogo from '@/components/BrandLogo.vue';
import LicensePlate from '@/components/LicensePlate.vue';
import { Button } from '@/components/ui/button';
import { useT } from '@/composables/useT';
import type { DetailSection, LicensePlate as LicensePlateType } from '@/types';

const props = defineProps<{
    title: string;
    indexUrl: string;
    indexTitle: string;
    editUrl: string;
    sections: DetailSection[];
    logo?: string | null;
    image?: string | null;
    licensePlate?: LicensePlateType;
}>();

const { t } = useT();

// Leave out what has no value, and sections that end up empty.
const visibleSections = computed(() =>
    props.sections
        .map((section) => ({
            ...section,
            items: section.items.filter(
                (item) =>
                    (item.value !== null &&
                        item.value !== undefined &&
                        item.value !== '') ||
                    !!item.html,
            ),
        }))
        .filter((section) => section.items.length > 0),
);
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
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold tracking-tight">
                        {{ title }}
                    </h1>
                    <LicensePlate v-if="licensePlate" :plate="licensePlate" />
                </div>
            </div>
            <Button as-child>
                <Link :href="editUrl">
                    <Pencil class="size-4" />
                    {{ t('Edit') }}
                </Link>
            </Button>
        </div>

        <img
            v-if="image"
            :src="image"
            alt=""
            class="max-h-72 w-fit rounded-xl"
        />
        <span
            v-if="logo"
            class="flex h-20 w-28 items-center justify-center rounded-lg border bg-white p-2"
        >
            <img :src="logo" alt="" class="max-h-full max-w-full" />
        </span>

        <section
            v-for="section in visibleSections"
            :key="section.title"
            class="overflow-hidden rounded-xl border bg-card"
        >
            <h2
                class="flex items-center gap-2 border-b px-4 py-3 font-semibold"
            >
                <AppIcon
                    v-if="section.icon"
                    :name="section.icon"
                    class="size-5 text-muted-foreground"
                />
                {{ section.title }}
            </h2>
            <dl class="grid gap-4 p-4 sm:grid-cols-2">
                <div v-for="item in section.items" :key="item.label">
                    <dt class="text-sm text-muted-foreground">
                        {{ item.label }}
                    </dt>
                    <!-- eslint-disable-next-line vue/no-v-html -->
                    <dd v-if="item.html" v-html="item.html" />
                    <dd v-else class="flex items-center gap-1.5">
                        <BrandLogo
                            v-if="item.logo !== undefined"
                            :name="item.logo"
                            class="size-7 shrink-0"
                        />
                        <AppIcon
                            v-else-if="item.icon"
                            :name="item.icon"
                            class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                        />
                        <span class="whitespace-pre-line">{{
                            item.value
                        }}</span>
                    </dd>
                </div>
            </dl>
        </section>
    </div>
</template>
