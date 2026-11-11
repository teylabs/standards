<script setup lang="ts">
const order = { id: 7, number: 'NW-1007' };
const actions = [{ key: 'remove', label: `Remove ${order.number}`, url: `/orders/${order.id}`, method: 'delete', destructive: true }];
</script>
<template>
    <Page title="Orders">
        <SectionHeader title="Orders" />
        <div class="mt-6 flex"><Button>Save</Button><Button>Cancel</Button></div>
        <div class="grid grid-cols-3"><div>a</div></div>
        <div class="flex flex-col gap-4"><Card /><Card /></div>
        <Button :href="route('orders.create')">Order</Button>
        <DataTable :data="rows">
            <template #row-actions="{ row }"><Button @click="remove(row)">Remove</Button></template>
        </DataTable>
    </Page>
</template>
