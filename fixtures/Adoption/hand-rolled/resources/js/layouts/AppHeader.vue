<script setup lang="ts">
import UserMenu from '@/components/UserMenu.vue';
</script>

<template>
    <header class="border-b">
        <a :href="route('logout')">Log out</a>
        <UserMenu />
    </header>
</template>
