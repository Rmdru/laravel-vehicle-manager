<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, Plus } from '@lucide/vue';
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import BrandLogo from '@/components/BrandLogo.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useT } from '@/composables/useT';

const page = usePage();
const { t } = useT();

const current = computed(() => page.props.currentVehicle);
const vehicles = computed(() => page.props.vehicles);
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton size="lg" data-test="vehicle-switcher">
                        <div
                            class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
                        >
                            <BrandLogo
                                :name="current?.brandLogo"
                                class="size-5 text-white dark:text-black"
                            />
                        </div>
                        <div class="grid flex-1 text-left text-sm leading-tight">
                            <span class="truncate font-semibold">{{
                                current?.name ?? page.props.name
                            }}</span>
                            <span
                                v-if="current"
                                class="truncate text-xs text-muted-foreground"
                                >{{ current.licensePlate.label }}</span
                            >
                        </div>
                        <ChevronsUpDown class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent class="w-64" align="start">
                    <DropdownMenuLabel>{{ t('Vehicles') }}</DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="vehicle in vehicles"
                        :key="vehicle.id"
                        class="cursor-pointer"
                        @select="router.post(vehicle.selectUrl)"
                    >
                        <span class="flex-1 truncate">{{ vehicle.name }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            vehicle.licensePlate
                        }}</span>
                        <Check
                            v-if="vehicle.id === current?.id"
                            class="size-4"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem as-child>
                        <Link href="/vehicles" class="cursor-pointer">
                            <AppIcon name="mdi-garage" class="size-4" />
                            {{ t('My garage') }}
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem as-child>
                        <Link href="/vehicles/create" class="cursor-pointer">
                            <Plus class="size-4" />
                            {{ t('Add a vehicle') }}
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>