<script setup lang="ts">
import { Form, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    ChevronRight,
    CircleAlert,
    CircleCheck,
    CircleMinus,
    ExternalLink,
    FileCode2,
    LoaderCircle,
    Maximize2,
    Minimize2,
    SearchCheck,
    Sparkles,
    SquareTerminal,
    Target,
    Undo2,
} from '@lucide/vue';
import { useScreen } from '@/composables/useScreen';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import FeatureRequestAcceptanceController from '@/actions/App/Http/Controllers/FeatureRequestAcceptanceController';
import FeatureRequestAnswerController from '@/actions/App/Http/Controllers/FeatureRequestAnswerController';
import FeatureRequestAssumptionController from '@/actions/App/Http/Controllers/FeatureRequestAssumptionController';
import FeatureRequestFollowUpController from '@/actions/App/Http/Controllers/FeatureRequestFollowUpController';
import FeatureRequestPreviewController from '@/actions/App/Http/Controllers/FeatureRequestPreviewController';
import FeatureRequestKeepTryingController from '@/actions/App/Http/Controllers/FeatureRequestKeepTryingController';
import FeatureRequestRetryController from '@/actions/App/Http/Controllers/FeatureRequestRetryController';
import FeatureRequestReversionController from '@/actions/App/Http/Controllers/FeatureRequestReversionController';
import FeatureRequestVerificationController from '@/actions/App/Http/Controllers/FeatureRequestVerificationController';
import FeatureRequestWorkerController from '@/actions/App/Http/Controllers/FeatureRequestWorkerController';
import PreviewController from '@/actions/App/Http/Controllers/PreviewController';
import RunCancellationController from '@/actions/App/Http/Controllers/RunCancellationController';
import DetailLevelController from '@/actions/App/Http/Controllers/Settings/DetailLevelController';
import ChangeCode from '@/components/ChangeCode.vue';
import ChangeProof from '@/components/ChangeProof.vue';
import ElapsedTime from '@/components/ElapsedTime.vue';
import MessageImages from '@/components/MessageImages.vue';
import InputError from '@/components/InputError.vue';
import WorkStepLine from '@/components/WorkStepLine.vue';
import WorkYourself from '@/components/WorkYourself.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { show as showFeatureRequest } from '@/routes/feature-requests';
import { show as showProject } from '@/routes/projects';
import { index as developers } from '@/routes/projects/developers';
import type { ChangeDetail, Run, VerificationResult } from '@/types';

// Roomy when the chat has the whole screen: more air between messages and
// the owner's messages kept narrow, so they read as a conversation.
const props = defineProps<{ change: ChangeDetail; roomy?: boolean }>();

const emit = defineEmits<{
    // Whether the change's code wants the whole screen.
    full: [on: boolean];
    // Whether the chat has the plan and the code beside it.
    sides: [on: boolean];
    // Whether the change stopped without being finished.
    stopped: [on: boolean];
}>();

const request = computed(() => props.change.featureRequest);
const run = computed(() => props.change.run);
const moreQuestions = ref(false);

// How the change is being made, in the owner's words. While it works the
// latest steps show as they happen; afterwards the whole story folds away.
const work = computed(() => run.value?.work ?? []);

// While it works, the line with the spinner already names the stage it is
// in, so the story leaves that stage out rather than say it twice.
const liveWork = computed(() => {
    const latest = work.value.slice(-6);
    const last = latest.at(-1);

    return last?.kind === 'stage' && Object.values(steps).includes(last.text)
        ? latest.slice(0, -1)
        : latest;
});

// What the owner might ask for next, one tap each, as in any chat. Offered
// only while the chat can go on and nothing has been asked after this yet,
// and not after a stop: they build on a change that was not made.
const nextIdeas = computed(() =>
    request.value.can_continue &&
    !failed.value &&
    props.change.followUps.length === 0
        ? (run.value?.plan?.next ?? [])
        : [],
);

// The same change at four depths (§28.3). The depth is remembered for the
// person, so a power user keeps seeing the detail they asked for.
const page = usePage();
const depths = [
    { level: 1, label: 'What' },
    { level: 2, label: 'Why' },
    { level: 3, label: 'How' },
    { level: 4, label: 'Code' },
] as const;
const depth = ref<number>(page.props.auth.user.detail_level ?? 1);

// Reading code wants room: the owner can give the Code view the whole
// screen, and it opens that way next time. Remembered in this browser only.
const FULL_KEY = 'builder.code-full';

function wantedFull(): boolean {
    try {
        return window.localStorage.getItem(FULL_KEY) === '1';
    } catch {
        return false;
    }
}

const wantsFull = ref(typeof window !== 'undefined' && wantedFull());

function toggleFull(): void {
    wantsFull.value = !wantsFull.value;

    try {
        window.localStorage.setItem(FULL_KEY, wantsFull.value ? '1' : '0');
    } catch {
        // Without storage the choice lasts until the page reloads.
    }
}

// A chat on the whole of a desktop screen has room beside it: the page
// puts the plan and the code on one side (and, when wider, the owner's
// other chats on the other). The why, the how and the code move there, so every depth is in
// view at once and the depth switch steps aside.
const wide = useScreen('(min-width: 1024px)');
const spread = computed(() => !!props.roomy && wide.value);
const sides = computed(
    () => spread.value && !!run.value?.plan && !run.value.plan.answer,
);

// The chat is named after what the owner first asked, as in the chat list.
const chatTitle = computed(
    () =>
        props.change.earlier[0]?.prompt ??
        request.value.background ??
        request.value.prompt,
);

