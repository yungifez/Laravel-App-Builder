<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import {
    contact,
    home,
    login,
    pricing,
    privacy,
    register,
    terms,
} from '@/routes';
import { index } from '@/routes/projects';
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <header class="sticky top-0 z-30 border-b bg-background">
            <div
                class="mx-auto flex h-12 max-w-6xl items-center justify-between gap-3 px-4"
            >
                <Link :href="home()" class="flex min-w-0 items-center">
                    <AppLogo />
                </Link>
                <nav class="flex items-center gap-1 text-sm">
                    <Link
                        :href="pricing()"
                        class="hidden min-h-11 items-center rounded-md px-3 text-muted-foreground select-none hover:text-foreground sm:inline-flex sm:min-h-8"
                    >
                        Pricing
                    </Link>
                    <Link
                        v-if="$page.props.auth?.user"
                        :href="index()"
                        class="inline-flex min-h-11 press items-center rounded-md bg-primary px-4 font-medium text-primary-foreground select-none hover:bg-primary/90 sm:min-h-8"
                    >
                        Your apps
                    </Link>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="inline-flex min-h-11 items-center rounded-md px-3 text-muted-foreground select-none hover:text-foreground sm:min-h-8"
                        >
                            Log in
                        </Link>
                        <Link
                            :href="register()"
                            class="inline-flex min-h-11 press items-center rounded-md bg-primary px-4 font-medium text-primary-foreground select-none hover:bg-primary/90 sm:min-h-8"
                        >
                            Start an app
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <main class="flex-1 p-3">
            <slot />
        </main>

        <footer class="border-t">
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-6"
            >
                <AppLogo />
                <nav class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    <Link
                        :href="pricing()"
                        class="text-muted-foreground hover:text-foreground"
                    >
                        Pricing
                    </Link>
                    <Link
                        :href="contact()"
                        class="text-muted-foreground hover:text-foreground"
                    >
                        Contact
                    </Link>
                    <Link
                        :href="privacy()"
                        class="text-muted-foreground hover:text-foreground"
                    >
                        Privacy
                    </Link>
                    <Link
                        :href="terms()"
                        class="text-muted-foreground hover:text-foreground"
                    >
                        Terms
                    </Link>
                </nav>
            </div>
        </footer>
    </div>
</template>
