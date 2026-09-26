<script setup lang="ts">
import { Form, Link, usePoll } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    ChevronRight,
    CircleAlert,
    CircleCheck,
    ExternalLink,
    FileCode2,
    LoaderCircle,
    Sparkles,
    Undo2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FeatureRequestAcceptanceController from '@/actions/App/Http/Controllers/FeatureRequestAcceptanceController';
import FeatureRequestAnswerController from '@/actions/App/Http/Controllers/FeatureRequestAnswerController';
import FeatureRequestPreviewController from '@/actions/App/Http/Controllers/FeatureRequestPreviewController';
import FeatureRequestRetryController from '@/actions/App/Http/Controllers/FeatureRequestRetryController';
import FeatureRequestReversionController from '@/actions/App/Http/Controllers/FeatureRequestReversionController';
import FeatureRequestVerificationController from '@/actions/App/Http/Controllers/FeatureRequestVerificationController';
import PreviewController from '@/actions/App/Http/Controllers/PreviewController';
import RunCancellationController from '@/actions/App/Http/Controllers/RunCancellationController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { show as showFeatureRequest } from '@/routes/feature-requests';
import { show as showProject } from '@/routes/projects';
import type { ChangeDetail, Run } from '@/types';

const props = defineProps<{ change: ChangeDetail }>();

const request = computed(() => props.change.featureRequest);
const run = computed(() => props.change.run);
const moreQuestions = ref(false);

const working = computed(
    () =>
        (run.value !== null &&
            [
                'queued',
                'planning',
                'implementing',
                'verifying',
                'reviewing',
                'cancelling',
            ].includes(run.value.status)) ||
        (run.value === null && request.value.status === 'generating'),
);

const checking = computed(
    () =>
        props.change.verification?.status === 'queued' ||
        props.change.verification?.status === 'running',
);

const { start, stop } = usePoll(
    1500,
    { only: ['change', 'changes'] },
    { autoStart: false },
);

watch(
    () =>
        working.value ||
        checking.value ||
        props.change.preview?.status === 'starting',
    (busy) => (busy ? start() : stop()),
    { immediate: true },
);

const steps: Partial<Record<Run['status'], string>> = {
    queued: 'Getting started',
    planning: 'Working out what to change',
    implementing: 'Making the change',
    verifying: 'Checking it works',
    reviewing: 'Looking over what changed',
    cancelling: 'Stopping',
};

const failed = computed(
    () =>
        run.value?.status === 'failed' ||
        (run.value?.status === 'needs_user_decision' &&
            run.value.question === null) ||
        (run.value === null && request.value.status === 'failed'),
);

const changes = computed(() => run.value?.review?.changes ?? []);
const asked = computed(() =>
    changes.value.filter((change) => change.section !== 'unexpected'),
);
const unexpected = computed(() =>
    changes.value.filter((change) => change.section === 'unexpected'),
);

