<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

const form = useForm({ name: '' });
</script>

<template>
    <form @submit.prevent="form.patch('/profile')">Profile</form>
</template>
