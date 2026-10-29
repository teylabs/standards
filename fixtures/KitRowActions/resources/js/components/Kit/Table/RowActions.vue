<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import Button from '@/components/Kit/Form/Button.vue';

// The shape of tey/kit's Table/RowActions: an inline kit Button that opens a new tab through a bound target (the Button adds the rel), and menu items whose
// new-tab link carries rel but, like the kit's, no external icon.
type RowAction = { key: string | null; label: string; url: string; newTab: boolean };
defineProps<{ inline: RowAction[]; menu: RowAction[]; label: string }>();
</script>
<template>
    <div class="flex items-center justify-end gap-2" data-row-actions>
        <Button v-for="action in inline" :key="action.key ?? action.label" variant="secondary" :href="action.url" :external="action.newTab" :target="action.newTab ? '_blank' : undefined">{{ action.label }}</Button>
        <DropdownMenu v-if="menu.length">
            <DropdownMenuTrigger as-child><Button variant="secondary" type="button" :aria-label="label">…</Button></DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem v-for="action in menu" :key="action.key ?? action.label" as-child>
                    <a v-if="action.newTab" :href="action.url" target="_blank" rel="noopener noreferrer">{{ action.label }}</a>
                    <Link v-else :href="action.url">{{ action.label }}</Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
