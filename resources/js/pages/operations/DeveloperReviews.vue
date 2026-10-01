<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { stamp } from '@/lib/operations';
import { attention } from '@/routes/operations';
import { index, show } from '@/routes/operations/developer-reviews';
import type { Paginated } from '@/types';

// What owners asked our developers, the ones still waiting first.
type Row = {
    id: string;
    app: string;
    question: string;
    about_change: boolean;
    asked_at: string | null;
    waiting: boolean;
    withdrawn: boolean;
    developer: string | null;
    guidance_kept: boolean;
};

defineProps<{ reviews: Paginated<Row> }>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Operations', href: attention().url },
        { title: 'Questions for developers', href: index().url },
    ],
});

function state(review: Row): string {
    if (review.waiting) {
        return 'Waiting';
    }

    if (review.withdrawn) {
        return 'Taken back';
    }

    return review.guidance_kept
        ? `${review.developer ?? 'Answered'} · guidance kept`
        : `${review.developer ?? 'Answered'}`;
}
</script>

<template>
    <Head title="Questions for developers" />

    <div class="mx-auto w-full max-w-4xl space-y-6 px-4 py-6 sm:px-6">
        <p
            v-if="reviews.data.length === 0"
            class="text-muted-foreground"
            data-test="no-questions"
        >
            No owner has asked a developer yet.
        </p>

        <ul v-else class="divide-y border-y">
            <li v-for="review in reviews.data" :key="review.id">
                <Link
                    :href="show(review.id).url"
                    class="flex min-h-11 items-center gap-3 py-3 hover:bg-muted/40"
                    data-test="question-row"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{
                            review.question
                        }}</span>
                        <span class="block text-xs text-muted-foreground">
                            {{ review.app }} ·
                            {{ review.about_change ? 'a change' : 'the app' }}
                            · {{ stamp(review.asked_at) }}
                        </span>
                    </span>
                    <span
                        class="shrink-0 text-xs"
                        :class="
                            review.waiting
                                ? 'font-medium text-amber-600'
                                : 'text-muted-foreground'
                        "
                        >{{ state(review) }}</span
                    >
                    <ChevronRight
                        class="size-4 shrink-0 text-muted-foreground"
                    />
                </Link>
            </li>
        </ul>

        <nav
            v-if="reviews.last_page > 1"
            class="flex items-center justify-between text-sm"
        >
            <Link
                v-if="reviews.prev_page_url"
                :href="reviews.prev_page_url"
                class="min-h-11 content-center text-primary sm:min-h-9"
                >Newer</Link
            >
            <span class="text-muted-foreground"
                >Page {{ reviews.current_page }} of
                {{ reviews.last_page }}</span
            >
            <Link
                v-if="reviews.next_page_url"
                :href="reviews.next_page_url"
                class="min-h-11 content-center text-primary sm:min-h-9"
                >Older</Link
            >
        </nav>
    </div>
</template>
