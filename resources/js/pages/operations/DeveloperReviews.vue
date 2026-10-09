<script setup lang="ts">
import { Head, Link, setLayoutProps, usePage } from '@inertiajs/vue3';
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
    taken_by: string | null;
    mine: boolean;
    guidance_kept: boolean;
};

defineProps<{ reviews: Paginated<Row> }>();

const page = usePage();

// Approved developers who are not operators see only the questions.
setLayoutProps({
    breadcrumbs: [
        ...(page.props.auth.operator
            ? [{ title: 'Operations', href: attention().url }]
            : []),
        { title: 'Questions for developers', href: index().url },
    ],
});

function state(review: Row): string {
    if (review.waiting && review.mine) {
        return 'Yours to answer';
    }

    if (review.waiting) {
        return review.taken_by ? `Taken by ${review.taken_by}` : 'Waiting';
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
            No questions are waiting. You hear when an owner asks one.
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
                            <!-- On a phone the state sits here, so the
                                 question keeps the width. -->
                            <span
                                class="sm:hidden"
                                :class="
                                    review.waiting &&
                                    'font-medium text-amber-600'
                                "
                                >· {{ state(review) }}</span
                            >
                        </span>
                    </span>
                    <span
                        class="hidden shrink-0 text-xs sm:block"
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
