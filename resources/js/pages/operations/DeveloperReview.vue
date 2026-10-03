<script setup lang="ts">
import { Form, Head, Link, setLayoutProps, usePage } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { watch } from 'vue';
import DeveloperReviewClaimController from '@/actions/App/Http/Controllers/Operations/DeveloperReviewClaimController';
import DeveloperReviewController from '@/actions/App/Http/Controllers/Operations/DeveloperReviewController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { stamp } from '@/lib/operations';
import { attention } from '@/routes/operations';
import { show as showChange } from '@/routes/operations/changes';
import {
    change as changeDownload,
    code as codeDownload,
    index,
    request as requestDownload,
    show,
} from '@/routes/operations/developer-reviews';

// One owner's question, for the developer of ours who answers it: what we
// wrote for them on the left, their answer on the right.
const props = defineProps<{
    review: {
        id: string;
        app: string;
        change: string | null;
        request: string;
        has_code: boolean;
        has_change: boolean;
        answer: {
            summary: string;
            findings: string[];
            guidance: string[];
        } | null;
        developer: string | null;
        taken_by: string | null;
        answered_at: string | null;
        withdrawn: boolean;
        guidance_kept: boolean;
        kept_guidance: string[] | null;
    };
    can: { claim: boolean; release: boolean; answer: boolean };
}>();

const page = usePage();

watch(
    () => props.review.id,
    (id) =>
        setLayoutProps({
            // Approved developers who are not operators see only the questions.
            breadcrumbs: [
                ...(page.props.auth.operator
                    ? [{ title: 'Operations', href: attention().url }]
                    : []),
                { title: 'Questions for developers', href: index().url },
                { title: props.review.app, href: show(id).url },
            ],
        }),
    { immediate: true },
);

const field =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
const download =
    'inline-flex min-h-11 items-center gap-1.5 text-sm font-medium text-foreground/80 select-none hover:text-foreground sm:min-h-9';
</script>

