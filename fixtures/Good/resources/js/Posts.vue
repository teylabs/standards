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
