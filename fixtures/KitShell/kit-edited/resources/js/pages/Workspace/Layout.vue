<script setup lang="ts">
import { UserMenu, WorkspaceSwitcher } from '@kit/shell';
</script>
<template>
    <div class="flex min-h-svh flex-col">
        <header class="border-b">
            <WorkspaceSwitcher />
            <UserMenu />
            <a href="/logout">Log out</a>
        </header>
        <main id="main"><slot /></main>
    </div>
</template>
