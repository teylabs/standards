<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';

const page = usePage();
const auth = page.props.auth;
</script>
<template>
    <header class="border-b">
        <Link href="/dashboard">Northwind Farms</Link>
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <button type="button">{{ auth.user.name }}</button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <UserMenuContent :user="auth.user" />
            </DropdownMenuContent>
        </DropdownMenu>
    </header>
</template>