watch(sides, (on) => emit('sides', on), { immediate: true });
onBeforeUnmount(() => emit('sides', false));

const full = computed(
    () =>
        !sides.value &&
        wantsFull.value &&
        depth.value === 4 &&
        !!run.value?.plan &&
        !run.value.plan.answer &&
        request.value.files.length > 0,
);

watch(full, (on) => emit('full', on), { immediate: true });
onBeforeUnmount(() => emit('full', false));

function setDepth(level: number): void {
    depth.value = level;
    router.patch(
        DetailLevelController.url(),
        { detail_level: level },
        { preserveState: true, preserveScroll: true, only: ['auth'] },
    );
}

const evidence = {
    checked: {
        icon: CircleCheck,
        tone: 'text-green-600',
        label: 'Checked by a test',
    },
    untouched: {
        icon: CircleMinus,
        tone: 'text-muted-foreground',
        label: 'Not touched',
    },
    open: {
        icon: CircleMinus,
        tone: 'text-muted-foreground',
        label: 'Not checked yet',
    },
};

const keptSame = computed(() => {
    const review = run.value?.review;

    if (review && review.preserved.length > 0) {
        return review.preserved.map((item) => ({
            text: item.statement,
            ...(item.evidence === 'verified'
                ? evidence.checked
                : item.evidence === 'untouched'
                  ? evidence.untouched
                  : evidence.open),
        }));
    }

    return (run.value?.plan?.preserve ?? []).map((text) => ({
        text,
        ...evidence.open,
    }));
});

const doneWhen = computed(() => {
    const review = run.value?.review;

    if (review && review.verified.length > 0) {
        return review.verified.map((item) => ({
            text: item.criterion,
            ...(item.evidence === 'tested' ? evidence.checked : evidence.open),
        }));
    }

    return (run.value?.plan?.acceptance_criteria ?? []).map((text) => ({
        text,
        ...evidence.open,
    }));
});

const alsoTouches = computed(() =>
    (run.value?.review?.areas.may_also_affect ?? []).map((area) => area.name),
);

// Where the change landed, by the parts of the app the review sorted its
// files into. Files outside every part come last.
const whereChanged = computed(() => {
    const files = request.value.files.map((file) => ({
        ...file,
        name: file.path.split('/').pop() ?? file.path,
        folder: file.path.split('/').slice(0, -1).join('/'),
    }));
    const areas = run.value?.review?.areas;
    const groups = [
        ...(areas?.requested ?? []),
        ...(areas?.may_also_affect ?? []),
    ]
        .map((area) => ({
            name: area.name,
            files: files.filter((file) => area.files.includes(file.path)),
        }))
        .filter((group) => group.files.length > 0);
    const placed = new Set(
        groups.flatMap((group) => group.files.map((file) => file.path)),
    );
    const rest = files.filter((file) => !placed.has(file.path));

    return rest.length > 0
        ? [
              ...groups,
              {
                  name: groups.length > 0 ? 'Other files' : 'Files',
                  files: rest,
              },
          ]
        : groups;
});

const testsAdded = computed(() =>
    request.value.files
        .map((file) => file.path)
        .filter(
            (path) => path.startsWith('tests/') && path.endsWith('Test.php'),
        ),
);

// A package shows as a new "name": "version" line in the app's package lists.
const packagesAdded = computed(() =>
    request.value.files
        .filter((file) => ['composer.json', 'package.json'].includes(file.path))
        .flatMap((file) =>
            file.diff
                .split('\n')
                .map(
                    (line) =>
                        line.match(/^\+\s*"([^"]+)":\s*"[\^~>=<*\d]/)?.[1],
                )
                .filter((name): name is string => name !== undefined),
        ),
);

const outcomes: Record<string, { icon: typeof CircleCheck; tone: string }> = {
    passed: { icon: CircleCheck, tone: 'text-green-600' },
    failed: { icon: CircleAlert, tone: 'text-red-600' },
    errored: { icon: CircleAlert, tone: 'text-red-600' },
    skipped: { icon: CircleMinus, tone: 'text-muted-foreground' },
    not_applicable: { icon: CircleMinus, tone: 'text-muted-foreground' },
};

// The checks that ran. One that did not apply to the change (no separate
// checks were written for it) did not run; the proof names that gap.
const ran = computed(() =>
    (props.change.verification?.results ?? []).filter(
        (result) => result.outcome !== 'not_applicable',
    ),
);

// A check that failed the same way before the change is the app's old
// problem, not something the change broke.
function failedBefore(result: VerificationResult): boolean {
    return (
        result.at_start === 'failed' && (result.new_problems ?? []).length === 0
    );
}

function lineClass(line: string): string {
    if (line.startsWith('+') && !line.startsWith('+++')) {
        return 'bg-green-500/10 text-green-700 dark:text-green-400';
    }

    if (line.startsWith('-') && !line.startsWith('---')) {
        return 'bg-red-500/10 text-red-700 dark:text-red-400';
    }

    return line.startsWith('@@') ? 'text-muted-foreground' : '';
}

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

// The owner's own Claude Code or Codex has the change, and we wait for it.
const theirs = computed(() => run.value?.yours?.waiting === true);

