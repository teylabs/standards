<template>
    <Page title="Orders">
        <SectionHeader title="Recent" />
        <ButtonGroup><Button>Save</Button><Button>Cancel</Button></ButtonGroup>
        <PageGrid preset="thirds"><PageGridItem>a</PageGridItem></PageGrid>
        <Stack class="gap-4"><Card /><Card /></Stack>
        <DataTable :data="rows" />
    </Page>
</template>
