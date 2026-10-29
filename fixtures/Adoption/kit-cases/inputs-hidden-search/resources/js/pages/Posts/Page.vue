<template>
    <form method="post" action="/posts/1">
        <input type="hidden" name="_method" value="delete">
        <input type="hidden" name="_token" :value="csrf">
    </form>
    <input type="search" placeholder="Search posts">
</template>
