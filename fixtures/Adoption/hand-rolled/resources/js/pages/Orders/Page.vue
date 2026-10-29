<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Page, DataTable } from '@/components/Kit';

const form = useForm({ q: '' });
</script>

<template>
    <Page title="Orders">
        <DataTable :rows="[]" />
        <table>
            <tr><td>Order</td></tr>
        </table>
    </Page>
</template>
