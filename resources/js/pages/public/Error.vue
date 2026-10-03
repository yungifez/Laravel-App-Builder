<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { contact, home } from '@/routes';

const props = defineProps<{
    status: 403 | 404 | 429 | 500 | 503;
}>();

// Say what happened and whose fault it is, in the owner's words.
const said = computed(
    () =>
        ({
            403: {
                title: 'This page is not yours to open',
                line: 'You may be signed in as someone else, or the page belongs to another person.',
            },
            404: {
                title: 'There is nothing here',
                line: 'The link may be old, or the page was removed.',
            },
            429: {
                title: 'Too many tries at once',
                line: 'Wait a minute, then try again.',
            },
            500: {
                title: 'Something broke on our side',
                line: 'This is our fault, and we have been told. Try again in a moment.',
            },
            503: {
                title: 'We are making changes',
                line: 'This is our fault. We will be back in a few minutes.',
            },
        })[props.status],
);
</script>

<template>
    <Head :title="said.title" />

    <section class="mx-auto max-w-7xl px-4 pt-20 pb-24 sm:px-8 sm:pt-32">
        <p class="text-sm text-muted-foreground">Error {{ status }}</p>
        <h1
            class="mt-3 max-w-3xl font-display text-4xl leading-[1.05] font-medium tracking-[-0.035em] text-balance sm:text-6xl"
        >
            {{ said.title }}.
            <span class="text-muted-foreground">{{ said.line }}</span>
        </h1>
        <div class="mt-10 flex flex-wrap gap-3">
            <Link
                :href="home()"
                class="inline-flex min-h-11 press items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground select-none hover:bg-primary/90 sm:min-h-9"
            >
                Go to the home page
            </Link>
            <Link
                :href="contact()"
                class="inline-flex min-h-11 items-center rounded-md border bg-background px-4 text-sm font-medium select-none hover:bg-muted sm:min-h-9"
            >
                Write to us
            </Link>
        </div>
    </section>
</template>
