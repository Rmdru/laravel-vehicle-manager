<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    useSidebar,
} from '@/components/ui/sidebar';
import VehicleSwitcher from '@/components/VehicleSwitcher.vue';

const page = usePage();
const { setOpenMobile } = useSidebar();

// On a phone the sidebar covers the page: once a link in it is followed, it
// gets out of the way. Partial reloads keep the URL, so they leave it open.
watch(
    () => page.url,
    () => setOpenMobile(false),
);

// Without a vehicle there is nothing to navigate to yet.
const groups = computed(() =>
    page.props.currentVehicle ? page.props.navigation : [],
);
</script>

<template>
    <Sidebar collapsible="icon">
        <SidebarHeader>
            <VehicleSwitcher />
        </SidebarHeader>

        <SidebarContent>
            <NavMain :groups="groups" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
