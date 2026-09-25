<script setup lang="ts">
import { Form, Head, Link, setLayoutProps, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import FeatureRequestStepChangeController from '@/actions/App/Http/Controllers/FeatureRequestStepChangeController';
import FeatureRequestVerificationController from '@/actions/App/Http/Controllers/FeatureRequestVerificationController';
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
    FeatureRequestDetail,
    FeatureRequestSummary,
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
}>();

const selectedStepKey = ref<string | null>(null);

// Inertia reuses this component when navigating from one request to another
// (for example to a follow-up), so refresh the breadcrumbs and selection.
watch(
    () => props.featureRequest.id,
    (id) => {
        selectedStepKey.value = null;
        setLayoutProps({
            breadcrumbs: [
                { title: 'Projects', href: index() },
                {
                    title: props.project.name,
                    href: showProject(props.project.id),
                },
                { title: `Request #${id}`, href: showFeatureRequest(id) },
            ],
        });
    },
    { immediate: true },
);

const { start, stop } = usePoll(
    1500,
    { only: ['featureRequest', 'followUps', 'verification', 'run'] },
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
        props.featureRequest.status === 'generating' ||
        verificationInProgress.value,
    (busy) => (busy ? start() : stop()),
    { immediate: true },
);

const runLabels: Record<Run['status'], string> = {
    queued: 'Queued',
    planning: 'Planning',
    implementing: 'Implementing',
    verifying: 'Verifying',
    reviewing: 'Reviewing',
    completed: 'Completed',
    needs_user_decision: 'Needs your decision',
    cancelling: 'Cancelling',
    cancelled: 'Cancelled',
    failed: 'Failed',
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
        case 'operation':
            return `${String(data.tool)} ${String(data.status)}${data.error ? `: ${String(data.error)}` : ''}`;
        case 'operation_reconciling':
            return `Checking whether ${String(data.tool)} took effect before the previous worker stopped`;
        case 'review':
            return data.approved
                ? 'Review accepted the verification evidence'
                : 'Review raised findings';
        default:
            return event.type;
    }
}

