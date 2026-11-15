<script setup lang="ts">
import { Form, Head, Link, http, router, usePage } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import { nextTick, onMounted, ref } from 'vue';
import { ActionGroup, Alert, Button, Field, FormStatus, Input, InputCheckbox, Page, PasswordInput, Stack, passkeySignInSupported, signInWithPasskey } from '@kit/ui';

// A forgotten password is reset by emailed link (Auth/ForgotPassword). `status`, `localLogin` and `register` are the view model's: an app that offers a one-click local sign-in passes the offer, and one that opens registration (Fortify's `registration` feature) the link to Auth/Register (null elsewhere).
// A guest card is not a settings form: no sticky FormActions bar; its actions stack full-width (ActionGroup's column), Sign in first, everything aligned to the card's left edge.
const props = defineProps<Tey.Kit.Features.SessionUi.ViewModels.LoginViewModel>();

// A failed sign-in clears the password and returns focus to the first invalid field. A 429 (Fortify's login limiter) stays on the page: Inertia's error modal is
// refused (the visit's `options.onHttpException` returns false; `<Form>` before Inertia 3.8 has no such prop, so it is never an `@http-exception` listener), stale field errors go, and an in-card alert says when to try again (the kit's `ui` lang file, `form.*`). The server decides; this only presents it.
const form = ref<{ clearErrors: () => void; reset: (...fields: string[]) => void } | null>(null);
const throttled = ref<string | null>(null);
const page = usePage();
// The password fields are the page's (v-model): Inertia's reset-on-error resets the DOM only, so a failed or refused submit clears them here too.
const password = ref('');
function clearPasswords() {
    password.value = '';
}
function refocus(errors: Record<string, string>) {
    clearPasswords();
    const first = ['email', 'password'].find((name) => errors[name]);
    if (first) nextTick(() => document.getElementById(first)?.focus());
}
function tooMany(response: { status: number; headers?: Record<string, string> }) {
    if (response.status !== 429) return;
    const copy = (page.props as { kitUi?: { form?: { throttled?: string; throttled_retry?: string } } }).kitUi?.form;
    const seconds = Number.parseInt(response.headers?.['retry-after'] ?? '', 10);
    throttled.value = seconds > 0 && copy?.throttled_retry ? copy.throttled_retry.replace(':seconds', String(seconds)) : (copy?.throttled ?? 'Too many requests. Try again in a moment.');
    form.value?.clearErrors();
    form.value?.reset('password');
    clearPasswords();
    return false;
}
// <Form> spreads `options` into the visit, but its type (FormComponentOptions) leaves out onHttpException, and an inline literal fails vue-tsc's excess-property
// check: so the handler is typed as the visit option, then widened. Both types are read off @inertiajs/vue3's own <Form> and router (no stub imports @inertiajs/core).
type FormOptions = NonNullable<InstanceType<typeof Form>['$props']['options']>;
type VisitOptions = NonNullable<Parameters<typeof router.visit>[1]>;
const failureHandlers: Pick<VisitOptions, 'onHttpException'> = { onHttpException: tooMany };
const formOptions = failureHandlers as FormOptions;

// Sign in with a passkey (Fortify's passkeys feature, `passkey` in the view model), offered only where the browser can. Remember me applies to it too.
// A cancelled prompt or a refused passkey (an unknown one, or an account that is not active) says so on the card; the password form stays as it was.
const passkeyReady = ref(false);
onMounted(() => { passkeyReady.value = props.passkey !== null && passkeySignInSupported(); });
const passkeyBusy = ref(false);
const passkeyFailed = ref(false);
async function passkeySignIn(): Promise<void> {
    if (!props.passkey || passkeyBusy.value) return;
    passkeyBusy.value = true;
    passkeyFailed.value = false;
    try {
        const remember = (document.getElementById('remember') as HTMLInputElement | null)?.checked ?? false;
        window.location.assign(await signInWithPasskey(http.getClient() as never, props.passkey, remember));
    } catch {
        passkeyFailed.value = true;
        passkeyBusy.value = false;
    }
}
</script>
<template>
    <Head :title="title" />
    <Page xs :heading="false">
        <!-- A compact auth card: the heading and its flashed status sit closer (gap-4) than the page's blocks (docs/page-rhythm.md). -->
        <Stack>
            <div class="space-y-2">
                <h1 class="text-xl font-medium">Sign in to your account</h1>
                <p class="text-sm text-muted-foreground">Enter your email and password below to sign in</p>
            </div>
            <FormStatus class="self-stretch" data-login-status />
        </Stack>
        <Form v-if="localLogin" v-slot="{ errors, processing }" :action="localLogin.url" method="post" class="grid gap-2" data-local-login>
            <Button type="submit" variant="secondary" class="w-full" :disabled="processing">Sign in as {{ localLogin.email }}</Button>
            <p v-if="errors.local_login" role="alert" class="text-sm text-destructive-text">{{ errors.local_login }}</p>
            <p class="text-xs text-muted-foreground">One-click sign-in, offered only locally</p>
        </Form>
        <Form ref="form" novalidate v-slot="{ errors, processing }" action="/login" method="post" :reset-on-success="['password']" :reset-on-error="['password']" class="grid gap-4" @start="throttled = null" @error="refocus" :options="formOptions">
            <Alert v-if="throttled" variant="danger" live="alert" data-auth-throttled>{{ throttled }}</Alert>
            <Field id="email" name="email" label="Email address">
                <Input id="email" name="email" type="email" required autofocus autocomplete="email" placeholder="email@example.com" :invalid="Boolean(errors.email)" />
            </Field>
            <Field id="password" name="password" label="Password">
                <PasswordInput id="password" v-model="password" name="password" required autocomplete="current-password" placeholder="Password" :invalid="Boolean(errors.password)" />
            </Field>
            <div class="flex items-center justify-between gap-3">
                <InputCheckbox id="remember" name="remember" value="1">Remember me</InputCheckbox>
                <Link href="/forgot-password" class="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline" data-forgot-password>Forgot your password?</Link>
            </div>
            <ActionGroup direction="column">
                <Button type="submit" :disabled="processing" data-test="login-button">{{ processing ? 'Signing in…' : 'Sign in' }}</Button>
                <Button v-if="passkeyReady" type="button" variant="secondary" :disabled="passkeyBusy" data-passkey-sign-in @click="passkeySignIn"><KeyRound class="size-4" aria-hidden="true" />{{ passkeyBusy ? 'Waiting for your passkey…' : 'Sign in with a passkey' }}</Button>
            </ActionGroup>
            <Alert v-if="passkeyReady && passkeyFailed" variant="danger" live="alert" data-passkey-failed>That passkey didn't sign you in. Try again, or use your password.</Alert>
            <p v-if="register" class="text-sm text-muted-foreground">New here? <Link :href="register" class="underline underline-offset-4 hover:text-foreground" data-register-link>Create an account</Link></p>
        </Form>
    </Page>
</template>
