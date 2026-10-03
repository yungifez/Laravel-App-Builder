<script setup lang="ts">
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { watch } from 'vue';
import DeveloperReviewController from '@/actions/App/Http/Controllers/DeveloperReviewController';
import DeveloperReviewGuidanceController from '@/actions/App/Http/Controllers/DeveloperReviewGuidanceController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { when } from '@/lib/when';
import { index, show } from '@/routes/projects';
import { index as developers } from '@/routes/projects/developers';

// The owner asks one of our developers for an hour of judgment
// (architecture §29.3). What they say comes back here, and guidance the
// owner keeps joins the app's notes so every later change follows it.
type Review = {
    id: string;
    question: string;
    change: { id: string; prompt: string } | null;
    asked_at: string | null;
    waiting: boolean;
    withdrawn: boolean;
    developer: string | null;
    answer: {
        summary: string;
        findings: string[];
        guidance: string[];
    } | null;
    answered_at: string | null;
    guidance_kept_at: string | null;
    kept_guidance: string[] | null;
};

const props = defineProps<{
    project: { id: string; name: string };
    change: { id: string; prompt: string } | null;
    reviews: Review[];
}>();

watch(
    () => props.project,
    (project) =>
        setLayoutProps({
            breadcrumbs: [
                { title: 'Your apps', href: index() },
                { title: project.name, href: show(project.id) },
                { title: 'Ask a developer', href: developers(project.id) },
            ],
        }),
    { immediate: true },
);
</script>

<template>
    <Head title="Ask a developer" />

    <div class="h-full overflow-y-auto">
        <div class="mx-auto max-w-2xl space-y-14 px-4 py-10 sm:px-6">
            <section>
                <h1 class="font-display text-3xl tracking-tight">
                    Ask a developer
                </h1>
                <p class="mt-2 max-w-prose text-muted-foreground">
                    One of our developers looks at your app and tells you what
                    they would worry about and what they would do. Guidance you
                    keep, I follow in every change after.
                </p>

                <Form
                    v-bind="DeveloperReviewController.store.form(project.id)"
                    class="mt-6 space-y-3"
                    reset-on-success
                    v-slot="{ errors, processing }"
                >
                    <div
                        v-if="change"
                        class="flex items-start gap-2 text-sm"
                        data-test="asking-about-change"
                    >
                        <input type="hidden" name="change" :value="change.id" />
                        <p class="min-w-0 flex-1 text-muted-foreground">
                            About the change
                            <span class="text-foreground"
                                >“{{ change.prompt }}”</span
                            >
                        </p>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="-mt-1.5 size-9 shrink-0 sm:size-7"
                            aria-label="Ask about the whole app instead"
                            as-child
                        >
                            <Link :href="developers(project.id)">
                                <X class="size-4" />
                            </Link>
                        </Button>
                    </div>
                    <label for="question" class="sr-only"
                        >What should they look at?</label
                    >
                    <textarea
                        id="question"
                        name="question"
                        rows="3"
                        :placeholder="
                            change
                                ? 'For example: Is this safe to keep? People sometimes pay twice.'
                                : 'For example: Is the way my app handles payments still sound?'
                        "
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                        data-test="question"
                    />
                    <InputError :message="errors.question ?? errors.change" />
                    <Button
                        :disabled="processing"
                        class="h-11 sm:h-9"
                        data-test="ask-developer"
                    >
                        Ask a developer
                    </Button>
                </Form>
            </section>

            <section v-if="reviews.length">
                <h2 class="mb-4 text-xl font-semibold tracking-[-0.02em]">
                    Your questions
                </h2>

                <article
                    v-for="review in reviews"
                    :key="review.id"
                    class="space-y-4 border-t pt-6 not-first-of-type:mt-10"
                    data-test="review"
                >
                    <div>
                        <p class="font-medium">{{ review.question }}</p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            <template v-if="review.change"
                                >About “{{ review.change.prompt }}” ·
                            </template>
                            Asked {{ when(review.asked_at) }}
                        </p>
                    </div>

                    <p
                        v-if="review.waiting"
                        class="text-sm text-muted-foreground"
                    >
                        A developer will answer here. I will let you know.
                    </p>

                    <!-- What they said. -->
                    <div
                        v-if="review.answer"
                        class="space-y-4"
                        data-test="review-answer"
                    >
                        <div>
                            <p class="text-sm text-muted-foreground">
                                {{ review.developer ?? 'Our developer' }}
                                answered
                                {{ when(review.answered_at) }}
                            </p>
                            <p class="mt-1 max-w-prose whitespace-pre-line">
                                {{ review.answer.summary }}
                            </p>
                        </div>

                        <div v-if="review.answer.findings.length">
                            <h3 class="text-sm font-medium">
                                What they noticed
                            </h3>
                            <ul class="mt-1.5 list-disc space-y-1 pl-5 text-sm">
                                <li
                                    v-for="(finding, at) in review.answer
                                        .findings"
                                    :key="at"
                                >
                                    {{ finding }}
                                </li>
                            </ul>
                        </div>

                        <div v-if="review.answer.guidance.length">
                            <h3 class="text-sm font-medium">
                                Their guidance for your app
                            </h3>
                            <ul
                                v-if="review.guidance_kept_at"
                                class="mt-1.5 list-disc space-y-1 pl-5 text-sm"
                            >
                                <li
                                    v-for="(
                                        point, at
                                    ) in review.kept_guidance ?? []"
                                    :key="at"
                                >
                                    {{ point }}
                                </li>
                            </ul>
                            <Form
                                v-else
                                v-bind="
                                    DeveloperReviewGuidanceController.store.form(
                                        review.id,
                                    )
                                "
                                class="mt-1.5 space-y-3"
                                v-slot="{ errors, processing }"
                            >
                                <label
                                    v-for="(point, at) in review.answer
                                        .guidance"
                                    :key="at"
                                    class="flex min-h-11 items-start gap-3 text-sm sm:min-h-0"
                                >
                                    <input
                                        type="checkbox"
                                        name="points[]"
                                        :value="at"
                                        checked
                                        class="mt-0.5 size-4 shrink-0 accent-primary"
                                    />
                                    <span>{{ point }}</span>
                                </label>
                                <InputError :message="errors.points" />
                                <Button
                                    :disabled="processing"
                                    class="h-11 sm:h-9"
                                    data-test="keep-guidance"
                                >
                                    Keep this guidance
                                </Button>
                                <p class="text-xs text-muted-foreground">
                                    I follow kept guidance in every change from
                                    now on.
                                </p>
                            </Form>
                            <p
                                v-if="review.guidance_kept_at"
                                class="mt-2 text-xs text-muted-foreground"
                                data-test="guidance-kept"
                            >
                                Kept {{ when(review.guidance_kept_at) }}. I
                                follow it in every change.
                            </p>
                        </div>
                    </div>

                    <Form
                        v-if="review.waiting"
                        v-bind="
                            DeveloperReviewController.destroy.form(review.id)
                        "
                        v-slot="{ processing }"
                    >
                        <button
                            type="submit"
                            :disabled="processing"
                            class="min-h-11 text-xs text-muted-foreground select-none hover:text-foreground sm:min-h-6"
                            data-test="withdraw"
                        >
                            I no longer need this
                        </button>
                    </Form>
                    <p
                        v-else-if="review.withdrawn"
                        class="text-xs text-muted-foreground"
                    >
                        You took this back.
                    </p>
                </article>
            </section>
        </div>
    </div>
</template>
