<script setup>
import axios from 'axios';
import { nanoid } from 'nanoid';

const key = nanoid();
axios.get('/projects');
</script>

<template>
    <div :data-key="key"></div>
</template>