const checks = computed(() => {
    switch (props.change.verification?.status) {
        case 'passed':
        case 'unverified':
            return {
                icon: CircleCheck,
                tone: 'text-green-600',
                label: 'Checks passed',
            };
        case 'failed':
            return {
                icon: CircleAlert,
                tone: 'text-red-600',
                label: 'A check failed',
            };
        case 'errored':
            return {
                icon: CircleAlert,
                tone: 'text-red-600',
                label: 'Checks could not run',
            };
        default:
            return null;
    }
});
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col" data-test="change-thread">
        <div class="flex h-11 shrink-0 items-center gap-1 border-b px-2">
            <Button
                variant="ghost"
                size="sm"
                class="h-11 gap-1 px-2 text-muted-foreground select-none sm:h-8"
                as-child
            >
                <Link
                    :href="showProject(change.project.id)"
                    :only="['change']"
                    preserve-state
                    data-test="thread-back"
                >
                    <ArrowLeft class="size-4" /> All changes
                </Link>
            </Button>
            <Button
                variant="ghost"
                size="sm"
                class="ml-auto h-11 gap-1 px-2 text-muted-foreground select-none sm:h-8"
                as-child
            >
                <Link
                    :href="showFeatureRequest(request.id)"
                    title="Everything about this change, for your developer"
                    data-test="thread-details"
                >
                    <FileCode2 class="size-4" /> Details
                </Link>
            </Button>
        </div>

        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
            <!-- What you asked -->
            <div class="flex justify-end">
                <p
                    class="max-w-[85%] rounded-2xl rounded-br-md bg-muted px-3.5 py-2.5 text-sm break-words whitespace-pre-line"
                >
                    {{ request.prompt }}
                </p>
            </div>

            <!-- What the builder said and did -->
            <div class="flex gap-2.5">
                <span
                    class="grid size-7 shrink-0 place-items-center rounded-full bg-muted"
                    aria-hidden="true"
                >
                    <Sparkles class="size-3.5" />
                </span>
                <div class="min-w-0 flex-1 space-y-3 pt-0.5 text-sm">
                    <p v-if="run?.plan" class="leading-relaxed">
                        {{ run.plan.summary }}
                    </p>

                    <div
                        v-if="working"
                        class="flex items-center gap-2 text-muted-foreground"
                        data-test="thread-working"
                    >
                        <LoaderCircle class="size-4 animate-spin" />
                        <span>{{ steps[run?.status ?? 'queued'] }}…</span>
                        <Form
                            v-if="run && run.status !== 'cancelling'"
                            v-bind="
                                RunCancellationController.store.form(run.id)
                            "
                            :options="{ preserveScroll: true }"
                            class="ml-auto"
                            v-slot="{ processing }"
                        >
                            <Button
                                variant="ghost"
                                size="sm"
                                :disabled="processing"
                                class="h-11 select-none sm:h-7"
                                data-test="cancel-run-button"
                            >
                                Stop
                            </Button>
                        </Form>
                    </div>

                    <!-- A question to answer before going on -->
                    <div
                        v-if="run?.question"
                        class="space-y-3 rounded-xl border p-3"
                        data-test="question"
                    >
                        <div>
                            <p class="font-medium">{{ run.question.text }}</p>
                            <p
                                v-if="run.question.why"
                                class="mt-0.5 text-xs text-muted-foreground"
                            >
                                {{ run.question.why }}
                            </p>
                        </div>
                        <div class="grid gap-1.5">
                            <Form
                                v-for="option in run.question.options"
                                :key="option"
                                v-bind="
                                    FeatureRequestAnswerController.store.form(
                                        request.id,
                                    )
                                "
                                :options="{ preserveScroll: true }"
                                v-slot="{ processing }"
                            >
                                <input
                                    type="hidden"
                                    name="answer"
                                    :value="option"
                                />
                                <input
                                    type="hidden"
                                    name="more_questions"
                                    :value="moreQuestions ? 1 : 0"
                                />
                                <button
                                    :disabled="processing"
                                    :class="[
                                        'flex min-h-11 w-full items-center gap-2 rounded-lg border px-3 text-left select-none hover:bg-muted sm:min-h-9',
                                        option === run.question.recommended &&
                                            'border-foreground/40',
                                    ]"
                                    :data-test="`answer-${option}`"
                                >
                                    <span class="min-w-0 flex-1">{{
                                        option
                                    }}</span>
                                    <span
                                        v-if="
                                            option === run.question.recommended
                                        "
                                        class="text-xs text-muted-foreground"
                                        >Suggested</span
                                    >
                                </button>
                            </Form>
                        </div>
                        <div class="flex items-center gap-1">
                            <Form
                                v-bind="
                                    FeatureRequestAnswerController.store.form(
                                        request.id,
                                    )
                                "
                                :options="{ preserveScroll: true }"
                                v-slot="{ processing, errors }"
                            >
                                <input
                                    type="hidden"
                                    name="more_questions"
                                    :value="moreQuestions ? 1 : 0"
                                />
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :disabled="processing"
                                    class="-ml-2 h-11 select-none sm:h-7"
                                    data-test="answer-you-decide"
                                >
                                    You decide
                                </Button>
                                <InputError :message="errors.answer" />
                            </Form>
                            <label
                                class="ml-auto flex min-h-11 items-center gap-1.5 text-xs text-muted-foreground select-none sm:min-h-7"
                            >
                                <input
                                    v-model="moreQuestions"
                                    type="checkbox"
                                    class="accent-foreground"
                                    data-test="ask-more-questions"
                                />
                                Ask me more
                            </label>
                        </div>
                    </div>

                    <!-- Could not finish -->
                    <div
                        v-if="failed"
                        class="space-y-2 rounded-xl border border-red-500/30 bg-red-500/5 p-3"
                        data-test="thread-failed"
                    >
                        <p class="flex items-center gap-2 font-medium">
                            <CircleAlert class="size-4 text-red-600" />
                            I couldn't finish this
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Nothing in your app changed.
                        </p>
                        <Form
                            v-if="request.can_retry"
                            v-bind="
                                FeatureRequestRetryController.store.form(
                                    request.id,
                                )
                            "
                            v-slot="{ errors, processing }"
                        >
                            <Button
                                size="sm"
                                :disabled="processing"
                                class="h-11 select-none sm:h-8"
                                data-test="retry-button"
                            >
                                Try again
                            </Button>
                            <InputError
                                :message="errors.retry ?? errors.step"
                            />
                        </Form>
                    </div>

                    <p
                        v-if="run?.status === 'cancelled'"
                        class="text-muted-foreground"
                    >
                        You stopped this. Nothing in your app changed.
                    </p>

                    <!-- What changed, before and now -->
                    <ul
                        v-if="asked.length > 0"
                        class="space-y-2.5"
                        data-test="run-review"
                    >
                        <li
                            v-for="(item, index) in asked"
                            :key="index"
                            class="space-y-0.5"
                        >
                            <p class="flex items-start gap-2 font-medium">
                                <Check
                                    class="mt-0.5 size-4 shrink-0 text-green-600"
                                />
                                {{ item.behavior }}
                            </p>
                            <p
                                class="line-clamp-2 pl-6 text-xs text-muted-foreground"
                                :title="`Before: ${item.before}`"
                            >
                                {{ item.now }}
                            </p>
                        </li>
                    </ul>

                    <div
                        v-if="unexpected.length > 0"
                        class="space-y-2 rounded-xl border border-amber-500/40 bg-amber-500/5 p-3"
                        data-test="review-unexpected"
                    >
                        <p class="flex items-center gap-2 font-medium">
                            <CircleAlert class="size-4 text-amber-500" />
                            I also changed something you didn't ask for
                        </p>
                        <ul class="space-y-1 pl-6 text-xs">
                            <li
                                v-for="(item, index) in unexpected"
                                :key="index"
                            >
                                {{ item.behavior }}
                            </li>
                        </ul>
                    </div>

                    <Collapsible
                        v-if="run?.plan && run.plan.assumptions.length > 0"
                        data-test="decisions"
                    >
                        <CollapsibleTrigger
                            class="group flex min-h-11 items-center gap-1 text-xs text-muted-foreground select-none hover:text-foreground sm:min-h-6"
                        >
                            <ChevronRight
                                class="size-3.5 transition-transform group-data-[state=open]:rotate-90"
                            />
                            I decided {{ run.plan.assumptions.length }}
                            {{
                                run.plan.assumptions.length === 1
                                    ? 'thing'
                                    : 'things'
                            }}
                            for you
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <ul
                                class="mt-1 list-disc space-y-1 pl-9 text-xs text-muted-foreground"
                            >
                                <li
                                    v-for="(assumption, index) in run.plan
                                        .assumptions"
                                    :key="index"
                                >
                                    {{ assumption }}
                                </li>
                            </ul>
                        </CollapsibleContent>
                    </Collapsible>

                    <div
                        v-if="request.status === 'generated'"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
                    >
                        <span
                            v-if="checking"
                            class="flex items-center gap-1.5"
                            data-test="verification-status"
                        >
                            <LoaderCircle class="size-3.5 animate-spin" />
                            Running the checks…
                        </span>
                        <span
                            v-else-if="checks"
                            class="flex items-center gap-1.5"
                            data-test="verification-status"
                        >
                            <component
                                :is="checks.icon"
                                :class="['size-3.5', checks.tone]"
                            />
                            {{ checks.label }}
                        </span>
                        <Form
                            v-if="!checking && !request.commit_sha"
                            v-bind="
                                FeatureRequestVerificationController.store.form(
                                    request.id,
                                )
                            "
                            :options="{ preserveScroll: true }"
                            v-slot="{ processing }"
                        >
                            <button
                                :disabled="processing"
                                class="min-h-11 underline-offset-2 select-none hover:text-foreground hover:underline sm:min-h-6"
                                data-test="run-verification-button"
                            >
                                {{
                                    change.verification
                                        ? 'Check again'
                                        : 'Run the checks'
                                }}
                            </button>
                        </Form>
                    </div>

                    <!-- Kept or undone -->
                    <div
                        v-if="request.commit_sha"
                        class="flex items-center gap-2"
                        data-test="change-decision"
                    >
                        <template v-if="request.reverted_at">
                            <Undo2 class="size-4 text-muted-foreground" />
                            <span class="text-muted-foreground"
                                >You undid this change.</span
                            >
                        </template>
                        <template v-else>
                            <CircleCheck class="size-4 text-green-600" />
                            <span>Kept. It's part of your app.</span>
                            <Form
                                v-bind="
                                    FeatureRequestReversionController.store.form(
                                        request.id,
                                    )
                                "
                                :options="{ preserveScroll: true }"
                                class="ml-auto"
                                v-slot="{ processing, errors }"
                            >
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :disabled="processing"
                                    class="h-11 gap-1 select-none sm:h-7"
                                    data-test="revert-change-button"
                                >
                                    <Undo2 class="size-3.5" /> Undo
                                </Button>
                                <InputError :message="errors.change" />
                            </Form>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- The decision stays in reach at the bottom -->
        <div
            v-if="request.can_accept"
            class="space-y-2 border-t p-3"
            data-test="change-decision"
        >
            <p
                v-if="change.preview?.status === 'starting'"
                class="flex items-center gap-1.5 text-xs text-muted-foreground"
            >
                <LoaderCircle class="size-3.5 animate-spin" /> Getting a copy
                ready to try…
            </p>
            <div class="flex gap-2">
                <Button
                    v-if="change.preview?.status === 'ready'"
                    variant="outline"
                    class="h-11 flex-1 gap-1.5 select-none sm:h-9"
                    as-child
                >
                    <a
                        :href="PreviewController.show.url(change.preview.id)"
                        target="_blank"
                        rel="noopener noreferrer"
                        data-test="open-preview-link"
                    >
                        Try it <ExternalLink class="size-3.5" />
                    </a>
                </Button>
                <Form
                    v-else-if="change.preview?.status !== 'starting'"
                    v-bind="
                        FeatureRequestPreviewController.store.form(request.id)
                    "
                    :options="{ preserveScroll: true }"
                    class="flex-1"
                    v-slot="{ processing, errors }"
                >
                    <Button
                        variant="outline"
                        :disabled="processing"
                        class="h-11 w-full select-none sm:h-9"
                        data-test="start-preview-button"
                    >
                        Try it first
                    </Button>
                    <InputError :message="errors.preview" />
                </Form>
                <Form
                    v-bind="
                        FeatureRequestAcceptanceController.store.form(
                            request.id,
                        )
                    "
                    :options="{ preserveScroll: true }"
                    class="flex-1"
                    v-slot="{ processing, errors }"
                >
                    <Button
                        :disabled="processing"
                        class="h-11 w-full select-none sm:h-9"
                        data-test="accept-change-button"
                    >
                        Keep it
                    </Button>
                    <InputError :message="errors.change" />
                </Form>
            </div>
        </div>
    </div>
</template>
