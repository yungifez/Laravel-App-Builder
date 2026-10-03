<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { home, login, register } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

// The same plain page as the home page: its header, then one narrow
// column. The header offers the other way in, so a visitor on the wrong
// form is one click from the right one.
const page = usePage();
const offerLogin = computed(() =>
    ['auth/Register', 'auth/ForgotPassword', 'auth/ResetPassword'].includes(
        page.component,
    ),
);
const onLogin = computed(() => page.component === 'auth/Login');
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <header class="border-b">
            <div
                class="mx-auto flex h-14 max-w-7xl items-center justify-between gap-3 px-4 sm:px-8"
            >
                <Link :href="home()" class="flex items-center gap-2">
                    <AppLogo />
                </Link>
                <nav class="flex items-center gap-1 text-sm">
                    <Link
                        v-if="offerLogin"
                        :href="login()"
                        class="inline-flex min-h-11 items-center rounded-md px-3 text-muted-foreground select-none hover:text-foreground sm:min-h-9"
                    >
                        Log in
                    </Link>
                    <Link
                        v-else-if="onLogin"
                        :href="register()"
                        class="inline-flex min-h-11 press items-center rounded-md bg-primary px-4 font-medium text-primary-foreground select-none hover:bg-primary/90 sm:min-h-8"
                    >
                        Start an app
                    </Link>
                </nav>
            </div>
        </header>

        <main class="flex flex-1 justify-center px-4 pt-16 pb-24 sm:pt-28">
            <div class="w-full max-w-sm">
                <h1
                    class="font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-balance sm:text-4xl"
                >
                    {{ title }}
                </h1>
                <p
                    v-if="description"
                    class="mt-3 text-pretty text-muted-foreground"
                >
                    {{ description }}
                </p>
                <div class="mt-10">
                    <slot />
                </div>
            </div>
        </main>
    </div>
</template>
