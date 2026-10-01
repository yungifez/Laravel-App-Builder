import { createInertiaApp, router } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AppPageLayout from '@/layouts/AppPageLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import WorkspaceLayout from '@/layouts/WorkspaceLayout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { settleDates } from '@/lib/when';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name === 'projects/Show':
                return WorkspaceLayout;
            case name === 'projects/Understanding':
            case name === 'feature-requests/Show':
            case name.startsWith('operations/'):
                return AppPageLayout;
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
    defaults: {
        // Going to another page crossfades instead of cutting. Reloads,
        // partial loads and form posts keep the page as it is.
        visitOptions: (href, options) =>
            (options.method ?? 'get') === 'get' &&
            !options.preserveState &&
            !options.async &&
            (options.only ?? []).length === 0
                ? { viewTransition: true }
                : {},
    },
}).then(() => {
    // The page is in the browser and matches the server's drawing now.
    if (typeof window !== 'undefined') {
        settleDates();
    }
});

// After an update the page is out of date, and the server refuses its
// requests until it loads again. Inertia reloads for a visit but not for a
// background request (a part's details, a poll), which would then fail
// every time. So load the page again once, whichever request found it.
let reloading = false;

router.on('location', (event) => {
    if (event.detail.versionChange && !reloading) {
        reloading = true;
        window.location.reload();
    }
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
