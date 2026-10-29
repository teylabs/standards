<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Page, Field } from '@/components/Kit';
import SearchBox from '@/components/Kit/SearchBox.vue';
import { DataTable } from '@kit/ui';
import { Toaster } from '@/components/Kit/Feedback';

const form = useForm({ name: '' });
</script>

<template>
    <Page title="Dashboard">
        <SearchBox />
        <Field v-model="form.name" label="Name" />
        <DataTable :rows="[]" />
        <Toaster />
    </Page>
</template>
