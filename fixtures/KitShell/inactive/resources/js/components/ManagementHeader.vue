<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import UserMenu from '@/components/UserMenu.vue';
</script>
<template>
    <header class="border-b">
        <nav class="flex gap-4">
            <Link href="/dashboard">Dashboard</Link>
            <Link href="/clients">Clients</Link>
        </nav>
        <UserMenu />
        <Link href="/logout" method="post" as="button">Log out</Link>
    </header>
</template>
