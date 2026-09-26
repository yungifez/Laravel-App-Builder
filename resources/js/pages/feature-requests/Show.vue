<script setup lang="ts">
import { Form, Head, Link, setLayoutProps, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import FeatureRequestAcceptanceController from '@/actions/App/Http/Controllers/FeatureRequestAcceptanceController';
import FeatureRequestAnswerController from '@/actions/App/Http/Controllers/FeatureRequestAnswerController';
import FeatureRequestPreviewController from '@/actions/App/Http/Controllers/FeatureRequestPreviewController';
import FeatureRequestRetryController from '@/actions/App/Http/Controllers/FeatureRequestRetryController';
import FeatureRequestReversionController from '@/actions/App/Http/Controllers/FeatureRequestReversionController';
import FeatureRequestStepChangeController from '@/actions/App/Http/Controllers/FeatureRequestStepChangeController';
import FeatureRequestVerificationController from '@/actions/App/Http/Controllers/FeatureRequestVerificationController';
import PreviewController from '@/actions/App/Http/Controllers/PreviewController';
import RunCancellationController from '@/actions/App/Http/Controllers/RunCancellationController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
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
    RunEvent,
    Verification,
    VerificationResult,
} from '@/types';

const props = defineProps<{
    project: { id: number; name: string };
    featureRequest: FeatureRequestDetail;
    parent: { id: number; prompt: string } | null;
    followUps: FeatureRequestSummary[];
    verification: Verification | null;
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
                {
                    title: props.project.name,
                    href: showProject(props.project.id),
                },
                { title: `Change #${id}`, href: showFeatureRequest(id) },
            ],
        });
    },
    { immediate: true },
);

