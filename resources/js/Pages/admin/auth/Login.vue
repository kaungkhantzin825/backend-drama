<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    appName: string;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submitting = ref(false);

const errors = computed(() => form.errors);

// Focus the email field on mount so the form is immediately usable.
const emailInput = ref<HTMLInputElement | null>(null);

const submit = () => {
    submitting.value = true;

    form.post('/admin/login', {
        onFinish: () => {
            submitting.value = false;
        },
    });
};
</script>

<template>
    <Head :title="`Sign in — ${props.appName}`" />

    <div
        class="relative flex min-h-screen items-center justify-center overflow-hidden
               bg-surface px-4 py-10"
    >
        <!-- Ambient gradient glow, echoing the reference artwork. -->
        <div
            class="pointer-events-none absolute -top-40 -right-40 h-[32rem] w-[32rem]
                   rounded-full bg-brand/20 blur-[120px]"
        />
        <div
            class="pointer-events-none absolute -bottom-52 -left-40 h-[30rem] w-[30rem]
                   rounded-full bg-grape/20 blur-[120px]"
        />

        <div class="relative w-full max-w-md">
            <!-- Brand mark -->
            <div class="mb-8 text-center">
                <div
                    class="mx-auto mb-4 flex h-14 w-14 items-center justify-center
                           rounded-2xl bg-brand-gradient text-2xl shadow-glow-lg"
                >
                    🎬
                </div>
                <h1 class="font-display text-3xl font-bold tracking-tight text-ink">
                    {{ props.appName }}
                </h1>
                <p class="mt-2 text-sm text-ink-muted">
                    Admin panel — sign in to manage the drama library and rewards.
                </p>
            </div>

            <!-- Form card -->
            <div class="card p-8">
                <form class="space-y-5" @submit.prevent="submit">
                    <div>
                        <label class="field-label" for="email">Email address</label>
                        <input
                            id="email"
                            ref="emailInput"
                            v-model="form.email"
                            type="email"
                            name="email"
                            autocomplete="username"
                            required
                            autofocus
                            class="field-input"
                            placeholder="admin@example.com"
                        />
                        <p
                            v-if="errors.email"
                            class="mt-1.5 text-sm text-danger-soft"
                        >
                            {{ errors.email }}
                        </p>
                    </div>

                    <div>
                        <label class="field-label" for="password">Password</label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            name="password"
                            autocomplete="current-password"
                            required
                            class="field-input"
                            placeholder="••••••••"
                        />
                        <p
                            v-if="errors.password"
                            class="mt-1.5 text-sm text-danger-soft"
                        >
                            {{ errors.password }}
                        </p>
                    </div>

                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-ink-muted">
                        <input
                            v-model="form.remember"
                            type="checkbox"
                            name="remember"
                            class="h-4 w-4 rounded border-surface-border bg-surface-overlay
                                   text-brand focus:ring-brand/40"
                        />
                        Keep me signed in
                    </label>

                    <button
                        type="submit"
                        class="btn-brand w-full"
                        :disabled="submitting"
                    >
                        <span v-if="submitting">Signing in…</span>
                        <span v-else>Sign in</span>
                    </button>
                </form>
            </div>

            <p class="mt-6 text-center text-xs text-ink-faint">
                Staff access only. Sessions are recorded in the audit log.
            </p>
        </div>
    </div>
</template>