const verificationLabels: Record<Verification['status'], string> = {
    queued: 'Queued',
    running: 'Running',
    passed: 'Passed',
    failed: 'Failed',
    errored: 'Could not run',
    unverified: 'Unverified',
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
    <Head :title="`Request #${featureRequest.id}`" />

    <div class="flex h-full flex-1 flex-col gap-8 p-4">
        <div class="space-y-2">
            <div class="flex items-center gap-3">
                <Heading :title="featureRequest.prompt" class="mb-0!" />
                <StatusBadge :status="featureRequest.status" />
            </div>

            <p v-if="parent" class="text-sm text-muted-foreground">
                Follow-up to
                <Link
                    :href="showFeatureRequest(parent.id)"
                    class="underline underline-offset-4"
                >
                    “{{ parent.prompt }}”
                </Link>
                <template v-if="featureRequest.target_step">
                    — changes the step “{{ featureRequest.target_step.label }}”
                </template>
            </p>
        </div>

        <p
            v-if="featureRequest.status === 'generating'"
            class="text-sm text-muted-foreground"
            data-test="generating"
        >
            Generating the change…
        </p>

        <Alert v-if="featureRequest.status === 'failed'" variant="destructive">
            <AlertTitle>Generation failed</AlertTitle>
            <AlertDescription>{{ featureRequest.error }}</AlertDescription>
        </Alert>

        <section v-if="run" class="max-w-2xl space-y-4" data-test="run">
            <div class="flex items-center gap-3">
                <Heading
                    variant="small"
                    title="Build run"
                    :description="`${run.operations} of ${run.budget.operations} tool operations used · ${run.budget.minutes} minute limit`"
                />
                <Badge
                    :variant="
                        run.status === 'completed'
                            ? 'default'
                            : run.status === 'failed' ||
                                run.status === 'needs_user_decision'
                              ? 'destructive'
                              : 'secondary'
                    "
                    data-test="run-status"
                >
                    {{ runLabels[run.status] }}
                </Badge>
            </div>

            <Alert
                v-if="
                    run.error &&
                    (run.status === 'failed' ||
                        run.status === 'needs_user_decision')
                "
                variant="destructive"
            >
                <AlertTitle>{{
                    run.status === 'failed'
                        ? 'The run failed'
                        : 'The run stopped for your decision'
                }}</AlertTitle>
                <AlertDescription>
                    {{ run.error }}
                    <template v-if="run.status === 'needs_user_decision'">
                        You can revise the request, try a stronger model or
                        involve a person.
                    </template>
                </AlertDescription>
            </Alert>

            <Form
                v-if="runInProgress && run.status !== 'cancelling'"
                v-bind="RunCancellationController.store.form(run.id)"
                v-slot="{ processing }"
            >
                <Button
                    variant="outline"
                    :disabled="processing"
                    data-test="cancel-run-button"
                >
                    Cancel run
                </Button>
            </Form>

            <Collapsible>
                <CollapsibleTrigger
                    class="text-sm underline underline-offset-4"
                    data-test="run-log-toggle"
                >
                    Run log ({{ run.events.length }} events)
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <ol class="mt-2 divide-y rounded-lg border">
                        <li
                            v-for="event in run.events"
                            :key="event.sequence"
                            class="flex gap-3 p-2 text-sm"
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
                </CollapsibleContent>
            </Collapsible>
        </section>

        <template v-if="featureRequest.status === 'generated'">
            <section class="space-y-4" data-test="change-preview">
                <Heading
                    variant="small"
                    title="Change preview"
                    :description="featureRequest.summary ?? undefined"
                />

                <p class="text-sm text-muted-foreground">
                    {{ featureRequest.files.length }} files changed,
                    <span class="text-green-700 dark:text-green-400"
                        >+{{ totals.additions }}</span
                    >
                    <span class="text-red-700 dark:text-red-400">
                        −{{ totals.deletions }}</span
                    >
                </p>

                <ul class="divide-y rounded-lg border">
                    <li v-for="file in featureRequest.files" :key="file.path">
                        <Collapsible>
                            <CollapsibleTrigger
                                class="flex w-full items-center justify-between gap-4 p-3 text-left hover:bg-muted/50"
                            >
                                <span class="truncate font-mono text-sm">{{
                                    file.path
                                }}</span>
                                <span class="shrink-0 font-mono text-xs">
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
            </section>

            <section class="max-w-2xl space-y-4" data-test="steps">
                <Heading
                    variant="small"
                    title="Steps"
                    description="Select a step to ask for a change to it"
                />

                <ul class="space-y-2">
                    <li v-for="step in featureRequest.steps" :key="step.key">
                        <button
                            type="button"
                            :class="[
                                'w-full rounded-lg border p-4 text-left transition-colors hover:bg-muted/50',
                                selectedStepKey === step.key &&
                                    'border-primary ring-1 ring-primary',
                            ]"
                            :aria-pressed="selectedStepKey === step.key"
                            :data-test="`step-${step.key}`"
                            @click="selectedStepKey = step.key"
                        >
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium">{{
                                    step.label
                                }}</span>
                                <Badge variant="outline">{{ step.kind }}</Badge>
                            </div>
                            <p
                                class="mt-1 font-mono text-xs text-muted-foreground"
                            >
                                {{ step.symbol }} · {{ step.file }}
                            </p>
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
                        <InputError :message="errors.prompt ?? errors.step" />
                    </div>

                    <Button
                        :disabled="processing"
                        data-test="request-step-change-button"
                    >
                        Request change
                    </Button>
                </Form>
            </section>
        </template>

        <section
            v-if="featureRequest.status === 'generated'"
            class="space-y-4"
            data-test="verification"
        >
            <div class="flex items-center gap-3">
                <Heading
                    variant="small"
                    title="Verification"
                    description="Apply the change to a fresh copy of the project and run its checks"
                />
                <Badge
                    v-if="verification"
                    :variant="
                        verification.status === 'passed'
                            ? 'default'
                            : verification.status === 'queued' ||
                                verification.status === 'running' ||
                                verification.status === 'unverified'
                              ? 'secondary'
                              : 'destructive'
                    "
                    data-test="verification-status"
                >
                    {{ verificationLabels[verification.status] }}
                </Badge>
            </div>

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
                    {{ verification ? 'Run again' : 'Run verification' }}
                </Button>
                <InputError class="mt-2" :message="errors.verification" />
            </Form>

            <p
                v-if="verificationInProgress"
                class="text-sm text-muted-foreground"
            >
                Installing dependencies and running checks. This can take a few
                minutes…
            </p>

            <p
                v-if="verification?.status === 'unverified'"
                class="text-sm text-muted-foreground"
            >
                Every check passed, but no protected acceptance tests apply to
                this change, so its behaviour is not independently verified.
            </p>

            <Alert v-if="verification?.error" variant="destructive">
                <AlertTitle>Verification could not finish</AlertTitle>
                <AlertDescription>{{ verification.error }}</AlertDescription>
            </Alert>

            <ul
                v-if="verification && verification.results.length > 0"
                class="divide-y rounded-lg border"
            >
                <li
                    v-for="(result, position) in verification.results"
                    :key="`${verification.id}-${position}`"
                >
                    <Collapsible>
                        <CollapsibleTrigger
                            class="flex w-full items-center justify-between gap-4 p-3 text-left hover:bg-muted/50"
                        >
                            <span class="flex items-center gap-2 text-sm">
                                <span
                                    :class="outcomeMarks[result.outcome].class"
                                    aria-hidden="true"
                                    >{{
                                        outcomeMarks[result.outcome].mark
                                    }}</span
                                >
                                <span class="sr-only">{{
                                    outcomeMarks[result.outcome].label
                                }}</span>
                                {{ result.name }}
                                <Badge variant="outline">{{
                                    result.stage
                                }}</Badge>
                                <Badge
                                    v-if="result.stage === 'acceptance'"
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
                                >{{ result.output || 'No output.' }}</pre>
                        </CollapsibleContent>
                    </Collapsible>
                </li>
            </ul>
        </section>

        <section v-if="followUps.length > 0" class="max-w-2xl space-y-4">
            <Heading variant="small" title="Follow-up requests" />

            <ul class="divide-y rounded-lg border">
                <li v-for="followUp in followUps" :key="followUp.id">
                    <Link
                        :href="showFeatureRequest(followUp.id)"
                        class="flex items-center justify-between gap-4 p-4 hover:bg-muted/50"
                    >
                        <span class="text-sm">{{ followUp.prompt }}</span>
                        <StatusBadge :status="followUp.status" />
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
