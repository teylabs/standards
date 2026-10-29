<template>
    <form>
        <input type="file" name="cover">
        <input :type="kind" name="bound">
        <input v-bind:type="kind" name="long-bound">
        <input name="untyped">
        <input type="search" name="q">
        <input :disabled="count > 1" type='text'>
        <input type="hidden" name="_token">
    </form>
</template>
