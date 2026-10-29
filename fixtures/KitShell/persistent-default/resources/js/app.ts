import { createInertiaApp } from '@inertiajs/vue3';
import AppShell from './layouts/AppShell.vue';

createInertiaApp({
    resolve: async (name) => {
        const page = await import(`./pages/${name}.vue`);
        page.default.layout ??= AppShell;
        return page;
    },
});