const { start, stop } = usePoll(
    1500,
    { only: ['featureRequest', 'followUps', 'verification', 'run', 'preview'] },
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

function describeEvent(event: RunEvent): string {
    const data = event.data;

    switch (event.type) {
        case 'created':
            return `Run created with the ${String(data.driver)} driver`;
        case 'lease_acquired':
            return data.took_over
                ? `A worker took over after the previous one stopped (fencing token ${String(data.fencing_token)})`
                : `A worker claimed the run (fencing token ${String(data.fencing_token)})`;
        case 'status':
            return `${runLabels[data.from as Run['status']]} → ${runLabels[data.to as Run['status']]}`;
        case 'workspace_ready':
            return 'Workspace prepared';
        case 'context_compiled':
            return `Project context compiled (${String(data.mode)}): ${(data.included as unknown[]).length} parts, about ${String(data.tokens)} tokens`;
        case 'operation':
            return `${String(data.tool)} ${String(data.status)}${data.error ? `: ${String(data.error)}` : ''}`;
        case 'operation_reconciling':
            return `Checking whether ${String(data.tool)} took effect before the previous worker stopped`;
        case 'review':
            return data.approved
                ? `Review approved: ${String(data.summary ?? '')}`
                : `Review found problems: ${String(data.summary ?? '')}`;
        case 'build_finished':
            return `The coder finished attempt ${Number(data.attempt) + 1} (its own account, not trusted): ${String(data.account)}`;
        case 'model_call':
            return data.adapter
                ? `Coding agent ${String(data.adapter)} (${String(data.provider)}${data.model ? ` ${String(data.model)}` : ''}) ${String(data.status).replace('_', ' ')}${data.error ? `: ${String(data.error)}` : ''} · ${String(data.turns)} turns, ${String(data.input_tokens)} tokens in, ${String(data.output_tokens)} out${data.cost_usd !== null ? `, $${Number(data.cost_usd).toFixed(2)}` : ''}`
                : `${String(data.role)} model call (${String(data.provider)} ${String(data.model)}): ${String(data.input_tokens)} tokens in, ${String(data.output_tokens)} out`;
        case 'failover':
            return `The ${String(data.from)} provider could not take the task (${String(data.reason)}); the workspace was reset and ${String(data.to)} took over`;
        case 'change_accepted':
            return `Accepted into the project as commit ${String(data.commit).slice(0, 7)}`;
        case 'change_reverted':
            return `Undone in commit ${String(data.revert).slice(0, 7)}`;
        case 'reviewer_not_independent':
            return `Reviewed by the same provider that built the change (${String(data.wanted)} has no credentials)`;
        default:
            return event.type;
    }
}

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

const contextModeLabels: Record<NonNullable<Run['context']>['mode'], string> = {
    none: 'no project context',
    flat: 'all project notes',
    selective: 'the notes for the parts this change touches',
    selective_without_effects:
        'the notes for the parts this change touches, without related parts',
};

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

    if (props.run) {
        return runLabels[props.run.status];
    }

    return {
        generating: 'Working on it',
        generated: 'Ready for you',
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

function evidenceMark(checked: boolean): string {
    return checked
        ? 'text-green-700 dark:text-green-400'
        : 'text-muted-foreground';
}

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
    unverified: 'Passed, nothing specific',
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
    <Head :title="featureRequest.prompt" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <header class="max-w-3xl space-y-1">
            <h1 class="text-xl font-semibold tracking-tight break-words">
                {{ featureRequest.prompt }}
            </h1>
            <p
                class="flex items-center gap-2 text-sm text-muted-foreground"
                data-test="run-status"
            >
                <span
                    :class="['size-2 shrink-0 rounded-full', stateDot]"
                    aria-hidden="true"
                />
                {{ stateLabel }}
            </p>
            <p v-if="parent" class="text-sm text-muted-foreground">
                Follow-up to
                <Link
                    :href="showFeatureRequest(parent.id)"
                    class="underline underline-offset-4"
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
            class="text-sm text-muted-foreground"
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
                <p>
                    {{
                        run.status === 'failed'
                            ? 'Something went wrong while I was making this change.'
                            : 'This change needed more work than I am allowed to do in one go.'
                    }}
                    Nothing in your app has changed. Try again, or ask in other
                    words.
                </p>
                <Collapsible>
                    <CollapsibleTrigger
                        class="min-h-11 text-xs underline-offset-4 select-none hover:underline sm:min-h-0"
                    >
                        Details
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <p class="font-mono text-xs break-words">
                            {{ run.error }}
                        </p>
                    </CollapsibleContent>
                </Collapsible>
            </AlertDescription>
        </Alert>

        <div
            v-if="featureRequest.can_retry"
            class="flex max-w-2xl flex-wrap items-center gap-3"
            data-test="retry"
        >
            <Form
                v-bind="
                    FeatureRequestRetryController.store.form(featureRequest.id)
                "
                v-slot="{ errors, processing }"
            >
                <Button
                    :disabled="processing"
                    class="h-11 select-none sm:h-9"
                    data-test="retry-button"
                >
                    Try again
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

        <section
            v-if="run?.question"
            class="max-w-2xl space-y-4 rounded-lg border p-4"
            data-test="question"
        >
            <div class="space-y-1">
                <p class="text-sm text-muted-foreground">
                    One thing I want to confirm
                </p>
                <h2 class="text-lg font-medium break-words">
                    {{ run.question.text }}
                </h2>
                <p
                    v-if="run.question.why"
                    class="text-sm text-muted-foreground"
                >
                    {{ run.question.why }}
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

            <p
                v-if="run.question.recommended"
                class="text-xs text-muted-foreground"
            >
                I would pick “{{ run.question.recommended }}”.
            </p>

            <div class="flex flex-wrap items-center gap-2 border-t pt-3">
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
                        :disabled="processing"
                        class="-ml-3 h-11 select-none sm:h-9"
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
                    class="h-11 select-none sm:h-9"
                    data-test="ask-more-questions"
                    @click="moreQuestions = true"
                >
                    Ask me more questions
                </Button>
                <p v-else class="text-sm text-muted-foreground">
                    After this, I'll ask about the other things I'm unsure of,
                    one at a time.
                </p>
            </div>
        </section>

        <!-- The story of the change on the left; what to do about it on the
             right, where it stays in view. On a phone the decision comes
             straight after what changed, before the finer detail. -->
        <div
            class="grid gap-x-10 gap-y-8 lg:grid-cols-[minmax(0,1fr)_20rem] [&>*]:min-w-0"
        >
            <div class="max-w-2xl space-y-8">
                <p
                    v-if="run && runInProgress && !run.plan"
                    class="text-sm text-muted-foreground"
                >
                    {{ runLabels[run.status] }}…
                </p>

                <section
                    v-if="run?.plan"
                    class="space-y-2"
                    data-test="run-plan"
                >
                    <h2 class="text-sm font-medium">
                        Here's what I'm changing
                    </h2>
                    <p class="text-base">{{ run.plan.summary }}</p>
                    <template
                        v-if="
                            !run.review &&
                            run.plan.current_behavior &&
                            run.plan.current_behavior !== 'New'
                        "
                    >
                        <h3 class="pt-2 text-sm font-medium">
                            How it works now
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            {{ run.plan.current_behavior }}
                        </p>
                    </template>
                    <p
                        v-if="runInProgress"
                        class="text-sm text-muted-foreground"
                    >
                        {{ runLabels[run.status] }}…
                    </p>
                </section>

                <section
                    v-if="run?.review"
                    class="space-y-6 text-sm"
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
                            class="space-y-2"
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
                                <h2 class="font-medium">{{ section.title }}</h2>
                                <p
                                    v-if="section.description"
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
                                    class="space-y-1 py-3"
                                >
                                    <p class="font-medium">
                                        {{ change.behavior }}
                                        <span
                                            v-if="change.area_name"
                                            class="font-normal text-muted-foreground"
                                            >· {{ change.area_name }}</span
                                        >
                                    </p>
                                    <dl
                                        class="grid grid-cols-[4rem_minmax(0,1fr)] gap-x-2 gap-y-1"
                                    >
                                        <dt class="text-muted-foreground">
                                            Before
                                        </dt>
                                        <dd class="text-muted-foreground">
                                            {{ change.before }}
                                        </dd>
                                        <dt class="text-muted-foreground">
                                            Now
                                        </dt>
                                        <dd>{{ change.now }}</dd>
                                    </dl>
                                </li>
                            </ul>
                            <p
                                v-if="areasIn(section.key).length > 0"
                                class="text-xs text-muted-foreground"
                            >
                                Parts of your app:
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

            <aside class="lg:col-start-2 lg:row-span-2 lg:row-start-1">
                <div class="space-y-8 lg:sticky lg:top-4">
                    <div
                        v-if="
                            featureRequest.can_accept ||
                            featureRequest.commit_sha
                        "
                        class="space-y-3 text-sm"
                        data-test="change-decision"
                    >
                        <template v-if="featureRequest.reverted_at">
                            <h2 class="text-base font-medium">Undone</h2>
                            <p class="text-muted-foreground">
                                You kept this change, then undid it. Your app
                                works as it did before.
                            </p>
                        </template>
                        <template v-else-if="featureRequest.commit_sha">
                            <h2 class="text-base font-medium">Kept</h2>
                            <p class="text-muted-foreground">
                                This change is part of your app. You can undo
                                it.
                            </p>
                            <Form
                                v-bind="
                                    FeatureRequestReversionController.store.form(
                                        featureRequest.id,
                                    )
                                "
                                v-slot="{ processing, errors }"
                                class="space-y-2"
                            >
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
                        </template>
                        <template v-else>
                            <h2 class="text-base font-medium">
                                Keep this change?
                            </h2>
                            <p class="text-muted-foreground">
                                Your next change starts from here. You can undo
                                it later.
                            </p>
                            <Form
                                v-bind="
                                    FeatureRequestAcceptanceController.store.form(
                                        featureRequest.id,
                                    )
                                "
                                v-slot="{ processing, errors }"
                                class="space-y-2"
                            >
                                <Button
                                    :disabled="processing"
                                    class="h-11 w-full select-none"
                                    data-test="accept-change-button"
                                >
                                    Keep this change
                                </Button>
                                <InputError :message="errors.change" />
                            </Form>
                        </template>
                    </div>

                    <Form
                        v-if="
                            run && runInProgress && run.status !== 'cancelling'
                        "
                        v-bind="RunCancellationController.store.form(run.id)"
                        v-slot="{ processing }"
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
                        class="space-y-3 text-sm"
                        data-test="preview"
                    >
                        <div>
                            <h2 class="font-medium">Try it</h2>
                            <p class="text-muted-foreground">
                                Open your app with this change, without changing
                                the real one.
                            </p>
                        </div>
                        <p
                            v-if="preview"
                            class="text-muted-foreground"
                            data-test="preview-status"
                        >
                            {{ previewLabels[preview.status] }}
                        </p>
                        <p
                            v-if="preview?.status === 'starting'"
                            class="text-sm text-muted-foreground"
                        >
                            Getting your app ready. This can take a few minutes…
                        </p>

                        <Alert v-if="preview?.error" variant="destructive">
                            <AlertTitle>Your app could not start</AlertTitle>
                            <AlertDescription class="whitespace-pre-wrap">{{
                                preview.error
                            }}</AlertDescription>
                        </Alert>

                        <div class="flex flex-wrap items-center gap-2">
                            <Button v-if="preview?.status === 'ready'" as-child>
                                <a
                                    :href="
                                        PreviewController.show.url(preview.id)
                                    "
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    data-test="open-preview-link"
                                    >Open</a
                                >
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
                                    data-test="stop-preview-button"
                                >
                                    Stop
                                </Button>
                            </Form>
                        </div>
                    </section>

                    <section
                        v-if="featureRequest.status === 'generated'"
                        class="space-y-3 text-sm"
                        data-test="verification"
                    >
                        <div>
                            <h2 class="font-medium">Checks I ran</h2>
                            <p class="text-muted-foreground">
                                I try the change on a fresh copy of your app and
                                run its checks.
                            </p>
                        </div>
                        <p
                            v-if="verification"
                            :class="[
                                'flex items-center gap-2',
                                verification.status === 'failed' ||
                                verification.status === 'errored'
                                    ? 'text-destructive'
                                    : 'text-foreground',
                            ]"
                            data-test="verification-status"
                        >
                            {{ verificationLabels[verification.status] }}
                        </p>
                        <p
                            v-if="verificationInProgress"
                            class="text-sm text-muted-foreground"
                        >
                            Running the checks. This can take a few minutes…
                        </p>

                        <p
                            v-if="verification?.status === 'unverified'"
                            class="text-sm text-muted-foreground"
                        >
                            Every check passed, but none of them is about this
                            change in particular.
                        </p>

                        <Alert v-if="verification?.error" variant="destructive">
                            <AlertTitle>The checks could not finish</AlertTitle>
                            <AlertDescription>{{
                                verification.error
                            }}</AlertDescription>
                        </Alert>

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
                                data-test="run-verification-button"
                            >
                                {{
                                    verification
                                        ? 'Run the checks again'
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

            <div class="max-w-2xl space-y-8">
                <section
                    v-if="run && run.answers.length > 0"
                    class="space-y-2 text-sm"
                    data-test="answers"
                >
                    <h2 class="font-medium">What you told me</h2>
                    <ul class="space-y-1">
                        <li v-for="(item, index) in run.answers" :key="index">
                            {{ item.question }}
                            <span class="font-medium">{{ item.answer }}</span>
                            <span
                                v-if="item.decided_by === 'builder'"
                                class="text-xs text-muted-foreground"
                                >· you let me decide</span
                            >
                        </li>
                    </ul>
                </section>

                <section
                    v-if="run?.plan && run.plan.assumptions.length > 0"
                    class="space-y-2 text-sm"
                    data-test="decisions"
                >
                    <h2 class="font-medium">Decisions I made for you</h2>
                    <p class="text-muted-foreground">
                        If one of these is wrong, adjust that part below or ask
                        in other words.
                    </p>
                    <ul class="list-disc space-y-1 pl-5">
                        <li
                            v-for="(assumption, index) in run.plan.assumptions"
                            :key="index"
                        >
                            {{ assumption }}
                        </li>
                    </ul>
                </section>

                <section
                    v-if="keptSame.length > 0"
                    class="space-y-2 text-sm"
                    data-test="review-preserved"
                >
                    <h2 class="font-medium">I'll keep these the same</h2>
                    <ul class="space-y-1" data-test="brief-preserve">
                        <li
                            v-for="(item, index) in keptSame"
                            :key="index"
                            class="grid grid-cols-[1rem_minmax(0,1fr)] gap-x-1"
                        >
                            <span :class="evidenceMark(item.checked)">{{
                                item.checked ? '✓' : '–'
                            }}</span>
                            <span>
                                {{ item.statement }}
                                <span
                                    v-if="item.label"
                                    class="text-xs text-muted-foreground"
                                    >· {{ item.label }}</span
                                >
                            </span>
                        </li>
                    </ul>
                </section>

                <Collapsible
                    v-if="doneWhen.length > 0"
                    data-test="review-verified"
                >
                    <CollapsibleTrigger
                        class="min-h-11 text-sm font-medium underline-offset-4 select-none hover:underline sm:min-h-0"
                    >
                        How I'll know it works ({{ doneWhen.length }})
                    </CollapsibleTrigger>
                    <CollapsibleContent class="mt-2">
                        <ul class="space-y-1 text-sm">
                            <li
                                v-for="(item, index) in doneWhen"
                                :key="index"
                                class="grid grid-cols-[1rem_minmax(0,1fr)] gap-x-1"
                            >
                                <span :class="evidenceMark(item.checked)">{{
                                    item.checked ? '✓' : '–'
                                }}</span>
                                <span>
                                    {{ item.criterion }}
                                    <span
                                        v-if="item.label"
                                        class="text-xs text-muted-foreground"
                                        >· {{ item.label }}</span
                                    >
                                </span>
                            </li>
                        </ul>
                    </CollapsibleContent>
                </Collapsible>

                <p
                    v-if="run?.review && run.review.context_updates.length > 0"
                    class="text-xs text-muted-foreground"
                    data-test="review-context-updates"
                >
                    I also updated what I know about your business.
                </p>
                <section
                    v-if="
                        featureRequest.status === 'generated' &&
                        featureRequest.steps.length > 0 &&
                        !featureRequest.reverted_at
                    "
                    class="max-w-2xl space-y-4"
                    data-test="steps"
                >
                    <Heading
                        variant="small"
                        title="Adjust part of it"
                        description="Pick a part to ask for a change to it"
                    />

                    <ul class="divide-y border-y">
                        <li
                            v-for="step in featureRequest.steps"
                            :key="step.key"
                        >
                            <button
                                type="button"
                                :class="[
                                    '-mx-2 w-[calc(100%+1rem)] rounded-md px-2 py-3 text-left select-none hover:bg-muted/50',
                                    selectedStepKey === step.key && 'bg-muted',
                                ]"
                                :aria-pressed="selectedStepKey === step.key"
                                :data-test="`step-${step.key}`"
                                @click="selectedStepKey = step.key"
                            >
                                <span class="text-sm font-medium">{{
                                    step.label
                                }}</span>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ step.detail }}
                                </p>
                            </button>
                        </li>
                    </ul>

                    <Form
                        v-if="selectedStep"
                        v-bind="
                            FeatureRequestStepChangeController.store.form(
                                featureRequest.id,
                            )
                        "
                        class="space-y-4 rounded-lg border p-4"
                        v-slot="{ errors, processing }"
                    >
                        <input
                            type="hidden"
                            name="step"
                            :value="selectedStep.key"
                        />

                        <div class="grid gap-2">
                            <Label for="step-prompt">
                                Change “{{ selectedStep.label }}”
                            </Label>
                            <textarea
                                id="step-prompt"
                                name="prompt"
                                rows="2"
                                required
                                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                                placeholder="Only the team owner may do this."
                            />
                            <InputError
                                :message="errors.prompt ?? errors.step"
                            />
                        </div>

                        <Button
                            :disabled="processing"
                            data-test="request-step-change-button"
                        >
                            Ask for this change
                        </Button>
                    </Form>
                </section>

                <section
                    v-if="followUps.length > 0"
                    class="max-w-2xl space-y-4"
                >
                    <Heading variant="small" title="Follow-up changes" />

                    <ul class="divide-y border-y">
                        <li v-for="followUp in followUps" :key="followUp.id">
                            <Link
                                :href="showFeatureRequest(followUp.id)"
                                class="flex min-h-11 items-center justify-between gap-4 py-3 select-none hover:bg-muted/50"
                            >
                                <span class="text-sm">{{
                                    followUp.prompt
                                }}</span>
                                <StatusBadge :status="followUp.status" />
                            </Link>
                        </li>
                    </ul>
                </section>

                <Collapsible
                    v-if="
                        run || featureRequest.files.length > 0 || verification
                    "
                    class="max-w-4xl"
                    data-test="details"
                >
                    <CollapsibleTrigger
                        class="text-sm text-muted-foreground underline underline-offset-4"
                        data-test="details-toggle"
                    >
                        Details
                    </CollapsibleTrigger>
                    <CollapsibleContent class="mt-4 space-y-6 text-sm">
                        <div v-if="run" class="space-y-1 text-muted-foreground">
                            <p>
                                {{ run.operations }} of
                                {{ run.budget.operations }} tool operations used
                                · {{ run.repairs }} of
                                {{ run.budget.repairs }} repairs ·
                                {{ run.budget.minutes }} minute limit
                                <template v-if="run.plan?.understood_as">
                                    · Understood as:
                                    {{ run.plan.understood_as }}
                                </template>
                            </p>
                            <p v-if="run.built_by" data-test="run-built-by">
                                <template v-if="run.built_by.backup">
                                    Built with the backup provider ({{
                                        run.built_by.provider
                                    }}<template v-if="run.built_by.reason">
                                        took over after
                                        {{
                                            run.built_by.reason.replaceAll(
                                                '_',
                                                ' ',
                                            )
                                        }}</template
                                    >).
                                </template>
                                <template v-else>
                                    Built by {{ run.built_by.adapter }} ({{
                                        run.built_by.provider
                                    }}).
                                </template>
                            </p>
                            <p v-if="run.context" data-test="run-context">
                                The builder was given
                                {{ contextModeLabels[run.context.mode] }} (about
                                {{ run.context.tokens }} tokens<template
                                    v-if="run.context.included.length > 0"
                                    >:
                                    {{
                                        run.context.included
                                            .map((part) => part.file)
                                            .join(', ')
                                    }}</template
                                >).
                                <template
                                    v-if="run.context.problems.length > 0"
                                >
                                    Some notes could not be read:
                                    {{ run.context.problems.join(' ') }}
                                </template>
                            </p>
                            <p
                                v-if="
                                    run.review &&
                                    run.review.context_updates.length > 0
                                "
                            >
                                Updated notes:
                                {{ run.review.context_updates.join(', ') }}
                            </p>
                            <p v-if="featureRequest.commit_sha">
                                Commit
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
                            v-if="featureRequest.steps.length > 0"
                            class="space-y-1"
                        >
                            <p class="font-medium">Where each part lives</p>
                            <ul class="space-y-1">
                                <li
                                    v-for="step in featureRequest.steps"
                                    :key="step.key"
                                    class="text-muted-foreground"
                                >
                                    {{ step.label }}
                                    <Badge variant="outline">{{
                                        step.kind
                                    }}</Badge>
                                    <span class="font-mono text-xs">
                                        {{ step.symbol }} ·
                                        {{ step.file }}</span
                                    >
                                </li>
                            </ul>
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
                                                <Badge variant="outline">{{
                                                    result.stage
                                                }}</Badge>
                                                <Badge
                                                    v-if="
                                                        result.stage ===
                                                        'acceptance'
                                                    "
                                                    variant="secondary"
                                                    >protected</Badge
                                                >
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

                        <div v-if="run" class="space-y-2">
                            <p class="font-medium" data-test="run-log-toggle">
                                Run log ({{ run.events.length }} events)
                            </p>
                            <ol class="divide-y rounded-lg border">
                                <li
                                    v-for="event in run.events"
                                    :key="event.sequence"
                                    class="flex gap-3 p-2"
                                >
                                    <span
                                        class="w-6 shrink-0 text-right font-mono text-xs text-muted-foreground"
                                        >{{ event.sequence }}</span
                                    >
                                    <span class="break-words">{{
                                        describeEvent(event)
                                    }}</span>
                                </li>
                            </ol>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
            </div>
        </div>
    </div>
</template>
