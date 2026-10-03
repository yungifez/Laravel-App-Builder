<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import {
    contact,
    developers,
    home,
    login,
    pricing,
    privacy,
    register,
    terms,
} from '@/routes';
import { index } from '@/routes/projects';

// The header names the page you are on, so the links double as a map.
const page = usePage();
const here = (path: string): boolean =>
    page.url === path || page.url.startsWith(`${path}?`);
const navLink = (path: string): string =>
    here(path)
        ? 'text-foreground'
        : 'text-muted-foreground hover:text-foreground';
</script>

<template>
    <!-- The site's own description, for pages that do not set one. Their
         PageMeta replaces it, as both use the same head-key. -->
    <Head>
        <meta
            head-key="description"
            name="description"
            content="Build apps that don't stay prototypes. Every change passes fixed checks before you keep it."
        />
    </Head>
    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <header class="sticky top-0 z-30 border-b bg-background">
            <div
                class="mx-auto flex h-14 max-w-7xl items-center justify-between gap-3 px-4 sm:px-8"
            >
                <div class="flex min-w-0 items-center gap-8">
                    <Link :href="home()" class="flex items-center gap-2">
                        <AppLogo />
                    </Link>
                    <nav class="hidden items-center gap-6 text-sm md:flex">
                        <Link
                            :href="pricing()"
                            :class="navLink('/pricing')"
                            :aria-current="
                                here('/pricing') ? 'page' : undefined
                            "
                            >Pricing</Link
                        >
                        <Link
                            :href="developers()"
                            :class="navLink('/developers')"
                            :aria-current="
                                here('/developers') ? 'page' : undefined
                            "
                            >For developers</Link
                        >
                    </nav>
                </div>
                <nav class="flex items-center gap-1 text-sm">
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

        <main class="flex-1">
            <slot />
        </main>

        <footer class="border-t">
            <div
                class="mx-auto grid max-w-7xl gap-10 px-4 py-14 text-sm sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)] sm:px-8"
            >
                <div>
                    <div class="flex items-center gap-2"><AppLogo /></div>
                    <p class="mt-3 text-muted-foreground">
                        Real apps that stay yours.
                    </p>
                </div>
                <nav aria-label="Product">
                    <p class="font-medium">Product</p>
                    <ul class="mt-3 space-y-2 text-muted-foreground">
                        <li>
                            <Link
                                :href="pricing()"
                                class="hover:text-foreground"
                                >Pricing</Link
                            >
                        </li>
                        <li>
                            <Link
                                :href="developers()"
                                class="hover:text-foreground"
                                >For developers</Link
                            >
                        </li>
                    </ul>
                </nav>
                <nav aria-label="Company">
                    <p class="font-medium">Company</p>
                    <ul class="mt-3 space-y-2 text-muted-foreground">
                        <li>
                            <Link
                                :href="contact()"
                                class="hover:text-foreground"
                                >Contact</Link
                            >
                        </li>
                        <li>
                            <Link
                                :href="privacy()"
                                class="hover:text-foreground"
                                >Privacy</Link
                            >
                        </li>
                        <li>
                            <Link :href="terms()" class="hover:text-foreground"
                                >Terms</Link
                            >
                        </li>
                    </ul>
                </nav>
            </div>
        </footer>
    </div>
</template>
