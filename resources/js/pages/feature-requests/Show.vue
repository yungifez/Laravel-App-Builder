<script setup lang="ts">
import { Form, Head, Link, setLayoutProps, usePoll } from '@inertiajs/vue3';
import {
    Check,
    ChevronDown,
    ChevronRight,
    CircleDashed,
    ExternalLink,
    Target,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FeatureRequestAcceptanceController from '@/actions/App/Http/Controllers/FeatureRequestAcceptanceController';
import FeatureRequestAnswerController from '@/actions/App/Http/Controllers/FeatureRequestAnswerController';
import FeatureRequestPreviewController from '@/actions/App/Http/Controllers/FeatureRequestPreviewController';
import FeatureRequestKeepTryingController from '@/actions/App/Http/Controllers/FeatureRequestKeepTryingController';
import FeatureRequestRetryController from '@/actions/App/Http/Controllers/FeatureRequestRetryController';
import FeatureRequestReversionController from '@/actions/App/Http/Controllers/FeatureRequestReversionController';
import FeatureRequestStepChangeController from '@/actions/App/Http/Controllers/FeatureRequestStepChangeController';
import FeatureRequestVerificationController from '@/actions/App/Http/Controllers/FeatureRequestVerificationController';
import PreviewController from '@/actions/App/Http/Controllers/PreviewController';
import RunCancellationController from '@/actions/App/Http/Controllers/RunCancellationController';
import ChangeProof from '@/components/ChangeProof.vue';
import MessageImages from '@/components/MessageImages.vue';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import { when } from '@/lib/when';
import { show as showFeatureRequest } from '@/routes/feature-requests';
import { index, show as showProject } from '@/routes/projects';
import type {
    ChangedArea,
    ChangeSection,
    RunReview,
    FeatureRequestDetail,
    FeatureRequestSummary,
    Preview,
    Run,
    ProofLine,
    Verification,
    VerificationResult,
} from '@/types';

const props = defineProps<{
    project: { id: string; name: string };
    featureRequest: FeatureRequestDetail;
    parent: { id: string; prompt: string } | null;
    followUps: FeatureRequestSummary[];
    verification: Verification | null;
    proof: ProofLine[];
    run: Run | null;
    preview: Preview | null;
}>();

const selectedStepKey = ref<string | null>(null);
const moreQuestions = ref(false);

// Inertia reuses this component when navigating from one request to another
// (for example to a follow-up), so refresh the breadcrumbs and selection.
watch(
    () => props.featureRequest.id,
    (id) => {
        selectedStepKey.value = null;
        setLayoutProps({
            breadcrumbs: [
                { title: 'Your apps', href: index() },
                // Back returns to this change in the workspace, not the list.
                {
                    title: props.project.name,
                    href: showProject(props.project.id, {
                        query: { change: id },
                    }),
                },
                { title: 'Change details', href: showFeatureRequest(id) },
            ],
        });
    },
    { immediate: true },
);

const { start, stop } = usePoll(
    1500,
    {
        only: [
            'featureRequest',
            'followUps',
            'verification',
            'proof',
            'run',
            'preview',
        ],
    },
    { autoStart: false },
);

const verificationInProgress = computed(
    () =>
        props.verification?.status === 'queued' ||
        props.verification?.status === 'running',
);

const runInProgress = computed(
    () =>
        props.run !== null &&
        [
            'queued',
            'planning',
            'implementing',
            'verifying',
            'reviewing',
            'cancelling',
        ].includes(props.run.status),
);

// The side column holds the owner's next step. With nothing to do there,
// it is left out, so no empty lines show beside the chat.
const asideShown = computed(
    () =>
        props.featureRequest.can_accept ||
        props.featureRequest.commit_sha !== null ||
        props.featureRequest.status === 'generated' ||
        (runInProgress.value && props.run?.status !== 'cancelling'),
);

watch(
    () =>
        runInProgress.value ||
        props.preview?.status === 'starting' ||
        props.featureRequest.status === 'generating' ||
        verificationInProgress.value,
    (busy) => (busy ? start() : stop()),
    { immediate: true },
);

const runLabels: Record<Run['status'], string> = {
    queued: 'Waiting to start',
    planning: 'Working out what to change',
    implementing: 'Making the change',
    verifying: 'Running checks',
    reviewing: 'Looking over what changed',
    completed: 'Ready for you',
    needs_user_decision: 'Needs your decision',
    cancelling: 'Stopping',
    cancelled: 'Stopped',
    failed: 'Could not finish',
};

const changeSections: {
    key: ChangeSection;
    title: string;
    description: string;
}[] = [
    {
        key: 'requested',
        title: 'What you asked for',
        description: 'Changes in the parts of the app this request is about.',
    },
    {
        key: 'may_also_affect',
        title: 'This may also touch',
        description:
            'Parts of your app that often change together with the ones you asked about.',
    },
    {
        key: 'unexpected',
        title: "Something I didn't expect to change",
        description:
            'Your request was not about these parts of your app. Check that you want these changes.',
    },
    {
        key: 'other',
        title: 'Other changes',
        description: '',
    },
];

function evidenceLabel(item: RunReview['preserved'][number]): string {
    switch (item.evidence) {
        case 'verified':
            return 'checked by a test';
        case 'untouched':
            return 'not touched by this change';
        default:
            return 'not checked yet';
    }
}

function verifyLabel(item: RunReview['verified'][number]): string {
    switch (item.evidence) {
        case 'tested':
            return 'checked by a test';
        case 'not_run':
            return 'a test covers it, but the checks did not pass';
        case 'not_run_by_checks':
            return "a test covers it, but my checks don't run that test";
        case 'claimed':
            return "a test covers it, but I couldn't confirm it ran";
        default:
            return 'not checked yet';
    }
}

// The state in one plain line. Colour separates the states; it never
// borrows the accent, which is kept for the action to take.
const stateLabel = computed(() => {
    if (props.featureRequest.reverted_at) {
        return 'Undone';
    }

    if (props.featureRequest.commit_sha) {
        return 'Kept';
    }

    if (props.run?.question) {
        return 'Waiting for your answer';
    }

    // A question gets an answer, not a change to keep.
    if (props.run?.status === 'completed' && props.run.plan?.answer) {
        return 'Answered';
    }

    if (props.run) {
        return runLabels[props.run.status];
    }

    return {
        generating: 'Working on it',
        generated: 'Ready for you',
        answered: 'Answered',
        failed: 'Could not finish',
        cancelled: 'Stopped',
    }[props.featureRequest.status];
});

const stateDot = computed(() => {
    if (props.featureRequest.commit_sha && !props.featureRequest.reverted_at) {
        return 'bg-green-600';
    }

    if (props.run?.question) {
        return 'bg-amber-500';
    }

    if (props.run?.status === 'completed' && props.run.plan?.answer) {
        return 'bg-muted-foreground';
    }

    const status = props.run?.status ?? props.featureRequest.status;

    if (['failed', 'needs_user_decision'].includes(status)) {
        return 'bg-red-600';
    }

    if (['completed', 'generated'].includes(status)) {
        return 'bg-green-600';
    }

    return runInProgress.value || status === 'generating'
        ? 'bg-amber-500'
        : 'bg-muted-foreground';
});

// What stays the same: once the change is reviewed, with how each was
// checked; before that, the promise from the plan.
const keptSame = computed(() => {
    const review = props.run?.review;

    if (review && review.preserved.length > 0) {
        return review.preserved.map((item) => ({
            statement: item.statement,
            checked: item.evidence !== 'not_checked',
            label: evidenceLabel(item),
        }));
    }

    return (props.run?.plan?.preserve ?? []).map((statement) => ({
        statement,
        checked: false,
        label: null,
    }));
});

const doneWhen = computed(() => {
    const review = props.run?.review;

    if (review && review.verified.length > 0) {
        return review.verified.map((item) => ({
            criterion: item.criterion,
            checked: item.evidence === 'tested',
            label: verifyLabel(item),
        }));
    }

    return (props.run?.plan?.acceptance_criteria ?? []).map((criterion) => ({
        criterion,
        checked: false,
        label: null,
    }));
});

// When nothing in a list has been checked yet, say so once under the
// heading instead of after every line.
const keptSameChecked = computed(() =>
    keptSame.value.some((item) => item.checked),
);
const doneWhenChecked = computed(() =>
    doneWhen.value.some((item) => item.checked),
);

function changesIn(section: ChangeSection) {
    return (props.run?.review?.changes ?? []).filter(
        (change) => change.section === section,
    );
}

function areasIn(section: ChangeSection): ChangedArea[] {
    return section === 'other' ? [] : (props.run?.review?.areas[section] ?? []);
}

const previewLabels: Record<Preview['status'], string> = {
    starting: 'Getting ready',
    ready: 'Ready',
    failed: 'Could not start',
    stopped: 'Stopped',
};

const verificationLabels: Record<Verification['status'], string> = {
    queued: 'Waiting to start',
    running: 'Running',
    passed: 'All passed',
    failed: 'Something failed',
    errored: 'Could not run',
    unverified: 'Passed, with gaps',
};

const outcomeMarks: Record<
    VerificationResult['outcome'],
    { mark: string; class: string; label: string }
> = {
    passed: {
        mark: '✓',
        class: 'text-green-700 dark:text-green-400',
        label: 'Passed',
    },
    failed: {
        mark: '✗',
        class: 'text-red-700 dark:text-red-400',
        label: 'Failed',
    },
    errored: {
        mark: '!',
        class: 'text-red-700 dark:text-red-400',
        label: 'Could not run',
    },
    skipped: { mark: '–', class: 'text-muted-foreground', label: 'Skipped' },
    not_applicable: {
        mark: '∅',
        class: 'text-muted-foreground',
        label: 'Not applicable',
    },
};

function resultTiming(result: VerificationResult): string {
    if (result.timed_out) {
        return 'timed out';
    }

    if (result.outcome === 'skipped' || result.outcome === 'not_applicable') {
        return outcomeMarks[result.outcome].label.toLowerCase();
    }

    return seconds(result.duration_ms);
}

function seconds(durationMs: number): string {
    return `${(durationMs / 1000).toFixed(1)} s`;
}

const selectedStep = computed(
    () =>
        props.featureRequest.steps.find(
            (step) => step.key === selectedStepKey.value,
        ) ?? null,
);

const totals = computed(() =>
    props.featureRequest.files.reduce(
        (sum, file) => ({
            additions: sum.additions + file.additions,
            deletions: sum.deletions + file.deletions,
        }),
        { additions: 0, deletions: 0 },
    ),
);

function lineClass(line: string): string {
    if (line.startsWith('+') && !line.startsWith('+++')) {
        return 'bg-green-500/10 text-green-700 dark:text-green-400';
    }

    if (line.startsWith('-') && !line.startsWith('---')) {
        return 'bg-red-500/10 text-red-700 dark:text-red-400';
    }

    if (line.startsWith('@@')) {
        return 'text-muted-foreground';
    }

    return '';
}
</script>
<template>
    <Head :title="featureRequest.background ?? featureRequest.prompt" />

    <div
        class="mx-auto flex max-w-6xl flex-col gap-12 px-4 pt-10 pb-16 sm:px-8"
    >
        <header class="max-w-3xl space-y-4">
            <h1
                class="text-2xl leading-snug font-semibold tracking-[-0.025em] break-words"
            >
                {{ featureRequest.background ?? featureRequest.prompt }}
            </h1>
            <MessageImages :images="featureRequest.images" align="start" />
            <p
                class="flex flex-wrap items-center gap-x-2 gap-y-1 text-muted-foreground"
                data-test="run-status"
            >
                <span
                    :class="['size-2 shrink-0 rounded-full', stateDot]"
                    aria-hidden="true"
                />
                <span class="text-foreground">{{ stateLabel }}</span>
                <template
                    v-if="
                        featureRequest.reverted_at || featureRequest.accepted_at
                    "
                >
                    <span aria-hidden="true">·</span>
                    {{
                        when(
                            (featureRequest.reverted_at ??
                                featureRequest.accepted_at)!,
                        )
                    }}
                </template>
            </p>
            <p v-if="parent" class="text-sm text-muted-foreground">
                Follow-up to
                <Link
                    :href="showFeatureRequest(parent.id)"
                    class="underline underline-offset-4 hover:text-foreground"
                >
                    “{{ parent.prompt }}”
                </Link>
                <template v-if="featureRequest.target_step">
                    — changes “{{ featureRequest.target_step.label }}”
                </template>
            </p>
        </header>

        <p
            v-if="featureRequest.status === 'generating' && !run"
            class="text-muted-foreground"
            data-test="generating"
        >
            Working on it…
        </p>

        <Alert
            v-if="featureRequest.status === 'failed' && !run"
            variant="destructive"
            class="max-w-2xl"
        >
            <AlertTitle>This change could not be made</AlertTitle>
            <AlertDescription>{{ featureRequest.error }}</AlertDescription>
        </Alert>

        <Alert
            v-if="
                run?.error &&
                (run.status === 'failed' ||
                    run.status === 'needs_user_decision')
            "
            variant="destructive"
            class="max-w-2xl"
        >
            <AlertTitle>{{
                run.status === 'failed'
                    ? 'This change could not be finished'
                    : 'I stopped before finishing'
            }}</AlertTitle>
            <AlertDescription>
                <!-- The reason already says whose fault it was and what to
                     do next, so it is the text itself. -->
                <p data-test="run-failed-reason">{{ run.error }}</p>
            </AlertDescription>
        </Alert>

        <div
            v-if="featureRequest.can_retry"
            class="-mt-6 flex max-w-2xl flex-wrap items-center gap-3"
            data-test="retry"
        >
            <Form
                v-if="featureRequest.can_keep_trying"
                v-bind="
                    FeatureRequestKeepTryingController.store.form(
                        featureRequest.id,
                    )
                "
                v-slot="{ errors, processing }"
            >
                <Button
                    :disabled="processing"
                    class="h-11 select-none sm:h-9"
                    title="Go on from the work so far and keep fixing it"
                    data-test="keep-trying-button"
                >
                    Keep trying
                </Button>
                <InputError class="mt-2" :message="errors.keep_trying" />
            </Form>
            <Form
                v-bind="
                    FeatureRequestRetryController.store.form(featureRequest.id)
                "
                v-slot="{ errors, processing }"
            >
                <Button
                    :variant="
                        featureRequest.can_keep_trying ? 'outline' : 'default'
                    "
                    :disabled="processing"
                    class="h-11 select-none sm:h-9"
                    data-test="retry-button"
                >
                    {{
                        featureRequest.can_keep_trying
                            ? 'Start over'
                            : 'Try again'
                    }}
                </Button>
                <InputError
                    class="mt-2"
                    :message="errors.retry ?? errors.step"
                />
            </Form>
            <Button variant="ghost" class="h-11 sm:h-9" as-child>
                <Link :href="showProject(project.id)">Ask in other words</Link>
            </Button>
        </div>

        <!-- The one thing the page needs from you, so it gets the only
             filled panel on the page. -->
        <section
            v-if="run?.question"
            class="max-w-3xl space-y-5 rounded-2xl bg-muted/50 p-5 sm:p-6"
            data-test="question"
        >
            <div class="space-y-2">
                <h2
                    class="text-xl font-semibold tracking-[-0.02em] break-words"
                >
                    {{ run.question.text }}
                </h2>
                <p v-if="run.question.why" class="text-muted-foreground">
                    {{ run.question.why }}
                </p>
                <p
                    v-if="run.question.reversible === false"
                    class="text-sm text-muted-foreground"
                    data-test="question-lasting"
                >
                    This is hard to change later, so I am asking you.
                </p>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                <Form
                    v-for="option in run.question.options"
                    :key="option"
                    v-bind="
                        FeatureRequestAnswerController.store.form(
                            featureRequest.id,
                        )
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                >
                    <input type="hidden" name="answer" :value="option" />
                    <input
                        type="hidden"
                        name="more_questions"
                        :value="moreQuestions ? 1 : 0"
                    />
                    <Button
                        :variant="
                            option === run.question.recommended
                                ? 'default'
                                : 'outline'
                        "
                        :disabled="processing"
                        class="h-11 w-full justify-start select-none sm:h-9 sm:w-auto"
                        :data-test="`answer-${option}`"
                    >
                        {{ option }}
                    </Button>
                </Form>
            </div>

            <div
                class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
            >
                <span v-if="run.question.recommended"
                    >I would pick “{{ run.question.recommended }}”.</span
                >
                <Form
                    v-bind="
                        FeatureRequestAnswerController.store.form(
                            featureRequest.id,
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
                        class="h-11 select-none sm:h-8"
                        data-test="answer-you-decide"
                    >
                        You decide
                    </Button>
                    <InputError :message="errors.answer" />
                </Form>
                <Button
                    v-if="!moreQuestions"
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="h-11 select-none sm:h-8"
                    data-test="ask-more-questions"
                    @click="moreQuestions = true"
                >
                    Ask me more questions
                </Button>
                <span v-else>
                    After this, I'll ask about the other things I'm unsure of,
                    one at a time.
                </span>
            </div>
        </section>

        <!-- The story of the change on the left; what to do about it on the
             right, where it stays in view. On a phone the decision comes
             straight after what changed, before the finer detail. -->
        <div
            class="grid gap-x-16 gap-y-12 lg:grid-cols-[minmax(0,1fr)_18rem] [&>*]:min-w-0"
        >
            <div class="max-w-3xl space-y-12">
                <p
                    v-if="run && runInProgress && !run.plan"
                    class="text-muted-foreground"
                >
                    {{ runLabels[run.status] }}…
                </p>

                <section
                    v-if="run?.plan"
                    class="space-y-3"
                    data-test="run-plan"
                >
                    <p
                        v-if="run.plan.answer"
                        class="text-lg leading-relaxed whitespace-pre-line"
                        data-test="run-answer"
                    >
                        {{ run.plan.answer }}
                    </p>
                    <p v-else class="text-lg leading-relaxed">
                        {{ run.plan.summary }}
                    </p>
                    <!-- How the change serves the owner's goal -->
                    <p
                        v-if="run.plan.goal && !run.plan.answer"
                        class="flex gap-1.5 text-muted-foreground"
                        data-test="run-goal"
                    >
                        <Target
                            class="mt-1 size-4 shrink-0"
                            aria-label="Your goal"
                        />
                        {{ run.plan.goal }}
                    </p>
                    <p
                        v-if="
                            !run.review &&
                            !run.plan.answer &&
                            run.plan.current_behavior &&
                            run.plan.current_behavior !== 'New'
                        "
                        class="text-muted-foreground"
                    >
                        Now: {{ run.plan.current_behavior }}
                    </p>
                    <p v-if="runInProgress" class="text-muted-foreground">
                        {{ runLabels[run.status] }}…
                    </p>
                </section>

                <section
                    v-if="run?.review"
                    class="space-y-10"
                    data-test="run-review"
                >
                    <template
                        v-for="section in changeSections"
                        :key="section.key"
                    >
                        <div
                            v-if="
                                changesIn(section.key).length > 0 ||
                                areasIn(section.key).length > 0
                            "
                            class="space-y-3"
                            :data-test="`review-${section.key}`"
                        >
                            <Alert
                                v-if="section.key === 'unexpected'"
                                variant="destructive"
                            >
                                <AlertTitle>{{ section.title }}</AlertTitle>
                                <AlertDescription>{{
                                    section.description
                                }}</AlertDescription>
                            </Alert>
                            <template v-else>
                                <h2
                                    class="text-xl font-semibold tracking-[-0.02em]"
                                >
                                    {{
                                        section.key === 'requested'
                                            ? 'What changes'
                                            : section.title
                                    }}
                                </h2>
                                <p
                                    v-if="
                                        section.key === 'may_also_affect' &&
                                        section.description
                                    "
                                    class="text-muted-foreground"
                                >
                                    {{ section.description }}
                                </p>
                            </template>
                            <ul class="divide-y border-y">
                                <li
                                    v-for="(change, index) in changesIn(
                                        section.key,
                                    )"
                                    :key="index"
                                    class="space-y-4 py-6"
                                >
                                    <p class="font-medium">
                                        {{ change.behavior }}
                                        <span
                                            v-if="change.area_name"
                                            class="font-normal text-muted-foreground"
                                            >· {{ change.area_name }}</span
                                        >
                                    </p>
                                    <dl class="grid gap-4 sm:grid-cols-2">
                                        <div class="space-y-1">
                                            <dt
                                                class="text-sm text-muted-foreground"
                                            >
                                                Before
                                            </dt>
                                            <dd class="text-muted-foreground">
                                                {{ change.before }}
                                            </dd>
                                        </div>
                                        <div class="space-y-1">
                                            <dt
                                                class="text-sm text-muted-foreground"
                                            >
                                                Now
                                            </dt>
                                            <dd>{{ change.now }}</dd>
                                        </div>
                                    </dl>
                                </li>
                            </ul>
                            <p
                                v-if="
                                    changesIn(section.key).length === 0 &&
                                    areasIn(section.key).length > 0
                                "
                                class="text-muted-foreground"
                            >
                                {{
                                    areasIn(section.key)
                                        .map((area) => area.name)
                                        .join(', ')
                                }}
                            </p>
                        </div>
                    </template>
                </section>
            </div>

            <aside
                v-if="asideShown"
                class="lg:col-start-2 lg:row-span-2 lg:row-start-1"
            >
                <div class="divide-y border-y lg:sticky lg:top-8">
                    <div
                        v-if="
                            featureRequest.can_accept ||
                            featureRequest.commit_sha
                        "
                        class="space-y-3 py-5"
                        data-test="change-decision"
                    >
                        <p
                            v-if="featureRequest.reverted_at"
                            class="text-muted-foreground"
                        >
                            You undid this change. Your app works as it did
                            before.
                        </p>
                        <Form
                            v-else-if="featureRequest.commit_sha"
                            v-bind="
                                FeatureRequestReversionController.store.form(
                                    featureRequest.id,
                                )
                            "
                            v-slot="{ processing, errors }"
                            class="space-y-3"
                        >
                            <p class="text-muted-foreground">
                                This change is part of your app.
                            </p>
                            <Button
                                variant="outline"
                                :disabled="processing"
                                class="h-11 w-full select-none sm:h-9"
                                data-test="revert-change-button"
                            >
                                Undo this change
                            </Button>
                            <InputError :message="errors.change" />
                        </Form>
                        <Form
                            v-else
                            v-bind="
                                FeatureRequestAcceptanceController.store.form(
                                    featureRequest.id,
                                )
                            "
                            v-slot="{ processing, errors }"
                            class="space-y-3"
                        >
                            <Button
                                :disabled="processing"
                                class="h-11 w-full select-none"
                                data-test="accept-change-button"
                            >
                                Keep this change
                            </Button>
                            <p class="text-sm text-muted-foreground">
                                Your next change starts from here. You can undo
                                it later.
                            </p>
                            <InputError :message="errors.change" />
                        </Form>
                    </div>

                    <Form
                        v-if="
                            run && runInProgress && run.status !== 'cancelling'
                        "
                        v-bind="RunCancellationController.store.form(run.id)"
                        v-slot="{ processing }"
                        class="py-5"
                    >
                        <Button
                            variant="outline"
                            :disabled="processing"
                            class="h-11 w-full select-none sm:h-9"
                            data-test="cancel-run-button"
                        >
                            Stop working on this
                        </Button>
                    </Form>

                    <section
                        v-if="featureRequest.status === 'generated'"
                        class="space-y-3 py-5"
                        data-test="preview"
                    >
                        <div class="flex items-baseline justify-between gap-3">
                            <h2 class="font-medium">Try it</h2>
                            <span
                                v-if="preview"
                                class="text-sm text-muted-foreground"
                                data-test="preview-status"
                                >{{ previewLabels[preview.status] }}</span
                            >
                        </div>
                        <p class="text-sm text-muted-foreground">
                            {{
                                preview?.status === 'starting'
                                    ? 'Getting your app ready. This can take a few minutes…'
                                    : 'Open your app with this change, without changing the real one.'
                            }}
                        </p>

                        <!-- A copy stopped for sitting idle did start; only
                             one that failed says so. -->
                        <Alert
                            v-if="preview?.status === 'failed' && preview.error"
                            variant="destructive"
                            data-test="preview-error"
                        >
                            <AlertTitle>Your app could not start</AlertTitle>
                            <AlertDescription class="whitespace-pre-wrap">{{
                                preview.error
                            }}</AlertDescription>
                        </Alert>

                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                v-if="preview?.status === 'ready'"
                                class="h-11 select-none sm:h-9"
                                as-child
                            >
                                <a
                                    :href="
                                        PreviewController.show.url(preview.id)
                                    "
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    data-test="open-preview-link"
                                    >Open <ExternalLink class="size-3.5"
                                /></a>
                            </Button>

                            <Form
                                v-if="preview?.status !== 'starting'"
                                v-bind="
                                    FeatureRequestPreviewController.store.form(
                                        featureRequest.id,
                                    )
                                "
                                v-slot="{ errors, processing }"
                            >
                                <Button
                                    variant="outline"
                                    :disabled="processing"
                                    class="h-11 select-none sm:h-9"
                                    data-test="start-preview-button"
                                >
                                    {{
                                        preview?.status === 'ready'
                                            ? 'Restart'
                                            : 'Try it'
                                    }}
                                </Button>
                                <InputError
                                    class="mt-2"
                                    :message="errors.preview"
                                />
                            </Form>

                            <Form
                                v-if="
                                    preview &&
                                    (preview.status === 'ready' ||
                                        preview.status === 'starting')
                                "
                                v-bind="
                                    PreviewController.destroy.form(preview.id)
                                "
                                v-slot="{ processing }"
                            >
                                <Button
                                    variant="ghost"
                                    :disabled="processing"
                                    class="h-11 select-none sm:h-9"
                                    data-test="stop-preview-button"
                                >
                                    Stop
                                </Button>
                            </Form>
                        </div>
                    </section>

                    <section
                        v-if="featureRequest.status === 'generated'"
                        class="space-y-3 py-5"
                        data-test="verification"
                    >
                        <div class="flex items-baseline justify-between gap-3">
                            <h2 class="font-medium">Checks</h2>
                            <span
                                v-if="verification"
                                :class="[
                                    'text-sm',
                                    verification.status === 'failed' ||
                                    verification.status === 'errored'
                                        ? 'text-destructive'
                                        : verification.status === 'passed'
                                          ? 'text-green-700 dark:text-green-400'
                                          : 'text-muted-foreground',
                                ]"
                                data-test="verification-status"
                                >{{
                                    verificationLabels[verification.status]
                                }}</span
                            >
                        </div>
                        <p class="text-sm text-muted-foreground">
                            <template v-if="verificationInProgress">
                                Running the checks. This can take a few minutes…
                            </template>
                            <template v-else-if="!verification">
                                I try the change on a fresh copy of your app and
                                run its checks.
                            </template>
                        </p>

                        <Alert v-if="verification?.error" variant="destructive">
                            <AlertTitle>The checks could not finish</AlertTitle>
                            <AlertDescription>{{
                                verification.error
                            }}</AlertDescription>
                        </Alert>

                        <ChangeProof :proof="proof" />

                        <Form
                            v-if="!verificationInProgress"
                            v-bind="
                                FeatureRequestVerificationController.store.form(
                                    featureRequest.id,
                                )
                            "
                            v-slot="{ errors, processing }"
                        >
                            <Button
                                :variant="verification ? 'outline' : 'default'"
                                :disabled="processing"
                                class="h-11 select-none sm:h-9"
                                data-test="run-verification-button"
                            >
                                {{
                                    verification
                                        ? 'Check again'
                                        : 'Run the checks'
                                }}
                            </Button>
                            <InputError
                                class="mt-2"
                                :message="errors.verification"
                            />
                        </Form>
                    </section>
                </div>
            </aside>

            <div class="max-w-3xl space-y-12 lg:col-start-1">
                <section
                    v-if="run && run.answers.length > 0"
                    class="space-y-3"
                    data-test="answers"
                >
                    <h2 class="text-xl font-semibold tracking-[-0.02em]">
                        What you told me
                    </h2>
                    <ul class="divide-y border-y">
                        <li
                            v-for="(item, index) in run.answers"
                            :key="index"
                            class="space-y-1 py-4"
                        >
                            <p class="text-muted-foreground">
                                {{ item.question }}
                            </p>
                            <p>
                                {{ item.answer }}
                                <span
                                    v-if="item.decided_by === 'builder'"
                                    class="text-sm text-muted-foreground"
                                    >· you let me decide</span
                                >
                            </p>
                        </li>
                    </ul>
                </section>

                <section
                    v-if="run?.plan && run.plan.assumptions.length > 0"
                    class="space-y-3"
                    data-test="decisions"
                >
                    <h2 class="text-xl font-semibold tracking-[-0.02em]">
                        Decisions I made for you
                    </h2>
                    <p class="text-muted-foreground">
                        If one is wrong, adjust that part below.
                    </p>
                    <ul class="divide-y border-y">
                        <li
                            v-for="(assumption, index) in run.plan.assumptions"
                            :key="index"
                            class="py-4"
                        >
                            {{ assumption }}
                        </li>
                    </ul>
                </section>

                <section
                    v-if="keptSame.length > 0"
                    class="space-y-3"
                    data-test="review-preserved"
                >
                    <h2 class="text-xl font-semibold tracking-[-0.02em]">
                        Stays the same
                    </h2>
                    <p v-if="!keptSameChecked" class="text-muted-foreground">
                        No test checks these yet.
                    </p>
                    <ul class="space-y-3" data-test="brief-preserve">
                        <li
                            v-for="(item, index) in keptSame"
                            :key="index"
                            class="grid grid-cols-[1.25rem_minmax(0,1fr)] gap-x-2"
                        >
                            <Check
                                v-if="item.checked"
                                class="mt-1 size-4 text-green-700 dark:text-green-400"
                                aria-label="Checked"
                            />
                            <CircleDashed
                                v-else
                                class="mt-1 size-4 text-muted-foreground"
                                aria-label="Not checked yet"
                            />
                            <span>
                                {{ item.statement }}
                                <span
                                    v-if="item.label && keptSameChecked"
                                    class="text-sm text-muted-foreground"
                                    >· {{ item.label }}</span
                                >
                            </span>
                        </li>
                    </ul>
                </section>

                <Collapsible
                    v-if="doneWhen.length > 0"
                    v-slot="{ open }"
                    data-test="review-verified"
                >
                    <CollapsibleTrigger
                        class="flex min-h-11 items-center gap-2 text-xl font-semibold tracking-[-0.02em] select-none"
                    >
                        Done when
                        <span class="font-normal text-muted-foreground">{{
                            doneWhen.length
                        }}</span>
                        <ChevronDown
                            :class="[
                                'size-5 text-muted-foreground transition-transform duration-quick',
                                open && 'rotate-180',
                            ]"
                        />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="mt-3 space-y-3">
                        <p
                            v-if="!doneWhenChecked"
                            class="text-muted-foreground"
                        >
                            No test checks these yet.
                        </p>
                        <ul class="space-y-3">
                            <li
                                v-for="(item, index) in doneWhen"
                                :key="index"
                                class="grid grid-cols-[1.25rem_minmax(0,1fr)] gap-x-2"
                            >
                                <Check
                                    v-if="item.checked"
                                    class="mt-1 size-4 text-green-700 dark:text-green-400"
                                    aria-label="Checked"
                                />
                                <CircleDashed
                                    v-else
                                    class="mt-1 size-4 text-muted-foreground"
                                    aria-label="Not checked yet"
                                />
                                <span>
                                    {{ item.criterion }}
                                    <span
                                        v-if="item.label && doneWhenChecked"
                                        class="text-sm text-muted-foreground"
                                        >· {{ item.label }}</span
                                    >
                                </span>
                            </li>
                        </ul>
                    </CollapsibleContent>
                </Collapsible>

                <section
                    v-if="
                        featureRequest.status === 'generated' &&
                        featureRequest.steps.length > 0 &&
                        !featureRequest.reverted_at
                    "
                    class="space-y-3"
                    data-test="steps"
                >
                    <h2 class="text-xl font-semibold tracking-[-0.02em]">
                        Adjust part of it
                    </h2>
                    <p class="text-muted-foreground">
                        Pick a part to ask for a change to it.
                    </p>

                    <ul class="divide-y border-y">
                        <li
                            v-for="step in featureRequest.steps"
                            :key="step.key"
                        >
                            <button
                                type="button"
                                :class="[
                                    'group flex w-full items-start gap-4 py-4 text-left transition-colors duration-quick select-none',
                                    selectedStepKey === step.key
                                        ? 'text-foreground'
                                        : 'hover:text-foreground',
                                ]"
                                :aria-expanded="selectedStepKey === step.key"
                                :data-test="`step-${step.key}`"
                                @click="
                                    selectedStepKey =
                                        selectedStepKey === step.key
                                            ? null
                                            : step.key
                                "
                            >
                                <span class="min-w-0 flex-1 space-y-1">
                                    <span class="block font-medium">{{
                                        step.label
                                    }}</span>
                                    <span class="block text-muted-foreground">{{
                                        step.detail
                                    }}</span>
                                </span>
                                <ChevronRight
                                    :class="[
                                        'mt-1 size-4 shrink-0 text-muted-foreground transition-transform duration-quick group-hover:translate-x-0.5',
                                        selectedStepKey === step.key &&
                                            'rotate-90 group-hover:translate-x-0',
                                    ]"
                                />
                            </button>

                            <!-- The form opens under the part it is about,
                                 not at the end of the list. -->
                            <Form
                                v-if="selectedStep?.key === step.key"
                                v-bind="
                                    FeatureRequestStepChangeController.store.form(
                                        featureRequest.id,
                                    )
                                "
                                class="space-y-3 pb-5"
                                v-slot="{ errors, processing }"
                            >
                                <input
                                    type="hidden"
                                    name="step"
                                    :value="selectedStep.key"
                                />
                                <Label for="step-prompt" class="sr-only">
                                    Change “{{ selectedStep.label }}”
                                </Label>
                                <textarea
                                    id="step-prompt"
                                    name="prompt"
                                    rows="2"
                                    required
                                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                                    placeholder="Only the team owner may do this."
                                />
                                <InputError
                                    :message="errors.prompt ?? errors.step"
                                />
                                <Button
                                    :disabled="processing"
                                    class="h-11 select-none sm:h-9"
                                    data-test="request-step-change-button"
                                >
                                    Ask for this change
                                </Button>
                            </Form>
                        </li>
                    </ul>
                </section>

                <section v-if="followUps.length > 0" class="space-y-3">
                    <h2 class="text-xl font-semibold tracking-[-0.02em]">
                        Follow-up changes
                    </h2>

                    <ul class="divide-y border-y">
                        <li v-for="followUp in followUps" :key="followUp.id">
                            <Link
                                :href="showFeatureRequest(followUp.id)"
                                class="flex min-h-11 items-center justify-between gap-4 py-4 transition-colors duration-quick select-none hover:text-muted-foreground"
                            >
                                <span>{{ followUp.prompt }}</span>
                                <StatusBadge :status="followUp.status" />
                            </Link>
                        </li>
                    </ul>
                </section>

                <Collapsible
                    v-if="
                        run || featureRequest.files.length > 0 || verification
                    "
                    v-slot="{ open }"
                    class="border-t pt-6"
                    data-test="details"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-x-4"
                    >
                        <CollapsibleTrigger
                            class="flex min-h-11 items-center gap-1.5 text-sm text-muted-foreground select-none hover:text-foreground sm:min-h-0"
                            data-test="details-toggle"
                        >
                            Details
                            <ChevronDown
                                :class="[
                                    'size-4 transition-transform duration-quick',
                                    open && 'rotate-180',
                                ]"
                            />
                        </CollapsibleTrigger>
                        <p
                            v-if="
                                run?.review &&
                                run.review.context_updates.length > 0
                            "
                            class="text-sm text-muted-foreground"
                            data-test="review-context-updates"
                        >
                            I also updated what I know about your app.
                        </p>
                    </div>
                    <CollapsibleContent class="mt-4 space-y-6 text-sm">
                        <div
                            v-if="
                                run?.plan?.understood_as ||
                                featureRequest.commit_sha
                            "
                            class="space-y-1 text-muted-foreground"
                        >
                            <p v-if="run?.plan?.understood_as">
                                I understood this as:
                                {{ run.plan.understood_as }}
                            </p>
                            <p v-if="featureRequest.commit_sha">
                                Saved as version
                                <span class="font-mono">{{
                                    featureRequest.commit_sha.slice(0, 7)
                                }}</span
                                ><template v-if="featureRequest.revert_sha">
                                    , undone in
                                    <span class="font-mono">{{
                                        featureRequest.revert_sha.slice(0, 7)
                                    }}</span></template
                                >.
                            </p>
                        </div>

                        <div
                            v-if="featureRequest.files.length > 0"
                            class="space-y-2"
                            data-test="change-preview"
                        >
                            <p class="font-medium">
                                The code:
                                {{ featureRequest.files.length }} files changed,
                                <span class="text-green-700 dark:text-green-400"
                                    >+{{ totals.additions }}</span
                                >
                                <span class="text-red-700 dark:text-red-400">
                                    −{{ totals.deletions }}</span
                                >
                            </p>

                            <ul class="divide-y rounded-lg border">
                                <li
                                    v-for="file in featureRequest.files"
                                    :key="file.path"
                                >
                                    <Collapsible>
                                        <CollapsibleTrigger
                                            class="flex w-full items-center justify-between gap-4 p-3 text-left hover:bg-muted/50"
                                        >
                                            <span
                                                class="truncate font-mono text-sm"
                                                >{{ file.path }}</span
                                            >
                                            <span
                                                class="shrink-0 font-mono text-xs"
                                            >
                                                <span
                                                    class="text-green-700 dark:text-green-400"
                                                    >+{{ file.additions }}</span
                                                >
                                                <span
                                                    class="text-red-700 dark:text-red-400"
                                                >
                                                    −{{ file.deletions }}</span
                                                >
                                            </span>
                                        </CollapsibleTrigger>
                                        <CollapsibleContent>
                                            <pre
                                                class="overflow-x-auto border-t bg-muted/30 py-2 font-mono text-xs leading-5"
                                            ><div
                                                v-for="(line, index) in file.diff.split('\n')"
                                                :key="index"
                                                :class="['px-3', lineClass(line)]"
                                            >{{ line || ' ' }}</div></pre>
                                        </CollapsibleContent>
                                    </Collapsible>
                                </li>
                            </ul>
                        </div>

                        <div
                            v-if="
                                verification && verification.results.length > 0
                            "
                            class="space-y-2"
                        >
                            <p class="font-medium">Each check</p>
                            <ul class="divide-y rounded-lg border">
                                <li
                                    v-for="(
                                        result, position
                                    ) in verification.results"
                                    :key="`${verification.id}-${position}`"
                                >
                                    <Collapsible>
                                        <CollapsibleTrigger
                                            class="flex w-full items-center justify-between gap-4 p-3 text-left hover:bg-muted/50"
                                        >
                                            <span
                                                class="flex items-center gap-2 text-sm"
                                            >
                                                <span
                                                    :class="
                                                        outcomeMarks[
                                                            result.outcome
                                                        ].class
                                                    "
                                                    aria-hidden="true"
                                                    >{{
                                                        outcomeMarks[
                                                            result.outcome
                                                        ].mark
                                                    }}</span
                                                >
                                                <span class="sr-only">{{
                                                    outcomeMarks[result.outcome]
                                                        .label
                                                }}</span>
                                                {{ result.name }}
                                            </span>
                                            <span
                                                class="font-mono text-xs text-muted-foreground"
                                            >
                                                {{ resultTiming(result) }}
                                            </span>
                                        </CollapsibleTrigger>
                                        <CollapsibleContent>
                                            <pre
                                                class="max-h-96 overflow-auto border-t bg-muted/30 p-3 font-mono text-xs leading-5 whitespace-pre-wrap"
                                                >{{
                                                    result.output ||
                                                    'No output.'
                                                }}</pre>
                                        </CollapsibleContent>
                                    </Collapsible>
                                </li>
                            </ul>
                        </div>

                        <div v-if="run && run.log.length > 0" class="space-y-2">
                            <p class="font-medium" data-test="run-log-toggle">
                                What I did
                            </p>
                            <ol class="space-y-1 text-muted-foreground">
                                <li
                                    v-for="entry in run.log"
                                    :key="entry.sequence"
                                    class="break-words"
                                >
                                    {{ entry.text }}
                                </li>
                            </ol>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
            </div>
        </div>
    </div>
</template>
