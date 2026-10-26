import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { refreshSubscription, registerServiceWorker } from '@/lib/push';

const appName = import.meta.env.VITE_APP_NAME || 'Vehicle manager';

void createInertiaApp({
    resolve: async (name) => {
        const pages = import.meta.glob('./pages/**/*.vue');
        const page = (await pages[`./pages/${name}.vue`]()) as any;
        page.default.layout ??= AppLayout;
        return page;
    },
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();

// The service worker shows the push notifications, also when the app is closed.
registerServiceWorker();
void refreshSubscription();
