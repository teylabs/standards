import { createInertiaApp } from '@inertiajs/vue3';
import Layout from '@/pages/Workspace/Layout.vue';
import GuestShell from '@/pages/Workspace/Partials/GuestShell.vue';
import PublicShell from '@/pages/Workspace/Partials/PublicShell.vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';

createInertiaApp({
    layout: (name: string) =>
        name === 'Landing' ? MarketingLayout
        : name.startsWith('Public/') ? PublicShell
        : name.startsWith('Auth/') ? GuestShell
        : Layout,
});
