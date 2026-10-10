<script setup lang="ts">
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import { Eye, Pencil, Plus, Trash2 } from '@lucide/vue';
import AppIcon from '@/components/AppIcon.vue';
import BrandLogo from '@/components/BrandLogo.vue';
import ColorBadge from '@/components/ColorBadge.vue';
import LicensePlate from '@/components/LicensePlate.vue';
import { Button } from '@/components/ui/button';
import { useT } from '@/composables/useT';
import type { VehicleSummary } from '@/types';

type VehicleCard = VehicleSummary & {
    mileageStart: number | null;
    age: number | null;
    constructionDate: string | null;
    status: string;
    statusIcon: string | null;
    showUrl: string;
    editUrl: string;
    selectUrl: string;
    destroyUrl: string;
};

defineProps<{
    vehicles: VehicleCard[];
    createUrl: string;
    badges?: Record<
        string,
        { label: string; color: string; icon: string | null } | null
    >;
}>();

const { t } = useT();

function destroy(vehicle: VehicleCard) {
    if (confirm(t('Are you sure you want to delete this?'))) {
        router.delete(vehicle.destroyUrl, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="t('My garage')" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-2xl font-semibold">{{ t('My garage') }}</h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'This is an overview of the vehicles that are in your garage.',
                        )
                    }}
                </p>
            </div>
            <Button as-child>
                <Link :href="createUrl">
                    <Plus class="size-4" />
                    {{ t('Add a vehicle') }}
                </Link>
            </Button>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="vehicle in vehicles"
                :key="vehicle.id"
                class="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70"
                :class="{
                    'ring-2 ring-ring': vehicle.id === $page.props.currentVehicle?.id,
                }"
            >
                <img
                    v-if="vehicle.imageUrl"
                    :src="vehicle.imageUrl"
                    alt=""
                    class="aspect-video w-full object-cover"
                />
                <div class="flex flex-1 flex-col gap-3 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="flex items-center gap-2 font-semibold">
                            <BrandLogo
                                :name="vehicle.brandLogo"
                                class="size-5 shrink-0"
                            />
                            {{ vehicle.name }}
                        </h2>
                        <LicensePlate :plate="vehicle.licensePlate" />
                    </div>
                    <div
                        class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground"
                    >
                        <span
                            v-if="vehicle.mileage !== null"
                            class="flex items-center gap-1"
                            :title="`${t('Purchased with:')} ${vehicle.mileageStart ?? '?'} km`"
                        >
                            <AppIcon name="gmdi-route" class="size-4" />
                            {{ vehicle.mileage }} km
                        </span>
                        <span
                            v-if="vehicle.age !== null"
                            class="flex items-center gap-1"
                            :title="vehicle.constructionDate ?? ''"
                        >
                            <AppIcon name="fas-birthday-cake" class="size-4" />
                            {{ vehicle.age }} {{ t('years old') }}
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <ColorBadge>
                            <AppIcon
                                v-if="vehicle.statusIcon"
                                :name="vehicle.statusIcon"
                                class="size-3.5"
                            />
                            {{ vehicle.status }}
                        </ColorBadge>
                        <Deferred data="badges">
                            <template #fallback>
                                <span
                                    class="h-5 w-24 animate-pulse rounded-md bg-muted"
                                />
                            </template>
                            <ColorBadge
                                v-if="badges?.[vehicle.id]"
                                :color="badges[vehicle.id]!.color"
                            >
                                <AppIcon
                                    v-if="badges[vehicle.id]!.icon"
                                    :name="badges[vehicle.id]!.icon!"
                                    class="size-3.5"
                                />
                                {{ badges[vehicle.id]!.label }}
                            </ColorBadge>
                        </Deferred>
                    </div>
                    <div class="mt-auto flex flex-wrap gap-2 pt-2">
                        <Button
                            size="sm"
                            :variant="
                                vehicle.id === $page.props.currentVehicle?.id
                                    ? 'secondary'
                                    : 'default'
                            "
                            @click="router.post(vehicle.selectUrl)"
                        >
                            {{ t('Select') }}
                        </Button>
                        <Button size="sm" variant="outline" as-child>
                            <Link :href="vehicle.showUrl">
                                <Eye class="size-4" />
                                {{ t('Show') }}
                            </Link>
                        </Button>
                        <Button size="sm" variant="outline" as-child>
                            <Link :href="vehicle.editUrl">
                                <Pencil class="size-4" />
                                {{ t('Edit') }}
                            </Link>
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            class="ml-auto text-destructive"
                            :aria-label="t('Delete')"
                            @click="destroy(vehicle)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>
            </article>
        </div>
    </div>
</template>