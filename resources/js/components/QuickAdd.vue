<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useT } from '@/composables/useT';

const { t } = useT();

// Everything in the navigation that has records to add; the overview pages
// (dashboard, timeline, the maintenance plan) do not.
const groups = computed(() =>
    usePage()
        .props.navigation.map((group) => ({
            ...group,
            items: group.items.filter((item) => item.create !== null),
        }))
        .filter((group) => group.items.length),
);
</script>

<template>
    <DropdownMenu v-if="groups.length">
        <DropdownMenuTrigger as-child>
            <!-- On a phone a round button at the bottom, in reach of the thumb. -->
            <Button
                variant="outline"
                class="max-sm:fixed max-sm:right-4 max-sm:bottom-[calc(1rem+env(safe-area-inset-bottom))] max-sm:z-30 max-sm:size-14 max-sm:rounded-full max-sm:border-0 max-sm:bg-primary max-sm:text-primary-foreground max-sm:shadow-lg"
                data-test="quick-add"
            >
                <Plus class="size-4 max-sm:size-6" />
                <span class="max-sm:sr-only">{{ t('Create') }}</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            align="end"
            class="max-h-[70vh] w-72 overflow-y-auto"
        >
            <template v-for="(group, index) in groups" :key="group.label">
                <DropdownMenuSeparator v-if="index > 0" />
                <DropdownMenuLabel class="text-xs text-muted-foreground">
                    {{ group.label }}
                </DropdownMenuLabel>
                <DropdownMenuGroup>
                    <DropdownMenuItem
                        v-for="item in group.items"
                        :key="item.href"
                        as-child
                    >
                        <Link
                            :href="item.create!"
                            class="min-h-10 cursor-pointer"
                        >
                            <AppIcon
                                :name="item.icon"
                                class="size-4 text-muted-foreground"
                            />
                            {{ item.title }}
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuGroup>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
