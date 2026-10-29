<script setup lang="ts">
const props = defineProps<{ progress: number }>();
const styles = { width: `${props.progress}%` };
</script>

<template>
    <div class="h-2" :style="{ '--progress': progress, width: progress + '%' }" />
    <div class="h-2" :style="styles" />
</template>
