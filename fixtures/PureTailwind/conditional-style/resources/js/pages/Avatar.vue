<script setup lang="ts">
defineProps<{ color?: string; size?: string; muted?: boolean; style?: object }>();
</script>

<template>
    <span :style="color ? { '--sf-avatar-color': color } : undefined" />
    <span :style="color ? { '--sf-avatar-color': color } : null" />
    <span :style="!color ? {} : { '--sf-avatar-color': color, '--sf-avatar-size': size }" />
    <span :style="color ? { '--sf-avatar-color': color } : size ? { '--sf-avatar-size': size } : undefined" />
    <span :style="muted ? (size ? { '--sf-avatar-size': size } : {}) : { '--sf-avatar-color': color }" />
    <span :style="color?.length ? { '--sf-avatar-color': color } : undefined" />
    <span :style="color ? { color: color } : undefined" />
    <span :style="color ? { '--sf-avatar-color': color } : size ? { width: size } : undefined" />
    <span :style="color ? style : undefined" />
    <span :style="color ? makeStyle(color) : undefined" />
    <span :style="color ? undefined : { 'background-color': color }" />
</template>
