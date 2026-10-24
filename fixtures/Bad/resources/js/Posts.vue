<script setup lang="ts">
import { ref } from 'vue';
import '@scope/widgets/style.css';
import type { Options } from 'test-helper';
export { widget } from 'peer-helper/subpath';
const helper = import('node:fs');
import Local from '@/Local';
import Other from '~/Other';
// user.roles; hasRole('owner'); import 'unlisted-comment';
const example = "import('unlisted-string')";
</script>
<template>
    <!-- <button>Add ignored</button><input type="file"> -->
    <Button><Plus />Add post</Button>
    <a target='_blank' rel='noreferrer noopener' href='/posts'><ArrowUpRight />Posts</a>
    <a href='/posts'>Posts</a>
    <div>{{ post.title }}</div>
</template>
<script>
import 'unlisted-side-effect';
import { toast } from 'vue-sonner';
import type { Missing } from '@missing/types';
export { unknown } from 'unlisted-export/subpath';
const dynamic = import('unlisted-dynamic');
const permitted = user.roles.includes('owner') && hasRole('owner') && can('update');
const can = { update: true };
const allowed = props.can.update;
</script>
<template>
    <Button>Add post</Button>
    <a target="_blank" rel="noreferrer" href="/posts">Posts</a>
    <input type='file'>
    <p>{{ page.props.flash.success }}</p>
</template>
