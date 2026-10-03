<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import { login, register } from '@/routes';
import { index } from '@/routes/projects';

// The page answers one question: why build here and not with any other
// AI app builder? It reads like a record (DESIGN.md): a claim, then the
// proof under it. Every line must stay true of the product as it is; the
// left column describes the usual approach, never a named product.
// Pictures are placeholders: swap the files in public/images/product and
// their sizes here.
const differences = [
    {
        topic: 'Memory',
        usual: 'Starts from a blank slate each time you ask.',
        ours: 'Keeps a written understanding of your app, and you can correct it.',
    },
    {
        topic: 'Checking',
        usual: 'Says it is done. You find out what broke.',
        ours: 'Tries each change in your app on every screen size first, and tells you what it could not check.',
    },
    {
        topic: 'Changes',
        usual: 'Shows you a wall of code changes.',
        ours: 'Says what was there before and what is there now, in plain words.',
    },
    {
        topic: 'Undo',
        usual: 'Going back means restoring an old version.',
        ours: 'Undo one change you kept, later on. The others stay.',
    },
    {
        topic: 'Code',
        usual: 'Code in whatever shape the AI chose.',
        ours: 'A standard Laravel app, built the way Laravel developers expect.',
    },
    {
        topic: 'Your app',
        usual: 'Best at starting new apps.',
        ours: 'Brings in the Laravel app you already have, and reads it first.',
    },
];

const scenes = [
    {
        id: 'say',
        topic: 'Start',
        title: 'Start with a sentence.',
        text: 'Describe your app the way you would tell a friend. I set up a working Laravel app, then you shape it.',
        image: '/images/product/ask.webp',
        width: 1600,
        height: 558,
        alt: 'The box where you describe the app you want',
    },
    {
        id: 'check',
        topic: 'Before you keep it',
        title: 'Every change comes with its proof.',
        text: 'Each change shows what it changed and how I know it works. When something is not checked, I say so.',
        image: '/images/product/change.webp',
        width: 2000,
        height: 1474,
        alt: 'A kept change with what it changed and the checks it passed',
    },
    {
        id: 'shape',
        topic: 'Design',
        title: 'Point at anything and change it exactly.',
        text: 'Click any part of your app and set how it looks. The change is exact, not a guess.',
        image: '/images/product/design.webp',
        width: 2400,
        height: 1500,
        alt: 'The Book room button picked, with its words and text controls',
    },
];

const promises = [
    {
        title: 'I read it first.',
        text: 'Bring in your app and I write down what it does before I change anything.',
    },
    {
        title: 'Undo any change you kept.',
        text: 'Just that one change, even after others.',
    },
    {
        title: 'Tried on every screen.',
        text: 'Phones and computers both, before you see the change.',
    },
    {
        title: 'Your code. Any time.',
        text: 'It is a standard Laravel app. Take it with you whenever you like.',
    },
    {
        title: 'Online in one click.',
        text: 'If a release goes wrong, go back to the last good one.',
    },
];
</script>

