import { createInertiaApp } from '@inertiajs/vue3';
import { Layout as KitLayout } from '@kit/shell';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/pages/Workspace/Settings/Layout.vue';

createInertiaApp({
    layout: (name: string) => (name.startsWith('Workspace/Settings/') ? [KitLayout, SettingsLayout] : AppLayout),
});
