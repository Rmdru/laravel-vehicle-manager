<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Languages, LogOut, Settings } from '@lucide/vue';
import AppIcon from '@/components/AppIcon.vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { logout } from '@/routes';
import { useT } from '@/composables/useT';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

const handleLogout = () => {
    router.flushAll();
};

defineProps<Props>();

const { t } = useT();

// The country whose flag stands for a language.
const flagCodes: Record<string, string> = { en: 'gb' };

const flag = (locale: string) => `circle-flags:${flagCodes[locale] ?? locale}`;

const setLocale = (locale: string) => {
    router.post('/locale', { locale }, { preserveScroll: true });
};
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                {{ t('Settings') }}
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuLabel
        class="flex items-center gap-2 text-xs text-muted-foreground"
    >
        <Languages class="size-4" />
        {{ t('Language') }}
    </DropdownMenuLabel>
    <DropdownMenuRadioGroup
        :model-value="$page.props.locale"
        @update:model-value="(locale) => setLocale(String(locale))"
    >
        <DropdownMenuRadioItem
            v-for="(label, locale) in $page.props.locales"
            :key="locale"
            :value="locale"
        >
            <AppIcon
                :name="flag(String(locale))"
                class="size-5 shrink-0 rounded-full"
            />
            {{ label }}
        </DropdownMenuRadioItem>
    </DropdownMenuRadioGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            {{ t('Log out') }}
        </Link>
    </DropdownMenuItem>
</template>
