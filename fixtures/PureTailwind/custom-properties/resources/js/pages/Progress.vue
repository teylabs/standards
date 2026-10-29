<script setup lang="ts">
defineProps<{ progress: number; hue: number }>();
</script>

<template>
    <div class="h-2 w-(--progress) bg-[hsl(var(--hue)_80%_50%)]" :style="{ '--progress': `${progress}%`, '--hue': hue }" />
    <div class="h-2" v-bind:style="{ '--progress': progress + '%' }" />
</template>
