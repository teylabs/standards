<template>
    <header>
        <nav><a href="/pricing">Pricing</a></nav>
        <a v-if="$page.props.auth.user" href="/logout">Log out</a>
    </header>
    <slot />
</template>
