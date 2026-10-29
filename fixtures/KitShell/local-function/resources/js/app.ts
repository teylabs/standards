import { createInertiaApp } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/auth/Layout.vue';
import Portal from '@/layouts/Portal.vue';

function pickLayout(name: string) {
    if (name.startsWith('Auth/')) {
        return AuthLayout;
    }

    return Portal;
}

createInertiaApp({
    layout: pickLayout,
});
