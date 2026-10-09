<script setup lang="ts">
import PageMeta from '@/components/PageMeta.vue';

// The text is written in Markdown on the server, with any HTML in it
// stripped, so it is safe to show as it is.
defineProps<{
    title: string;
    html: string;
}>();

// The line search results show under each page's title.
const summaries: Record<string, string> = {
    Privacy: 'What we keep about you, why, and who else sees it.',
    Terms: 'The terms for making and changing apps here, and what we promise.',
};
</script>

<template>
    <PageMeta
        :title="title"
        :description="
            summaries[title] ?? 'How we look after you and your apps.'
        "
    />

    <section class="mx-auto max-w-7xl px-4 pt-20 pb-24 sm:px-8 sm:pt-32">
        <article class="legal max-w-2xl" v-html="html" />
    </section>
</template>

<style scoped>
@reference '../../../css/app.css';

.legal :deep(h1) {
    @apply font-display text-4xl leading-[1.05] font-medium tracking-[-0.035em] sm:text-6xl;
}

.legal :deep(h1 + p) {
    @apply mt-4 text-sm text-muted-foreground;
}

.legal :deep(h2) {
    @apply mt-14 font-display text-2xl font-medium tracking-[-0.02em];
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
