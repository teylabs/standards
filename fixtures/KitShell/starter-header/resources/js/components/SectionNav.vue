<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { NavigationMenu, NavigationMenuItem, NavigationMenuList } from '@/components/ui/navigation-menu';
import MenuButton from '@/components/MenuButton.vue';
</script>
<template>
    <nav class="flex gap-4">
        <MenuButton />
        <NavigationMenu>
            <NavigationMenuList>
                <NavigationMenuItem><Link href="/dashboard">Dashboard</Link></NavigationMenuItem>
                <NavigationMenuItem><Link href="/reports">Reports</Link></NavigationMenuItem>
            </NavigationMenuList>
        </NavigationMenu>
    </nav>
</template>
