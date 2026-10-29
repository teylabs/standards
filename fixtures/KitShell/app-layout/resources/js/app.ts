import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, h, type DefineComponent } from 'vue';
import GuestLayout from '@/layouts/GuestLayout.vue';
import ManagementLayout from '@/layouts/ManagementLayout.vue';

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob<DefineComponent>('./pages/**/Page.vue', { eager: true });
        return pages[`./pages/${name}.vue`];
    },
    // Sign-in pages get the guest layout; ManagementLayout in a comment is not read.
    layout: (name: string) => (name.startsWith('Auth/') ? GuestLayout : ManagementLayout),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
});
