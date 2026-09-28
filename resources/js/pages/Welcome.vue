<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CircleCheck, Download, Undo2 } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import { login, register } from '@/routes';
import { index } from '@/routes/projects';

// What sets the builder apart, each a thing the owner can see and use,
// not a claim. Keep every line true of the product as it is.
const promises = [
    {
        icon: CircleCheck,
        title: 'Checked before you see it',
        text: 'Each change runs in your app with its tests. If something breaks, I fix it first, and I tell you what I checked.',
    },
    {
        icon: Undo2,
        title: 'Undo what you kept',
        text: 'Every change you keep can be taken back later. Your app goes back to how it was.',
    },
    {
        icon: Download,
        title: 'Yours to take',
        text: 'Download the code any time. It is a standard Laravel app that any Laravel developer can work on.',
    },
];
</script>

<template>
    <Head title="Make an app by saying what it does" />

    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <header
            class="mx-auto flex w-full max-w-5xl items-center justify-between gap-3 px-4 py-4"
        >
            <div class="flex items-center gap-2">
                <AppLogo />
            </div>
            <nav class="flex items-center gap-1 text-sm">
                <Link
                    v-if="$page.props.auth.user"
                    :href="index()"
                    class="inline-flex min-h-11 items-center rounded-lg bg-primary px-4 font-medium text-primary-foreground select-none hover:bg-primary/90 sm:min-h-9"
                    data-test="welcome-apps"
                >
                    Your apps
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="inline-flex min-h-11 items-center rounded-lg px-4 text-muted-foreground select-none hover:text-foreground sm:min-h-9"
                    >
                        Log in
                    </Link>
                    <Link
                        :href="register()"
                        class="inline-flex min-h-11 items-center rounded-lg bg-primary px-4 font-medium text-primary-foreground select-none hover:bg-primary/90 sm:min-h-9"
                        data-test="welcome-start"
                    >
                        Start an app
                    </Link>
                </template>
            </nav>
        </header>

        <main class="mx-auto w-full max-w-5xl flex-1 px-4 pt-10 pb-20 sm:pt-20">
            <div
                class="grid items-center gap-12 lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]"
            >
                <div class="min-w-0">
                    <h1
                        class="text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
                    >
                        Say what your app does. Get one that works, and stays
                        yours.
                    </h1>
                    <p
                        class="mt-4 max-w-xl text-lg text-balance text-muted-foreground"
                    >
                        Describe it in a sentence or two. I build it, try each
                        change in your app before you see it, and you keep or
                        undo it.
                    </p>
                </div>

                <!-- Show the loop instead of describing it: an ask, the
                     proof, and the owner's choice. -->
                <figure
                    aria-label="An example change"
                    class="w-full max-w-md min-w-0 rounded-2xl border bg-card p-4 text-sm shadow-sm select-none lg:max-w-none"
                    data-test="welcome-example"
                >
                    <p
                        class="ml-auto w-fit max-w-[85%] rounded-2xl rounded-br-md bg-muted px-3.5 py-2"
                    >
                        Let customers book a clean online.
                    </p>
                    <p class="mt-4">
                        Customers now pick a day and a time, and you see each
                        booking on your jobs page.
                    </p>
                    <p
                        class="mt-3 flex items-center gap-1.5 text-muted-foreground"
                    >
                        <CircleCheck class="size-4 shrink-0 text-green-600" />
                        Tried in your app: 6 new checks pass
                    </p>
                    <div class="mt-4 flex justify-end gap-2" aria-hidden="true">
                        <span
                            class="inline-flex h-9 items-center rounded-lg border px-3"
                            >Try it first</span
                        >
                        <span
                            class="inline-flex h-9 items-center rounded-lg bg-primary px-4 font-medium text-primary-foreground"
                            >Keep it</span
                        >
                    </div>
                </figure>
            </div>

            <ul
                class="mt-16 grid gap-8 border-t pt-10 sm:grid-cols-3 sm:gap-6"
                data-test="welcome-promises"
            >
                <li v-for="promise in promises" :key="promise.title">
                    <component
                        :is="promise.icon"
                        class="size-5 text-muted-foreground"
                    />
                    <h2 class="mt-3 font-medium">{{ promise.title }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ promise.text }}
                    </p>
                </li>
            </ul>
        </main>
    </div>
</template>
