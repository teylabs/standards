<script setup lang="ts">
import { Page, DataTable, Toaster } from '@/components/Kit';
import CustomerPicker from '../../components/CustomerPicker.vue';
</script>

<template>
    <Page title="Invoices">
        <Toaster />
        <CustomerPicker />
        <DataTable :rows="[]" />
        <table>
            <tr><td>Invoice</td></tr>
        </table>
    </Page>
</template>
