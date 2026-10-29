<script setup lang="ts">
import WorkspaceLayout from '@/pages/Workspace/Layout.vue';
</script>
<template>
    <WorkspaceLayout :shell="$page.props.shell">
        <template #nav="{ navigation }">
            <nav aria-label="Modules"><a v-for="item in navigation" :key="item.url" :href="item.url">{{ item.label }}</a></nav>
        </template>
        <template #user-menu><AccountMenu /></template>
        <slot />
    </WorkspaceLayout>
</template>
