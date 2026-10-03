<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

// The text is written in Markdown on the server, with any HTML in it
// stripped, so it is safe to show as it is.
defineProps<{
    title: string;
    html: string;
}>();
</script>

<template>
    <Head :title="title" />

    <section class="bg-muted/50">
        <div class="mx-auto max-w-6xl px-4 pt-16 pb-24 sm:px-6 sm:pt-24">
            <article class="legal max-w-2xl" v-html="html" />
        </div>
    </section>
</template>

<style scoped>
@reference '../../../css/app.css';

.legal :deep(h1) {
    @apply font-display text-4xl leading-[1.05] sm:text-5xl;
}

.legal :deep(h1 + p) {
    @apply mt-4 text-sm text-muted-foreground;
}

.legal :deep(h2) {
    @apply mt-12 border-t pt-6 font-display text-xl;
}

.legal :deep(p) {
    @apply mt-4 leading-relaxed;
}

.legal :deep(ul) {
    @apply mt-4 list-disc space-y-2 pl-5;
}

.legal :deep(strong) {
    @apply font-semibold;
}
</style>
