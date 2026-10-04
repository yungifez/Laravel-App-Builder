<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import PublicFooter from '@/components/PublicFooter.vue';
import { home, howItWorks, login, pricing, register } from '@/routes';
import { index } from '@/routes/projects';
import * as sampleDesign from '@/routes/sample-design';

// The header names the page you are on, so the links double as a map.
const page = usePage();
const here = (path: string): boolean =>
    page.url === path || page.url.startsWith(`${path}?`);
const navLink = (path: string): string =>
    'inline-flex min-h-11 items-center pointer-fine:min-h-0 ' +
    (here(path)
        ? 'text-foreground'
        : 'text-muted-foreground hover:text-foreground');
</script>

<template>
    <!-- The site's own description, for pages that do not set one. Their
         PageMeta replaces it, as both use the same head-key. -->
    <Head>
        <meta
            head-key="description"
            name="description"
            content="Don't just build a prototype. Every change passes fixed checks before you keep it."
        />
    </Head>
    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <a
            href="#main"
            class="sr-only rounded-md bg-background px-3 py-2 text-sm font-medium shadow-md focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50"
            >Skip to the content</a
        >
        <header class="sticky top-0 z-30 border-b bg-background">
            <div
                class="mx-auto flex h-14 max-w-7xl items-center justify-between gap-3 px-4 sm:px-8"
            >
                <div class="flex min-w-0 items-center gap-8">
                    <Link :href="home()" class="flex items-center gap-2">
                        <AppLogo />
                    </Link>
                    <!-- The same short menu as the home page's. -->
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
                            :href="howItWorks()"
                            :class="navLink(howItWorks().url)"
                            :aria-current="
                                here(howItWorks().url) ? 'page' : undefined
                            "
                            >How it works</Link
                        >
                        <Link
                            :href="sampleDesign.show().url"
                            :class="navLink(sampleDesign.show().url)"
                            >Try the designer</Link
                        >
                    </nav>
                </div>
                <nav class="flex items-center gap-1 text-sm">
                    <Link
                        v-if="$page.props.auth?.user"
                        :href="index()"
                        class="inline-flex min-h-11 press items-center rounded-md bg-primary px-4 font-medium text-primary-foreground select-none hover:bg-primary/90 pointer-fine:min-h-8"
                    >
                        Your apps
                    </Link>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="inline-flex min-h-11 items-center rounded-md px-3 text-muted-foreground select-none hover:text-foreground pointer-fine:min-h-8"
                        >
                            Log in
                        </Link>
                        <Link
                            :href="register()"
                            class="inline-flex min-h-11 press items-center rounded-md bg-primary px-4 font-medium text-primary-foreground select-none hover:bg-primary/90 pointer-fine:min-h-8"
                        >
                            Start an app
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <main id="main" class="flex-1">
            <slot />
        </main>

        <PublicFooter />
    </div>
</template>