<template>
    <Head :title="`Question from ${review.app}`" />

    <div class="h-full overflow-y-auto">
        <div
            class="mx-auto grid w-full max-w-6xl gap-10 px-4 py-6 sm:px-6 lg:grid-cols-[minmax(0,1fr)_22rem]"
        >
            <article class="min-w-0">
                <nav class="mb-6 flex flex-wrap gap-x-5" aria-label="Downloads">
                    <a
                        v-if="review.has_code"
                        :href="codeDownload(review.id).url"
                        :class="download"
                        data-test="download-code"
                        ><Download class="size-4" /> Code</a
                    >
                    <a
                        v-if="review.has_change"
                        :href="changeDownload(review.id).url"
                        :class="download"
                        data-test="download-change"
                        ><Download class="size-4" /> The change</a
                    >
                    <a :href="requestDownload(review.id).url" :class="download"
                        ><Download class="size-4" /> This page as Markdown</a
                    >
                    <Link
                        v-if="review.change"
                        :href="showChange(review.change).url"
                        :class="download"
                        >How the change was made</Link
                    >
                </nav>

                <!-- Escaped Markdown from the server: text, never markup. -->
                <div
                    class="max-w-prose text-sm leading-relaxed [&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_code]:text-xs [&_h1]:text-2xl [&_h1]:font-semibold [&_h1]:tracking-[-0.02em] [&_h2]:mt-8 [&_h2]:mb-2 [&_h2]:text-lg [&_h2]:font-semibold [&_h3]:mt-5 [&_h3]:mb-1 [&_h3]:font-semibold [&_h4]:mt-3 [&_h4]:mb-1 [&_h4]:text-xs [&_h4]:font-semibold [&_h4]:text-muted-foreground [&_li]:mt-1 [&_p]:mt-2 [&_pre]:mt-2 [&_pre]:max-h-[32rem] [&_pre]:overflow-auto [&_pre]:rounded-md [&_pre]:border [&_pre]:p-3 [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_ul]:mt-2 [&_ul]:list-disc [&_ul]:pl-5"
                    data-test="request"
                    v-html="review.request"
                />
            </article>

            <aside class="lg:sticky lg:top-6 lg:self-start">
                <h2 class="text-lg font-semibold">Your answer</h2>
                <p
                    v-if="review.withdrawn"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    The owner took this question back.
                </p>
                <p
                    v-else-if="review.guidance_kept"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    The owner kept the guidance below, so the answer stays as it
                    is.
                </p>
                <p
                    v-else-if="review.answered_at"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    {{ review.developer }} answered
                    {{ stamp(review.answered_at) }}. You can still change it.
                </p>

                <template v-if="!review.withdrawn && !review.guidance_kept">
                    <p
                        v-if="!can.answer && review.taken_by"
                        class="mt-2 text-sm text-muted-foreground"
                        data-test="taken-by"
                    >
                        {{ review.taken_by }} took this question.
                    </p>
                    <Form
                        v-else-if="!can.answer && can.claim"
                        v-bind="
                            DeveloperReviewClaimController.store.form(review.id)
                        "
                        class="mt-2 space-y-3"
                        v-slot="{ errors, processing }"
                    >
                        <p class="text-sm text-muted-foreground">
                            Read the question and the code first. Taking it
                            tells the other developers you are answering it.
                        </p>
                        <Button
                            :disabled="processing"
                            class="h-11 sm:h-9"
                            data-test="take-question"
                        >
                            Take this question
                        </Button>
                        <InputError :message="errors.claim" />
                    </Form>
                </template>

                <Form
                    v-if="
                        !review.withdrawn && !review.guidance_kept && can.answer
                    "
                    v-bind="DeveloperReviewController.update.form(review.id)"
                    class="mt-4 space-y-4"
                    v-slot="{ errors, processing }"
                >
                    <label class="block space-y-1.5">
                        <span class="text-sm font-medium"
                            >What you would tell the owner</span
                        >
                        <textarea
                            name="summary"
                            rows="6"
                            :value="review.answer?.summary ?? ''"
                            placeholder="What you found, in words the owner understands."
                            :class="field"
                            data-test="answer-summary"
                        />
                        <InputError :message="errors.summary" />
                    </label>
                    <label class="block space-y-1.5">
                        <span class="text-sm font-medium"
                            >What you noticed</span
                        >
                        <span class="block text-xs text-muted-foreground"
                            >One point per line.</span
                        >
                        <textarea
                            name="findings"
                            rows="4"
                            :value="review.answer?.findings.join('\n') ?? ''"
                            :class="field"
                            data-test="answer-findings"
                        />
                        <InputError :message="errors.findings" />
                    </label>
                    <label class="block space-y-1.5">
                        <span class="text-sm font-medium"
                            >Guidance for every later change</span
                        >
                        <span class="block text-xs text-muted-foreground"
                            >One rule per line. The owner chooses what to keep;
                            kept guidance goes into every change from then
                            on.</span
                        >
                        <textarea
                            name="guidance"
                            rows="4"
                            :value="review.answer?.guidance.join('\n') ?? ''"
                            placeholder="For example: Keep every payment behind one class."
                            :class="field"
                            data-test="answer-guidance"
                        />
                        <InputError :message="errors.guidance" />
                    </label>
                    <Button
                        :disabled="processing"
                        class="h-11 sm:h-9"
                        data-test="send-answer"
                    >
                        {{
                            review.answered_at
                                ? 'Save the answer'
                                : 'Send the answer'
                        }}
                    </Button>
                </Form>
                <Link
                    v-if="can.release"
                    :href="
                        DeveloperReviewClaimController.destroy.url(review.id)
                    "
                    method="delete"
                    as="button"
                    class="mt-3 min-h-11 text-sm text-muted-foreground select-none hover:text-foreground sm:min-h-9"
                    data-test="give-back"
                >
                    Give it back for someone else to answer
                </Link>

                <template v-else-if="review.answer">
                    <p class="mt-4 text-sm whitespace-pre-line">
                        {{ review.answer.summary }}
                    </p>
                    <ul
                        v-if="review.kept_guidance?.length"
                        class="mt-3 list-disc space-y-1 pl-5 text-sm"
                    >
                        <li
                            v-for="(point, at) in review.kept_guidance"
                            :key="at"
                        >
                            {{ point }}
                        </li>
                    </ul>
                </template>
            </aside>
        </div>
    </div>
</template>
