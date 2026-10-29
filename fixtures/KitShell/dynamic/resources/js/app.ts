import { createInertiaApp } from '@inertiajs/vue3';
import { layoutFor } from './layouts';

const layouts = import.meta.glob('./layouts/*.vue', { eager: true, import: 'default' });

createInertiaApp({
    layout: layoutFor,
    resolve: (name) => {
        const page = import.meta.glob('./pages/**/Page.vue', { eager: true })[`./pages/${name}.vue`];
        page.default.layout = layouts[`./layouts/${page.default.shell}.vue`];
        return page;
    },
});
