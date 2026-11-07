<script setup lang="ts">
const order = { id: 7, number: 'NW-1007' };
const actions = [{ key: 'remove', label: 'Remove', url: `/orders/${order.id}`, method: 'delete', destructive: true }];
</script>
<template>
    <Page title="Orders">
        <SectionHeader title="Recent" />
        <ButtonGroup><Button>Save</Button><Button>Cancel</Button></ButtonGroup>
        <PageGrid preset="thirds"><PageGridItem>a</PageGridItem></PageGrid>
        <Stack class="gap-4"><Card /><Card /></Stack>
        <DataTable :data="rows" />
    </Page>
</template>
