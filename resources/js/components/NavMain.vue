<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavGroup } from '@/types';

const props = defineProps<{
    groups: NavGroup[];
}>();

const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
// The item of the page already shown keeps the URL, so it closes here too.
const { setOpenMobile } = useSidebar();

// The item of the current page: of the items whose URL is the current one or a
// parent of it, the most specific. So /trips/create is "Plan a trip" only, and
// not "My trips" as well. The dashboard lives at "/", a parent of every URL.
const activeHref = computed(
    () =>
        props.groups
            .flatMap((group) => group.items.map((item) => item.href))
            .filter((href) =>
                href === '/' ? isCurrentUrl(href) : isCurrentOrParentUrl(href),
            )
            .sort((a, b) => b.length - a.length)[0],
);

const isActive = (href: string) => href === activeHref.value;
</script>

<template>
    <SidebarGroup v-for="group in groups" :key="group.label" class="px-2 py-0">
        <SidebarGroupLabel>{{ group.label }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in group.items" :key="item.href">
                <SidebarMenuButton
                    as-child
                    :is-active="isActive(item.href)"
                    :tooltip="item.title"
                >
                    <Link :href="item.href" @click="setOpenMobile(false)">
                        <AppIcon :name="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
