<script setup>
import { useToast } from 'vue-toastification';

const toast = useToast();
</script>

<template>
    <main>
        <h1>Orders</h1>
        <button type="button" @click="toast.success('Saved')">Save</button>
    </main>
</template>