// The owner can take over a change we are still making.
const takeOver = computed(
    () =>
        request.value.can_work_yourself &&
        run.value !== null &&
        run.value.yours === null &&
        ['queued', 'planning', 'implementing'].includes(run.value.status),
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
    planning: 'Working out what you need',
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

watch(failed, (on) => emit('stopped', on), { immediate: true });
onBeforeUnmount(() => emit('stopped', false));

// Why the change could not be finished, as the run or the request says.
const reason = computed(() => run.value?.error ?? request.value.error ?? null);

// It looked and found nothing to change, and said why: its conclusion
// leads, and what it checked is there to read.
const foundNothing = computed(() => {
    const account = run.value?.found_nothing?.trim();

    if (!account) {
        return null;
    }

    const paragraphs = account.split(/\n\s*\n/);

    return {
        conclusion: paragraphs.at(-1) ?? account,
        checked: paragraphs.slice(0, -1).join('\n\n'),
    };
});

const changes = computed(() => run.value?.review?.changes ?? []);
const asked = computed(() =>
    changes.value.filter((change) => change.section !== 'unexpected'),
);
const unexpected = computed(() =>
    changes.value.filter((change) => change.section === 'unexpected'),
);

const checks = computed(() => {
    // A pass is said once: the proof below opens with the same verdict.
    const proven = props.change.proof.length > 0;

    switch (props.change.verification?.status) {
        case 'passed':
            return proven
                ? null
                : {
                      icon: CircleCheck,
                      tone: 'text-green-600',
                      label: 'Checks passed',
                  };
        // No protected tests apply, so the change is not proven (§12, §30.2).
        // The proof below names that gap; this only says there is one.
        case 'unverified':
            return proven
                ? null
                : {
                      icon: CircleMinus,
                      tone: 'text-muted-foreground',
                      label: 'Checks passed, with gaps',
                  };
        case 'failed':
            // Every failure was there before the change: it broke nothing,
            // and saying "failed" would blame it for the app's old problem.
            return props.change.verification.results
                .filter((result) => result.outcome === 'failed')
                .every(failedBefore)
                ? {
                      icon: CircleMinus,
                      tone: 'text-muted-foreground',
                      label: 'Broke nothing that worked before',
                  }
                : {
                      icon: CircleAlert,
                      tone: 'text-red-600',
                      label:
                          props.change.verification.failed ?? 'A check failed',
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
            <h2
                v-if="spread"
                class="min-w-0 truncate px-2 text-sm font-medium"
                :title="chatTitle"
                data-test="thread-title"
            >
                {{ chatTitle }}
            </h2>
            <Button
                v-else
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

        <div
            :class="[
                'flex min-h-0 flex-1 flex-col',
                full && 'lg:grid lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]',
            ]"
        >
            <div
                :class="[
                    'min-h-0 flex-1 overflow-y-auto p-4',
                    spread && 'px-[max(1rem,calc(50%-21rem))]',
                    roomy ? 'space-y-7 py-6 leading-relaxed' : 'space-y-4',
                    full && 'lg:border-r',
                ]"
            >
                <!-- What came before in this chat -->
                <template v-for="earlier in change.earlier" :key="earlier.id">
                    <div class="flex flex-col items-end gap-1.5">
                        <p
                            :class="[
                                'rounded-lg rounded-br-sm bg-muted px-3.5 py-2.5 text-sm break-words whitespace-pre-line',
                                roomy ? 'max-w-[65%]' : 'max-w-[85%]',
                            ]"
                        >
                            {{ earlier.prompt }}
                        </p>
                        <MessageImages :images="earlier.images" />
                    </div>
                    <Link
                        :href="
                            showProject(change.project.id, {
                                query: { change: earlier.id },
                            })
                        "
                        :only="['change']"
                        preserve-state
                        class="flex gap-2.5 rounded-md text-sm text-muted-foreground hover:text-foreground"
                        data-test="thread-earlier"
                    >
                        <span
                            class="grid size-7 shrink-0 place-items-center rounded-full bg-muted"
                            aria-hidden="true"
                        >
                            <Sparkles class="size-3.5" />
                        </span>
                        <span class="min-w-0 flex-1 pt-1 break-words">{{
                            earlier.summary ?? 'Done.'
                        }}</span>
                    </Link>
                </template>

                <!-- A change made in the background: nobody asked, so no
                     words are put in the owner's mouth. -->
                <p
                    v-if="request.background"
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                    data-test="thread-background"
                >
                    <Sparkles class="size-3.5 shrink-0" />
                    {{ request.background }}
                </p>

                <!-- What you asked -->
                <div v-else class="flex flex-col items-end gap-1.5">
                    <p
                        :class="[
                            'rounded-lg rounded-br-sm bg-muted px-3.5 py-2.5 text-sm break-words whitespace-pre-line',
                            roomy ? 'max-w-[65%]' : 'max-w-[85%]',
                        ]"
                    >
                        {{ request.prompt }}
                    </p>
                    <MessageImages :images="request.images" />
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
                        <p
                            v-if="run?.plan?.answer"
                            class="leading-relaxed whitespace-pre-line"
                            data-test="thread-answer"
                        >
                            {{ run.plan.answer }}
                        </p>
                        <p v-else-if="run?.plan" class="leading-relaxed">
                            {{ run.plan.summary }}
                        </p>
                        <!-- How the change serves the owner's goal -->
                        <p
                            v-if="run?.plan?.goal && !run.plan.answer"
                            class="flex gap-1.5 text-muted-foreground"
                            data-test="thread-goal"
                        >
                            <Target
                                class="mt-0.5 size-3.5 shrink-0"
                                aria-label="Your goal"
                            />
                            {{ run.plan.goal }}
                        </p>
                        <!-- Whose tool wrote it, once it handed it back -->
                        <p
                            v-if="run?.yours?.wrote && !theirs"
                            class="flex gap-1.5 text-xs text-muted-foreground"
                            data-test="thread-written-by"
                        >
                            <SquareTerminal
                                class="mt-0.5 size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            Your own Claude Code or Codex wrote this. I checked
                            it the same way as my own.
                        </p>

                        <!-- How it is being made, step by step -->
                        <!-- Each step slides in as it happens -->
                        <TransitionGroup
                            v-if="working && liveWork.length > 0"
                            tag="ol"
                            class="space-y-1.5"
                            enter-active-class="transition duration-base ease-settle"
                            enter-from-class="opacity-0 translate-y-1"
                            data-test="thread-work"
                        >
                            <li
                                v-for="(step, index) in liveWork"
                                :key="work.length - liveWork.length + index"
                            >
                                <WorkStepLine :step="step" />
                            </li>
                        </TransitionGroup>

                        <p
                            v-if="theirs && run?.yours?.whole_app"
                            class="text-muted-foreground"
                            data-test="own-tool-writes"
                        >
                            Your Claude Code or Codex writes this. It takes it
                            the next time it asks for work.
                        </p>
                        <WorkYourself
                            v-else-if="theirs && run?.yours"
                            :request-id="request.id"
                            :run-id="run.id"
                            :address="run.yours.address"
                            :name="run.yours.name"
                        />

                        <div
                            v-if="working"
                            class="flex items-center gap-2 text-muted-foreground"
                            data-test="thread-working"
                        >
                            <LoaderCircle class="size-4 animate-spin" />
                            <span data-test="thread-progress"
                                >{{
                                    run?.progress?.text ??
                                    (theirs && run?.status === 'implementing'
                                        ? 'Waiting for your change'
                                        : steps[run?.status ?? 'queued'])
                                }}…</span
                            >
                            <!-- Seconds counting up show the work has not
                                 stalled, even while one step runs long -->
                            <ElapsedTime
                                v-if="run?.started_at"
                                :since="run.started_at"
                                class="text-xs"
                                data-test="thread-elapsed"
                            />
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

                        <!-- Depth for those who code: their own agent writes
                             it, and we still check what it hands back. -->
                        <Form
                            v-if="takeOver"
                            v-bind="
                                FeatureRequestWorkerController.store.form(
                                    request.id,
                                )
                            "
                            v-slot="{ errors, processing }"
                        >
                            <button
                                type="submit"
                                :disabled="processing"
                                class="min-h-11 text-xs text-muted-foreground select-none hover:text-foreground sm:min-h-6"
                                data-test="work-yourself-button"
                            >
                                Use my own Claude Code or Codex
                            </button>
                            <InputError :message="errors.worker" />
                        </Form>

                        <!-- A question to answer before going on -->
                        <div
                            v-if="run?.question"
                            class="space-y-3 border-t pt-3"
                            data-test="question"
                        >
                            <div>
                                <p class="font-medium">
                                    {{ run.question.text }}
                                </p>
                                <p
                                    v-if="run.question.why"
                                    class="mt-0.5 text-xs text-muted-foreground"
                                >
                                    {{ run.question.why }}
                                </p>
                                <!-- Easy choices are made for the owner; say
                                     why this one is not (direction 18 §6) -->
                                <p
                                    v-if="run.question.reversible === false"
                                    class="mt-0.5 text-xs text-muted-foreground"
                                    data-test="question-lasting"
                                >
                                    This is hard to change later, so I am asking
                                    you.
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
                                            option ===
                                                run.question.recommended &&
                                                'border-foreground/40',
                                        ]"
                                        :data-test="`answer-${option}`"
                                    >
                                        <span class="min-w-0 flex-1">{{
                                            option
                                        }}</span>
                                        <span
                                            v-if="
                                                option ===
                                                run.question.recommended
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
                                    <!-- A way out, not a fourth answer: quiet
                                         like "Ask me more", so the answers
                                         above stay the choice. -->
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        :disabled="processing"
                                        class="-ml-2 h-11 text-xs font-normal text-muted-foreground select-none hover:text-foreground sm:h-7"
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
                            :class="[
                                'space-y-2 rounded-md border p-3',
                                foundNothing
                                    ? 'bg-muted/40'
                                    : 'border-red-500/30 bg-red-500/5',
                            ]"
                            data-test="thread-failed"
                        >
                            <p
                                v-if="foundNothing"
                                class="flex items-center gap-2 font-medium"
                            >
                                <SearchCheck class="size-4" />
                                I found nothing to change
                            </p>
                            <p
                                v-else
                                class="flex items-center gap-2 font-medium"
                            >
                                <CircleAlert class="size-4 text-red-600" />
                                I couldn't finish this
                            </p>
                            <template v-if="foundNothing">
                                <p
                                    class="text-sm whitespace-pre-line"
                                    data-test="thread-found-nothing"
                                >
                                    {{ foundNothing.conclusion }}
                                </p>
                                <details
                                    v-if="foundNothing.checked"
                                    class="text-xs text-muted-foreground"
                                >
                                    <summary
                                        class="min-h-11 cursor-pointer select-none sm:min-h-0"
                                    >
                                        What I checked
                                    </summary>
                                    <p class="mt-1 whitespace-pre-line">
                                        {{ foundNothing.checked }}
                                    </p>
                                </details>
                            </template>
                            <!-- Why, when I know, so the owner is not left
                                 guessing; the app is never changed. -->
                            <p
                                v-else-if="reason"
                                class="text-sm"
                                data-test="thread-failed-reason"
                            >
                                {{ reason }}
                            </p>
                            <p
                                v-if="!reason?.includes('Nothing in your app')"
                                class="text-xs text-muted-foreground"
                            >
                                Nothing in your app changed.
                            </p>
                            <!-- It ran out of tries: going on from its work
                                 so far comes first, starting over second. -->
                            <div class="flex flex-wrap items-start gap-2">
                                <Form
                                    v-if="request.can_keep_trying"
                                    v-bind="
                                        FeatureRequestKeepTryingController.store.form(
                                            request.id,
                                        )
                                    "
                                    v-slot="{ errors, processing }"
                                >
                                    <Button
                                        size="sm"
                                        :disabled="processing"
                                        class="h-11 select-none sm:h-8"
                                        title="Go on from the work so far and keep fixing it"
                                        data-test="keep-trying-button"
                                    >
                                        Keep trying
                                    </Button>
                                    <InputError :message="errors.keep_trying" />
                                </Form>
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
                                        :variant="
                                            request.can_keep_trying
                                                ? 'outline'
                                                : 'default'
                                        "
                                        :disabled="processing"
                                        class="h-11 select-none sm:h-8"
                                        data-test="retry-button"
                                    >
                                        {{
                                            request.can_keep_trying
                                                ? 'Start over'
                                                : 'Try again'
                                        }}
                                    </Button>
                                    <InputError
                                        :message="errors.retry ?? errors.step"
                                    />
                                </Form>
                            </div>
                            <!-- What passed before this stays the owner's to keep -->
                            <Link
                                v-if="request.keep_earlier"
                                :href="
                                    showProject(change.project.id, {
                                        query: { change: request.keep_earlier },
                                    })
                                "
                                :only="['change']"
                                preserve-state
                                class="block min-h-11 content-center text-xs text-muted-foreground select-none hover:text-foreground sm:min-h-6"
                                data-test="keep-earlier-after-failure"
                            >
                                Keep the version before this
                            </Link>
                            <Form
                                v-if="request.can_work_yourself"
                                v-bind="
                                    FeatureRequestWorkerController.store.form(
                                        request.id,
                                    )
                                "
                                v-slot="{ errors, processing }"
                            >
                                <button
                                    type="submit"
                                    :disabled="processing"
                                    class="min-h-11 text-xs text-muted-foreground select-none hover:text-foreground sm:min-h-6"
                                    data-test="work-yourself-after-failure"
                                >
                                    Use my own Claude Code or Codex
                                </button>
                                <InputError :message="errors.worker" />
                            </Form>
                            <Link
                                :href="
                                    developers(change.project.id, {
                                        query: { change: request.id },
                                    })
                                "
                                class="block min-h-11 content-center text-xs text-muted-foreground select-none hover:text-foreground sm:min-h-6"
                                data-test="ask-developer-after-failure"
                            >
                                Ask one of our developers
                            </Link>
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
                                    <!-- Not green when it stopped: none of
                                         this reached the app. -->
                                    <Check
                                        :class="[
                                            'mt-0.5 size-4 shrink-0',
                                            failed
                                                ? 'text-muted-foreground'
                                                : 'text-green-600',
                                        ]"
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
                            class="space-y-2 rounded-md border border-amber-500/40 bg-amber-500/5 p-3"
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
                                <!-- Each one is the owner's to keep, so
                                     later changes follow it, or to change
                                     in this chat. -->
                                <ul
                                    class="mt-1 list-disc space-y-2 pl-9 text-xs text-muted-foreground"
                                >
                                    <li
                                        v-for="(assumption, index) in run.plan
                                            .assumptions"
                                        :key="index"
                                        data-test="decision"
                                    >
                                        {{ assumption }}
                                        <span
                                            class="flex min-h-6 items-center gap-3"
                                        >
                                            <span
                                                v-if="
                                                    run.kept_assumptions.includes(
                                                        assumption,
                                                    )
                                                "
                                                class="inline-flex items-center gap-1 text-foreground"
                                                data-test="decision-kept"
                                            >
                                                <Check class="size-3" /> You
                                                chose this
                                            </span>
                                            <Form
                                                v-else
                                                v-bind="
                                                    FeatureRequestAssumptionController.store.form(
                                                        request.id,
                                                    )
                                                "
                                                :options="{
                                                    preserveScroll: true,
                                                    preserveState: true,
                                                }"
                                                v-slot="{ processing }"
                                            >
                                                <input
                                                    type="hidden"
                                                    name="assumption"
                                                    :value="assumption"
                                                />
                                                <button
                                                    :disabled="processing"
                                                    class="inline-flex min-h-11 items-center underline-offset-4 select-none hover:text-foreground hover:underline sm:min-h-6"
                                                    data-test="decision-keep"
                                                >
                                                    Keep
                                                </button>
                                            </Form>
                                            <Link
                                                :href="
                                                    showProject(
                                                        change.project.id,
                                                        {
                                                            query: {
                                                                change: request.id,
                                                                ask: `Change this: “${assumption}”\n\nInstead, `,
                                                            },
                                                        },
                                                    )
                                                "
                                                class="inline-flex min-h-11 items-center underline-offset-4 select-none hover:text-foreground hover:underline sm:min-h-6"
                                                data-test="decision-change"
                                            >
                                                Change
                                            </Link>
                                        </span>
                                    </li>
                                </ul>
                            </CollapsibleContent>
                        </Collapsible>

                        <!-- How it was made, once it is made: kept
                             after what it made, for those who ask. -->
                        <Collapsible v-if="!working && work.length > 0">
                            <CollapsibleTrigger
                                class="group flex min-h-11 items-center gap-1 text-xs text-muted-foreground select-none hover:text-foreground sm:min-h-6"
                                data-test="thread-work-toggle"
                            >
                                <ChevronRight
                                    class="size-3.5 transition-transform group-data-[state=open]:rotate-90"
                                />
                                How I did it
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <ol
                                    class="mt-1.5 space-y-1.5"
                                    data-test="thread-work"
                                >
                                    <li
                                        v-for="(step, index) in work"
                                        :key="index"
                                    >
                                        <WorkStepLine :step="step" />
                                    </li>
                                </ol>
                            </CollapsibleContent>
                        </Collapsible>

                        <div
                            v-if="
                                request.status === 'generated' &&
                                (checking || checks)
                            "
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
                        </div>
                        <!-- Say why, or "Check again" looks like it does nothing. -->
                        <p
                            v-if="
                                !checking &&
                                change.verification?.status === 'errored' &&
                                change.verification.error
                            "
                            class="text-xs text-muted-foreground"
                            data-test="verification-error"
                        >
                            {{ change.verification.error }}
                        </p>
                        <ChangeProof
                            v-if="request.status === 'generated'"
                            :proof="change.proof"
                        />
                        <!-- The action comes after what it acts on, so it never
                             sits above the proof's heading on its own. -->
                        <div
                            v-if="request.status === 'generated'"
                            class="text-xs text-muted-foreground"
                        >
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

                        <!-- Deeper answers, for whoever wants them. A change
                             I could not finish has none to give. -->
                        <div
                            v-if="
                                run?.plan &&
                                !run.plan.answer &&
                                !sides &&
                                !failed
                            "
                            class="flex items-center gap-1"
                        >
                            <div
                                class="flex flex-1 rounded-md bg-muted p-0.5"
                                role="group"
                                aria-label="How much detail"
                                data-test="detail-level"
                            >
                                <button
                                    v-for="option in depths"
                                    :key="option.level"
                                    type="button"
                                    :aria-pressed="depth === option.level"
                                    :class="[
                                        'min-h-11 flex-1 rounded text-xs select-none sm:min-h-7',
                                        depth === option.level
                                            ? 'bg-background font-medium shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground',
                                    ]"
                                    :data-test="`detail-${option.level}`"
                                    @click="setDepth(option.level)"
                                >
                                    {{ option.label }}
                                </button>
                            </div>
                            <Button
                                v-if="depth === 4 && request.files.length > 0"
                                variant="ghost"
                                size="icon"
                                class="hidden size-8 shrink-0 text-muted-foreground lg:inline-flex"
                                :aria-pressed="wantsFull"
                                :aria-label="
                                    wantsFull
                                        ? 'Leave full screen'
                                        : 'Full screen'
                                "
                                :title="
                                    wantsFull
                                        ? 'Leave full screen'
                                        : 'Full screen'
                                "
                                data-test="code-full"
                                @click="toggleFull"
                            >
                                <component
                                    :is="wantsFull ? Minimize2 : Maximize2"
                                    class="size-4"
                                />
                            </Button>
                        </div>

                        <Teleport defer :to="'#beside-plan'" :disabled="!sides">
                            <div
                                v-if="
                                    (sides || depth === 2) &&
                                    run?.plan &&
                                    !run.plan.answer
                                "
                                class="space-y-6 leading-relaxed"
                                data-test="detail-why"
                            >
                                <section
                                    v-if="
                                        run.plan.current_behavior &&
                                        run.plan.current_behavior !== 'New'
                                    "
                                    class="space-y-2"
                                >
                                    <h3
                                        class="text-sm font-medium text-muted-foreground"
                                    >
                                        How it works now
                                    </h3>
                                    <p>{{ run.plan.current_behavior }}</p>
                                </section>
                                <section
                                    v-if="sides && request.steps.length > 0"
                                    class="space-y-2"
                                >
                                    <h3
                                        class="text-sm font-medium text-muted-foreground"
                                    >
                                        What I'm changing
                                    </h3>
                                    <p
                                        v-for="step in request.steps"
                                        :key="step.key"
                                    >
                                        <span class="font-medium">{{
                                            step.label
                                        }}</span>
                                        <span
                                            v-if="step.detail"
                                            class="block text-muted-foreground"
                                            >{{ step.detail }}</span
                                        >
                                    </p>
                                </section>
                                <section
                                    v-if="
                                        sides && run.plan.assumptions.length > 0
                                    "
                                    class="space-y-2"
                                >
                                    <h3
                                        class="text-sm font-medium text-muted-foreground"
                                    >
                                        What I decided for you
                                    </h3>
                                    <p
                                        v-for="(assumption, index) in run.plan
                                            .assumptions"
                                        :key="index"
                                    >
                                        {{ assumption }}
                                    </p>
                                </section>
                                <section
                                    v-if="keptSame.length > 0"
                                    class="space-y-2"
                                >
                                    <h3
                                        class="text-sm font-medium text-muted-foreground"
                                    >
                                        I'll keep these the same
                                    </h3>
                                    <p
                                        v-for="(item, index) in keptSame"
                                        :key="index"
                                        class="flex items-start gap-2"
                                        :title="item.label"
                                    >
                                        <component
                                            :is="item.icon"
                                            :class="[
                                                'mt-0.5 size-4 shrink-0',
                                                item.tone,
                                            ]"
                                            :aria-label="item.label"
                                        />
                                        <span class="min-w-0">{{
                                            item.text
                                        }}</span>
                                    </p>
                                </section>
                                <section
                                    v-if="doneWhen.length > 0"
                                    class="space-y-2"
                                >
                                    <h3
                                        class="text-sm font-medium text-muted-foreground"
                                    >
                                        Done when
                                    </h3>
                                    <p
                                        v-for="(item, index) in doneWhen"
                                        :key="index"
                                        class="flex items-start gap-2"
                                        :title="item.label"
                                    >
                                        <component
                                            :is="item.icon"
                                            :class="[
                                                'mt-0.5 size-4 shrink-0',
                                                item.tone,
                                            ]"
                                            :aria-label="item.label"
                                        />
                                        <span class="min-w-0">{{
                                            item.text
                                        }}</span>
                                    </p>
                                </section>
                                <section
                                    v-if="alsoTouches.length > 0"
                                    class="space-y-2"
                                >
                                    <h3
                                        class="text-sm font-medium text-muted-foreground"
                                    >
                                        This may also touch
                                    </h3>
                                    <p class="flex flex-wrap gap-1.5">
                                        <span
                                            v-for="name in alsoTouches"
                                            :key="name"
                                            class="rounded-full bg-muted px-2 py-0.5 text-xs"
                                            >{{ name }}</span
                                        >
                                    </p>
                                </section>
                            </div>
                        </Teleport>

                        <Teleport defer :to="'#beside-code'" :disabled="!sides">
                            <div
                                v-if="
                                    (sides || depth === 3) &&
                                    run?.plan &&
                                    !run.plan.answer
                                "
                                class="space-y-6"
                                data-test="detail-how"
                            >
                                <section
                                    v-for="group in whereChanged"
                                    :key="group.name"
                                    class="space-y-2"
                                >
                                    <h3
                                        class="text-sm font-medium text-muted-foreground"
                                    >
                                        {{ group.name }}
                                    </h3>
                                    <p
                                        v-for="file in group.files"
                                        :key="file.path"
                                        class="flex items-baseline gap-1.5 text-xs"
                                        :title="file.path"
                                    >
                                        <span
                                            class="min-w-0 truncate font-mono"
                                            >{{ file.name }}</span
                                        >
                                        <span
                                            class="min-w-0 flex-1 truncate text-muted-foreground"
                                            >{{ file.folder }}</span
                                        >
                                        <span
                                            class="shrink-0 font-mono tabular-nums"
                                        >
                                            <span class="text-green-600"
                                                >+{{ file.additions }}</span
                                            >
                                            <span class="text-red-600">
                                                −{{ file.deletions }}</span
                                            >
                                        </span>
                                    </p>
                                </section>
                                <section
                                    v-if="testsAdded.length > 0"
                                    class="space-y-2"
                                >
                                    <h3
                                        class="text-sm font-medium text-muted-foreground"
                                    >
                                        Tests I wrote
                                    </h3>
                                    <p
                                        v-for="path in testsAdded"
                                        :key="path"
                                        class="truncate font-mono text-xs"
                                        :title="path"
                                    >
                                        {{ path.split('/').pop() }}
                                    </p>
                                </section>
                                <section
                                    v-if="packagesAdded.length > 0"
                                    class="space-y-2"
                                >
                                    <h3
                                        class="text-sm font-medium text-muted-foreground"
                                    >
                                        Packages it adds
                                    </h3>
                                    <p
                                        v-for="name in packagesAdded"
                                        :key="name"
                                        class="font-mono text-xs"
                                    >
                                        {{ name }}
                                    </p>
                                </section>
                                <section
                                    v-if="ran.length > 0"
                                    class="space-y-2"
                                >
                                    <h3
                                        class="text-sm font-medium text-muted-foreground"
                                    >
                                        Checks I ran
                                    </h3>
                                    <p
                                        v-for="(result, index) in ran"
                                        :key="index"
                                        class="flex items-center gap-2"
                                    >
                                        <component
                                            :is="
                                                failedBefore(result)
                                                    ? CircleMinus
                                                    : outcomes[result.outcome]
                                                          .icon
                                            "
                                            :class="[
                                                'size-4 shrink-0',
                                                failedBefore(result)
                                                    ? 'text-muted-foreground'
                                                    : outcomes[result.outcome]
                                                          .tone,
                                            ]"
                                            :aria-label="result.outcome"
                                        />
                                        <span class="min-w-0 flex-1 truncate"
                                            >{{ result.name
                                            }}<span
                                                v-if="failedBefore(result)"
                                                class="text-muted-foreground"
                                            >
                                                · failing before this change
                                                too</span
                                            ></span
                                        >
                                        <!-- A check that was skipped or took
                                             no time shows none, not "0.0 s". -->
                                        <span
                                            v-if="result.duration_ms >= 50"
                                            class="shrink-0 text-xs text-muted-foreground tabular-nums"
                                            >{{
                                                (
                                                    result.duration_ms / 1000
                                                ).toFixed(1)
                                            }}
                                            s</span
                                        >
                                    </p>
                                </section>
                                <ChangeCode
                                    v-if="sides && request.files.length > 0"
                                    narrow
                                    class="-mx-5 border-t"
                                    :files="request.files"
                                />
                            </div>
                        </Teleport>

                        <div
                            v-if="
                                !sides &&
                                depth === 4 &&
                                request.files.length > 0
                            "
                            :class="['space-y-1.5', full && 'lg:hidden']"
                            data-test="detail-code"
                        >
                            <h3
                                class="text-sm font-medium text-muted-foreground"
                            >
                                Files
                            </h3>
                            <details
                                v-for="file in request.files"
                                :key="file.path"
                                open
                                class="group"
                            >
                                <summary
                                    class="flex min-h-11 cursor-pointer list-none items-center gap-2 select-none sm:min-h-7"
                                >
                                    <ChevronRight
                                        class="size-3.5 shrink-0 text-muted-foreground transition-transform group-open:rotate-90"
                                    />
                                    <span
                                        class="flex min-w-0 flex-1 items-baseline gap-1.5 text-xs"
                                        :title="file.path"
                                    >
                                        <span
                                            class="min-w-0 truncate font-mono"
                                            >{{
                                                file.path.split('/').pop()
                                            }}</span
                                        >
                                        <span
                                            class="min-w-0 truncate text-muted-foreground"
                                            >{{
                                                file.path
                                                    .split('/')
                                                    .slice(0, -1)
                                                    .join('/')
                                            }}</span
                                        >
                                    </span>
                                    <span
                                        class="shrink-0 font-mono text-xs tabular-nums"
                                    >
                                        <span class="text-green-600"
                                            >+{{ file.additions }}</span
                                        >
                                        <span class="text-red-600">
                                            −{{ file.deletions }}</span
                                        >
                                    </span>
                                </summary>
                                <pre
                                    class="mt-1 max-h-[60vh] overflow-auto rounded-md bg-muted/40 py-2 font-mono text-xs leading-5"
                                ><div
                                v-for="(line, index) in file.diff.split('\n')"
                                :key="index"
                                :class="['px-2', lineClass(line)]"
                            >{{ line || ' ' }}</div></pre>
                            </details>
                        </div>

                        <!-- Kept or undone -->
                        <div
                            v-if="request.commit_sha"
                            class="flex items-start gap-2"
                            data-test="change-decision"
                        >
                            <!-- The icon keeps its size beside long text,
                                 and sits on the first line. -->
                            <template v-if="request.reverted_at">
                                <Undo2
                                    class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                />
                                <span class="text-muted-foreground"
                                    >You undid this change.</span
                                >
                            </template>
                            <template v-else>
                                <CircleCheck
                                    class="mt-0.5 size-4 shrink-0 text-green-600"
                                />
                                <span
                                    >Kept. It's part of your app.<span
                                        v-if="request.tests_added > 0"
                                        class="ml-1 text-muted-foreground"
                                        data-test="change-kept-tests"
                                    >
                                        {{
                                            request.tests_added === 1
                                                ? 'Its test now runs on every change, so it keeps working.'
                                                : `Its ${request.tests_added} tests now run on every change, so it keeps working.`
                                        }}</span
                                    ></span
                                >
                                <Form
                                    v-bind="
                                        FeatureRequestReversionController.store.form(
                                            request.id,
                                        )
                                    "
                                    :options="{ preserveScroll: true }"
                                    class="-mt-3 ml-auto sm:-mt-1"
                                    v-slot="{ processing, errors }"
                                >
                                    <!-- Pulled up by half its extra height, so
                                         its word sits on the first line. -->
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

                <!-- What to ask for next -->
                <Transition
                    enter-active-class="transition duration-base ease-settle"
                    enter-from-class="opacity-0 translate-y-1"
                >
                    <div
                        v-if="nextIdeas.length > 0"
                        class="flex flex-wrap gap-2 pl-9.5"
                        data-test="thread-next"
                    >
                        <Form
                            v-for="idea in nextIdeas"
                            :key="idea"
                            v-bind="
                                FeatureRequestFollowUpController.store.form(
                                    request.id,
                                )
                            "
                            v-slot="{ processing }"
                        >
                            <input type="hidden" name="prompt" :value="idea" />
                            <button
                                :disabled="processing"
                                class="min-h-11 rounded-full border px-3 text-left text-sm text-muted-foreground select-none hover:border-foreground/30 hover:text-foreground disabled:opacity-50 sm:min-h-8"
                                data-test="next-idea"
                            >
                                {{ idea }}
                            </button>
                        </Form>
                    </div>
                </Transition>

                <!-- What was asked after this, in the same chat -->
                <div
                    v-if="change.followUps.length > 0"
                    class="space-y-1 border-t pt-3"
                    data-test="thread-later"
                >
                    <p class="text-xs text-muted-foreground">
                        Asked after this
                    </p>
                    <Link
                        v-for="later in change.followUps"
                        :key="later.id"
                        :href="
                            showProject(change.project.id, {
                                query: { change: later.id },
                            })
                        "
                        :only="['change']"
                        preserve-state
                        class="-mx-2 flex min-h-11 items-center gap-2 rounded-md px-2 text-sm hover:bg-muted/60 sm:min-h-8"
                    >
                        <span class="min-w-0 flex-1 truncate">{{
                            later.prompt
                        }}</span>
                        <ChevronRight
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                    </Link>
                </div>
            </div>

            <ChangeCode
                v-if="full"
                class="hidden lg:grid"
                :files="request.files"
            />
        </div>

        <!-- The decision stays in reach at the bottom -->
        <div
            v-if="request.can_accept"
            :class="[
                'space-y-2 border-t p-3',
                spread && 'px-[max(0.75rem,calc(50%-21rem))]',
            ]"
            data-test="change-decision"
        >
            <!-- Both buttons keep their places while the copy starts, so
                 nothing jumps under the owner's hand. -->
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
                <Button
                    v-else-if="change.preview?.status === 'starting'"
                    variant="outline"
                    disabled
                    class="h-11 flex-1 gap-1.5 select-none sm:h-9"
                    data-test="preview-starting"
                >
                    <LoaderCircle class="size-3.5 animate-spin" />
                    Getting it ready…
                </Button>
                <Form
                    v-else
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
                        <Spinner v-if="processing" />
                        {{ processing ? 'Keeping it' : 'Keep it' }}
                    </Button>
                    <InputError :message="errors.change" />
                </Form>
            </div>
        </div>
    </div>
</template>