<template>
    <Head title="Build real software without losing control" />

    <div class="min-h-svh overflow-x-clip bg-background text-foreground">
        <header class="sticky top-0 z-30 border-b bg-background">
            <div
                class="mx-auto flex h-14 max-w-6xl items-center justify-between gap-3 px-4"
            >
                <div class="flex min-w-0 items-center">
                    <AppLogo />
                </div>
                <nav class="flex items-center gap-1 text-sm">
                    <a
                        href="#different"
                        class="hidden min-h-11 items-center px-3 text-muted-foreground underline-offset-4 select-none hover:text-foreground hover:underline sm:inline-flex sm:min-h-9"
                        >What is different</a
                    >
                    <Link
                        v-if="$page.props.auth.user"
                        :href="index()"
                        class="inline-flex min-h-11 press items-center rounded-md bg-primary px-4 font-medium text-primary-foreground select-none hover:bg-primary/90 sm:min-h-9"
                        data-test="welcome-apps"
                    >
                        Your apps
                    </Link>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="inline-flex min-h-11 items-center px-3 text-muted-foreground underline-offset-4 select-none hover:text-foreground hover:underline sm:min-h-9"
                        >
                            Log in
                        </Link>
                        <Link
                            :href="register()"
                            class="inline-flex min-h-11 press items-center rounded-md bg-primary px-4 font-medium text-primary-foreground select-none hover:bg-primary/90 sm:min-h-9"
                            data-test="welcome-start"
                        >
                            Start an app
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <main>
            <!-- The thesis, set large on the left edge, then the product. -->
            <section class="mx-auto max-w-6xl px-4 pt-16 sm:pt-28">
                <h1
                    class="max-w-4xl font-display text-5xl leading-[1.02] tracking-tight text-balance sm:text-7xl lg:text-8xl"
                >
                    Build real apps with AI, and stay in control of how they
                    work.
                </h1>
                <div
                    class="mt-8 grid gap-6 border-t pt-6 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start"
                >
                    <p
                        class="max-w-xl text-lg text-pretty text-muted-foreground"
                    >
                        Describe your app in a sentence. I build it on Laravel
                        and check each change before you see it.
                    </p>
                    <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                        <Link
                            :href="$page.props.auth.user ? index() : register()"
                            class="inline-flex min-h-11 press items-center gap-2 rounded-md bg-primary px-5 font-medium text-primary-foreground select-none hover:bg-primary/90"
                            data-test="welcome-hero-start"
                        >
                            Start an app
                            <ArrowRight class="size-4" />
                        </Link>
                        <a
                            href="#different"
                            class="inline-flex min-h-11 items-center font-medium text-primary underline-offset-4 select-none hover:underline"
                            >See what is different</a
                        >
                    </div>
                </div>

                <figure class="mt-14 sm:mt-20" data-test="welcome-example">
                    <img
                        src="/images/product/workspace.webp"
                        width="2400"
                        height="1500"
                        alt="The workspace: a conversation about the app beside the app itself"
                        class="w-full rounded-md border"
                        fetchpriority="high"
                    />
                    <figcaption
                        class="mt-3 font-mono text-xs text-muted-foreground"
                    >
                        The workspace, where you ask for a change and see it in
                        your app.
                    </figcaption>
                </figure>
            </section>

            <!-- The difference, as a ledger: the usual way in the middle,
                 ours on the right, one topic per row. -->
            <section
                id="different"
                class="mx-auto mt-24 max-w-6xl scroll-mt-14 px-4 sm:mt-36"
                data-test="welcome-different"
            >
                <div class="border-t pt-6">
                    <h2
                        class="max-w-4xl font-display text-4xl leading-[1.05] tracking-tight text-balance sm:text-6xl"
                    >
                        Other AI builders write code. I understand your app.
                    </h2>
                    <p
                        class="mt-5 max-w-2xl text-lg text-pretty text-muted-foreground"
                    >
                        Most tools treat each request as a fresh guess. I keep
                        track of what your app does, so each change fits.
                    </p>
                </div>

                <div class="mt-12">
                    <div
                        class="hidden grid-cols-[9rem_minmax(0,1fr)_minmax(0,1fr)] gap-8 border-b pb-3 text-sm text-muted-foreground md:grid"
                        aria-hidden="true"
                    >
                        <span />
                        <span>The usual AI app builder</span>
                        <span class="text-foreground">Here</span>
                    </div>
                    <dl class="divide-y border-b">
                        <div
                            v-for="row in differences"
                            :key="row.topic"
                            class="grid gap-x-8 gap-y-2 py-5 md:grid-cols-[9rem_minmax(0,1fr)_minmax(0,1fr)]"
                        >
                            <dt
                                class="font-mono text-xs leading-6 text-muted-foreground"
                            >
                                {{ row.topic }}
                            </dt>
                            <dd class="text-pretty text-muted-foreground">
                                <span class="md:sr-only">Usually: </span
                                >{{ row.usual }}
                            </dd>
                            <dd class="font-medium text-pretty">
                                <span class="md:sr-only">Here: </span
                                >{{ row.ours }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </section>

            <!-- Each part on a real screen. Text and picture share the
                 rule above them; the topic sits on that rule. -->
            <section
                v-for="scene in scenes"
                :id="scene.id"
                :key="scene.id"
                class="mx-auto mt-24 max-w-6xl px-4 sm:mt-36"
            >
                <div
                    class="grid gap-6 border-t pt-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-12"
                >
                    <div>
                        <p class="font-mono text-xs text-muted-foreground">
                            {{ scene.topic }}
                        </p>
                        <h2
                            class="mt-3 font-display text-4xl leading-[1.05] tracking-tight text-balance sm:text-5xl"
                        >
                            {{ scene.title }}
                        </h2>
                        <p
                            class="mt-4 max-w-md text-pretty text-muted-foreground"
                        >
                            {{ scene.text }}
                        </p>
                    </div>
                    <img
                        :src="scene.image"
                        :width="scene.width"
                        :height="scene.height"
                        :alt="scene.alt"
                        loading="lazy"
                        class="w-full self-start rounded-md border"
                    />
                </div>
            </section>

            <section class="mx-auto mt-24 max-w-6xl px-4 sm:mt-36">
                <div class="border-t pt-6">
                    <h2
                        class="max-w-3xl font-display text-4xl leading-[1.05] tracking-tight text-balance sm:text-6xl"
                    >
                        Nothing you keep is locked in.
                    </h2>
                </div>
                <ul
                    class="mt-10 divide-y border-y"
                    data-test="welcome-promises"
                >
                    <li
                        v-for="promise in promises"
                        :key="promise.title"
                        class="grid gap-x-8 gap-y-1 py-5 md:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]"
                    >
                        <h3 class="font-display text-2xl tracking-tight">
                            {{ promise.title }}
                        </h3>
                        <p class="text-pretty text-muted-foreground md:pt-1.5">
                            {{ promise.text }}
                        </p>
                    </li>
                </ul>
            </section>

            <section
                class="mx-auto mt-24 max-w-6xl px-4 pb-24 sm:mt-36 sm:pb-36"
            >
                <div
                    class="flex flex-wrap items-end justify-between gap-6 border-t pt-6"
                >
                    <h2
                        class="font-display text-5xl leading-[1.02] tracking-tight sm:text-7xl"
                    >
                        What do you want to make?
                    </h2>
                    <Link
                        :href="$page.props.auth.user ? index() : register()"
                        class="inline-flex min-h-11 press items-center gap-2 rounded-md bg-primary px-5 font-medium text-primary-foreground select-none hover:bg-primary/90"
                    >
                        Start an app
                        <ArrowRight class="size-4" />
                    </Link>
                </div>
            </section>
        </main>

        <footer class="border-t">
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-6"
            >
                <AppLogo />
                <p class="font-mono text-xs text-muted-foreground">
                    Real apps on Laravel that stay yours.
                </p>
            </div>
        </footer>
    </div>
</template>
