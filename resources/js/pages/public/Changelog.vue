<script setup lang="ts">
import PageMeta from '@/components/PageMeta.vue';

type Week = {
    starts_on: string;
    label: string;
    entries: { title: string; body: string }[];
};

// What changed for owners, written by hand in resources/changelog.json.
// Only what an owner would notice goes in, in the words the product uses.
defineProps<{
    weeks: Week[];
}>();
</script>

<template>
    <PageMeta
        title="What's new"
        description="What changed for people building their apps here, week by week."
    />

    <section class="mx-auto max-w-7xl px-4 pt-20 pb-24 sm:px-8 sm:pt-32">
        <h1
            class="max-w-3xl font-display text-4xl leading-[1.05] font-medium tracking-[-0.035em] text-balance sm:text-6xl"
        >
            What's new.
            <span class="text-muted-foreground"
                >What changed for you, week by week.</span
            >
        </h1>

        <div class="mt-16 space-y-20 sm:mt-24 sm:space-y-28">
            <article
                v-for="week in weeks"
                :key="week.starts_on"
                class="grid gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16"
                data-test="changelog-week"
            >
                <h2
                    class="font-display text-2xl leading-[1.1] font-medium tracking-[-0.02em] lg:sticky lg:top-24 lg:self-start"
                >
                    <time :datetime="week.starts_on">{{ week.label }}</time>
                </h2>
                <ul class="divide-y border-y">
                    <li
                        v-for="entry in week.entries"
                        :key="entry.title"
                        class="py-5"
                    >
                        <h3 class="font-medium">{{ entry.title }}</h3>
                        <p
                            class="mt-1 max-w-prose text-pretty text-muted-foreground"
                        >
                            {{ entry.body }}
                        </p>
                    </li>
                </ul>
            </article>
        </div>
    </section>
</template>
