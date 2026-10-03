<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    ArrowUp,
    Check,
    CircleDashed,
    Download,
    LoaderCircle,
    Minus,
    Plus,
    RotateCcw,
    ShieldCheck,
    ArrowLeft,
    Bell,
    CalendarDays,
    ChevronDown,
    CircleCheck,
    CircleDot,
    FileDiff,
    LayoutGrid,
    Maximize2,
    MessageSquare,
    MousePointerClick,
    PanelLeft,
    RotateCw,
    Share2,
    Sparkles,
    Ticket,
} from '@lucide/vue';
import type { Directive } from 'vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { faults } from '@/lib/appFaults';
import { keepIdea } from '@/lib/startIdea';
import { login, register } from '@/routes';
import { index } from '@/routes/projects';
import type { DesignOption, Starter } from '@/types';

// The page answers one question: why build here and not with any other
// AI app builder? Apps made with them tend to stay prototypes, because
// the AI decides when its own work is done. Here the AI still plans and
// writes the code, and fixed checks decide whether it works. The page
// shows that working rather than saying it. Every line must stay true of
// the product as it is. Do not name the framework underneath; the owner
// chose to lead with the outcome. Do not claim "no AI", live hosting, or
// speed and cost against named tools. The usual way is never
// a named product. Pictures are placeholders: swap the files in
// public/images/product.

const props = defineProps<{
    designs: DesignOption[];
    starters: Starter[];
}>();

const page = usePage();
const still =
    typeof window !== 'undefined' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Blocks rise into place the first time they scroll into view.
const watched = new Map<Element, () => void>();
const seen =
    typeof IntersectionObserver === 'undefined'
        ? null
        : new IntersectionObserver(
              (entries) => {
                  for (const entry of entries) {
                      if (!entry.isIntersecting) {
                          continue;
                      }

                      (entry.target as HTMLElement).dataset.shown = '';
                      watched.get(entry.target)?.();
                      watched.delete(entry.target);
                      seen?.unobserve(entry.target);
                  }
              },
              { rootMargin: '0px 0px -12% 0px' },
          );

function whenSeen(element: Element, then: () => void = () => {}): void {
    if (seen === null) {
        (element as HTMLElement).dataset.shown = '';
        then();

        return;
    }

    watched.set(element, then);
    seen.observe(element);
}

const vReveal: Directive<HTMLElement> = {
    mounted: (element) => whenSeen(element),
};

const idea = ref('');
// The ready-made idea the box holds, while its words are unchanged.
const starter = ref<Starter | null>(null);
const ideaField = ref<HTMLTextAreaElement | null>(null);

// The empty box writes out the example ideas, one after another, so a
// visitor sees the kind of sentence that works.
const typed = ref(props.starters[0]?.purpose ?? '');
let typing: ReturnType<typeof setTimeout> | undefined;

function typeExamples(): void {
    const examples = props.starters.map((s) => s.purpose);

    if (examples.length === 0) {
        return;
    }

    let example = 0;
    let length = 0;
    let erasing = false;

    const step = (): void => {
        const text = examples[example];

        if (!erasing) {
            length += 1;
            typed.value = text.slice(0, length);
            erasing = length >= text.length;
            typing = setTimeout(step, erasing ? 2400 : 32);

            return;
        }

        length = Math.max(0, length - 4);
        typed.value = text.slice(0, length);

        if (length === 0) {
            erasing = false;
            example = (example + 1) % examples.length;
        }

        typing = setTimeout(step, length === 0 ? 500 : 14);
    };

    typed.value = '';
    typing = setTimeout(step, 700);
}

// A ready-made idea fills the box; from further down the page it also
// takes the visitor back up to it.
function useStarter(picked: Starter): void {
    idea.value = picked.purpose;
    starter.value = picked;
    backToStart();
}

// The idea waits in this tab; the new-app form on "Your apps" picks it up,
// after signing up if needed.
function start(): void {
    if (idea.value.trim() === '') {
        return;
    }

    keepIdea(
        idea.value.trim(),
        starter.value?.purpose === idea.value.trim() ? starter.value.key : null,
    );
    router.visit(page.props.auth.user ? index() : register());
}

// Enter starts the app, as in other builders; Shift and Enter adds a line.
function startOnEnter(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        start();
    }
}

// The last tile sends the visitor back up to the box, ready to type.
function backToStart(): void {
    window.scrollTo({ top: 0, behavior: still ? 'auto' : 'smooth' });
    ideaField.value?.focus({ preventScroll: true });
}

// Under the box, the real workspace plays one change from the ask to
// "Keep it", with the stages, words and layout the workspace uses. The
// same change runs through the rest of the page. Lines only ever appear
// below what is already there, as in the real thread, so nothing jumps.
// It plays once, and the visitor can play it again.
const heroChecks = [
    'Your app’s own tests passed',
    'The code fits together',
    'The code is set out tidily',
    'No packages with known security problems',
];

// The stages the workspace names, and the beat each one starts on.
const stageSteps = [
    { label: 'Working out what you need', from: 1 },
    { label: 'Making the change', from: 2 },
    { label: 'Checking it works', from: 3 },
    { label: 'Looking over what changed', from: 8 },
];

// How long each beat lasts: the ask, the stages, one beat per check, and
// the last look.
const beats = [900, 1500, 1500, 700, 500, 500, 500, 500, 1200];
const ready = beats.length;
const beat = ref(still ? ready : -1);
const elapsed = ref(0);
const stage = ref<HTMLElement | null>(null);
let beating: ReturnType<typeof setTimeout> | undefined;
let counting: ReturnType<typeof setInterval> | undefined;
let stageSeen: IntersectionObserver | undefined;

function playStage(): void {
    clearTimeout(beating);
    clearInterval(counting);
    clearInterval(counting);

    if (still) {
        beat.value = ready;

        return;
    }

    beat.value = 0;
    elapsed.value = 0;
    counting = setInterval(() => (elapsed.value += 1), 1000);

    const step = (): void => {
        if (beat.value >= ready) {
            clearInterval(counting);

            return;
        }

        beating = setTimeout(() => {
            beat.value += 1;
            step();
        }, beats[beat.value]);
    };

    step();
}

const working = computed(() => beat.value >= 1 && beat.value < ready);
const stageNow = computed(
    () =>
        [...stageSteps].reverse().find((step) => beat.value >= step.from)
            ?.label ?? '',
);
const checksDone = computed(() =>
    Math.min(heroChecks.length, Math.max(0, beat.value - 3)),
);

// The example app's own page, where the change shows up once it is ready.
const bookings = [
    { name: 'Pilates', when: 'Thursday, 18:30', late: false },
    { name: 'Morning yoga', when: 'Today, 18:00', late: true },
];

// One change, checked two ways. "Here" is what the platform does with
// every change; "alone" is an AI coding agent working on its own, which
// chooses its own checks. The steps play one by one, so the visitor
// watches the checking happen.
type Step = { label: string; note: string; passed: boolean };

const ways: Record<'here' | 'alone', { steps: Step[]; verdict: string }> = {
    here: {
        steps: [
            {
                label: 'The AI writes the change',
                note: 'It plans the change and writes the code.',
                passed: true,
            },
            {
                label: 'Your app’s own tests',
                note: 'The same tests run on every change.',
                passed: true,
            },
            {
                label: 'Email is down',
                note: 'Where the change runs, sending fails. Your app copes.',
                passed: true,
            },
            {
                label: 'Background work runs twice',
                note: 'Your app copes, and nothing happens twice.',
                passed: true,
            },
            {
                label: 'What else changed',
                note: 'Anything outside what you asked for is named.',
                passed: true,
            },
        ],
        verdict: 'The checks passed. It is ready for you to keep.',
    },
    alone: {
        steps: [
            {
                label: 'The AI writes the change',
                note: 'It plans the change and writes the code.',
                passed: true,
            },
            {
                label: 'Tests it chose to run',
                note: 'It picked which ones, and when to stop.',
                passed: true,
            },
            { label: 'Email is down', note: 'Not tried.', passed: false },
            {
                label: 'Background work runs twice',
                note: 'Not tried.',
                passed: false,
            },
            { label: 'What else changed', note: 'Not said.', passed: false },
        ],
        verdict: 'The AI says it is done.',
    },
};

const wayOptions = [
    { key: 'here', label: 'Here' },
    { key: 'alone', label: 'An AI agent on its own' },
] as const;

const way = ref<'here' | 'alone'>('here');
const shownSteps = ref(still ? 99 : 0);
const demo = ref<HTMLElement | null>(null);
let playing: ReturnType<typeof setInterval> | undefined;

function play(): void {
    clearInterval(playing);

    if (still) {
        shownSteps.value = 99;

        return;
    }

    shownSteps.value = 0;
    playing = setInterval(() => {
        shownSteps.value += 1;

        if (shownSteps.value > ways[way.value].steps.length) {
            clearInterval(playing);
        }
    }, 650);
}

watch(way, play);

// The screens, one at a time. The list moves on by itself while it is
// on screen, and stops for good once the visitor picks one.
const screens = [
    {
        key: 'talk',
        title: 'Ask in plain words',
        text: 'Say what to change, and watch your app beside the conversation.',
        image: '/images/product/workspace.webp',
        alt: 'The workspace: a conversation about the app beside the app itself',
    },
    {
        key: 'design',
        title: 'Point at anything, change it exactly',
        text: 'Design edits use no AI. Click any part of your app and set how it looks, then undo it if you change your mind.',
        image: '/images/product/design.webp',
        alt: 'The Book room button picked, with its words and text controls',
    },
    {
        key: 'fail',
        title: 'See what happens when things fail',
        text: 'Pick what goes wrong and use your app as a visitor would. Nothing is really sent or stored while you try it.',
        image: null,
        alt: '',
    },
];

const active = ref(0);
const picked = ref(false);
const hovering = ref(false);
const screensSeen = ref(false);
const screensBox = ref<HTMLElement | null>(null);
const cycling = computed(
    () => !still && !picked.value && screensSeen.value && !hovering.value,
);

function pick(at: number): void {
    picked.value = true;
    active.value = at;
}

function next(): void {
    active.value = (active.value + 1) % screens.length;
}

const fault = ref(faults[1]);

// A kept change reports how it knows each thing, in the words the change
// page uses. What nothing checked is named, never passed off as done.
type Mark = 'tested' | 'untouched' | 'unchecked';

const marks: { key: Mark; label: string }[] = [
    { key: 'tested', label: 'checked by a test' },
    { key: 'untouched', label: 'not touched by this change' },
    { key: 'unchecked', label: 'not checked yet' },
];

const markLabel = (key: Mark): string =>
    marks.find((mark) => mark.key === key)?.label ?? '';

const receipt: { title: string; lines: { text: string; mark: Mark }[] }[] = [
    {
        title: 'Done when',
        lines: [
            {
                text: 'A customer cancels a booking two days before',
                mark: 'tested',
            },
            {
                text: 'A cancel on the day itself is turned away',
                mark: 'tested',
            },
        ],
    },
    {
        title: 'Stays the same',
        lines: [
            { text: 'Customers book a class', mark: 'tested' },
            { text: 'Staff see the day’s bookings', mark: 'untouched' },
            { text: 'The booking email', mark: 'unchecked' },
        ],
    },
];

// The app's history: one commit per change kept. Undo adds a new commit
// on top, as the real undo does, and the first one stays in the history.
type Kept = { id: string; title: string; undoneBy?: string; undo?: boolean };

const history = ref<Kept[]>([
    {
        id: 'a41c9e2',
        title: 'Let customers cancel a booking up to a day before',
    },
    { id: '7d03b5f', title: 'Send a reminder the day before a class' },
    { id: 'e98a61c', title: 'Add a waiting list when a class is full' },
    { id: '3b2f7d0', title: 'Start Studio Classes' },
]);

function undo(kept: Kept): void {
    const id = Math.random().toString(16).slice(2, 9).padEnd(7, '0');

    kept.undoneBy = id;
    history.value.unshift({ id, title: `Undo “${kept.title}”`, undo: true });
}

// What every new app has before the owner asks for anything.
const included = [
    'Accounts and sign-in',
    'A real database you can look into',
    'Take payments with Stripe',
    'Send email with Resend',
    'A link anyone can use to try your app',
];

const questions = [
    {
        ask: 'Why do apps from AI builders stay prototypes?',
        answer: 'Because the AI decides when its own work is done. A change can quietly break something that worked, and nothing makes sure it is checked. Here the same fixed checks run on every change, and only they can pass it.',
    },
    {
        ask: 'Do I need to know how to code?',
        answer: 'No. You say what you want in plain words and see it in your app. Developers can still read every change in Git.',
    },
    {
        ask: 'Who owns the code?',
        answer: 'You do. Each change you keep is a commit in your app, and you can download the code any time.',
    },
    {
        ask: 'What happens when a change fails its checks?',
        answer: 'The attempt is rolled back, and your app stays as it was. You see what went wrong in plain words. When it is our fault, we say so.',
    },
    {
        ask: 'What if I change my mind?',
        answer: 'Undo any change you kept, on its own, even after others.',
    },
    {
        ask: 'Can my app take payments or send email?',
        answer: 'Yes. Add your own Stripe keys to take payments, and your own Resend key to send email.',
    },
];

onMounted(() => {
    if (!still) {
        typeExamples();
    }

    // It waits until a good part of it is on screen, so the visitor sees
    // it play rather than its end.
    if (stage.value !== null && typeof IntersectionObserver !== 'undefined') {
        stageSeen = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    stageSeen?.disconnect();
                    playStage();
                }
            },
            { threshold: 0.45 },
        );
        stageSeen.observe(stage.value);
    } else {
        playStage();
    }

    if (demo.value !== null) {
        whenSeen(demo.value, play);
    }

    if (screensBox.value !== null) {
        whenSeen(screensBox.value, () => (screensSeen.value = true));
    }
});

onBeforeUnmount(() => {
    clearTimeout(typing);
    clearTimeout(beating);
    clearInterval(counting);
    clearInterval(playing);
    seen?.disconnect();
    stageSeen?.disconnect();
});
</script>

<template>
    <Head title="Build real software without losing control" />

    <div class="min-h-svh overflow-x-clip bg-background text-foreground">
        <header class="sticky top-0 z-30 border-b bg-background">
            <div
                class="mx-auto flex h-12 max-w-6xl items-center justify-between gap-3 px-4"
            >
                <div class="flex min-w-0 items-center">
                    <AppLogo />
                </div>
                <nav class="flex items-center gap-1 text-sm">
                    <a
                        href="#different"
                        class="hidden min-h-11 items-center rounded-md px-3 text-muted-foreground select-none hover:text-foreground sm:inline-flex sm:min-h-8"
                        >What is different</a
                    >
                    <Link
                        v-if="$page.props.auth.user"
                        :href="index()"
                        class="inline-flex min-h-11 press items-center rounded-md bg-primary px-4 font-medium text-primary-foreground select-none hover:bg-primary/90 sm:min-h-8"
                        data-test="welcome-apps"
                    >
                        Your apps
                    </Link>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="inline-flex min-h-11 items-center rounded-md px-3 text-muted-foreground select-none hover:text-foreground sm:min-h-8"
                        >
                            Log in
                        </Link>
                        <Link
                            :href="register()"
                            class="inline-flex min-h-11 press items-center rounded-md bg-primary px-4 font-medium text-primary-foreground select-none hover:bg-primary/90 sm:min-h-8"
                            data-test="welcome-start"
                        >
                            Start an app
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <!-- Tiles run edge to edge with thin gutters, soft and inverted in
             turn. Space goes inside each tile, never around it. -->
        <main class="space-y-3 p-3">
            <!-- The first screen is the box: say what the app is for, and
                 go. What they type waits for them after signing up. Under
                 it, the workspace plays one change and runs off the bottom
                 edge of the tile. -->
            <section id="start" class="overflow-hidden bg-muted/50">
                <div
                    class="mx-auto flex max-w-3xl flex-col px-4 pt-20 text-center sm:pt-28"
                >
                    <p
                        v-reveal
                        class="mx-auto reveal rounded-md border bg-background px-3 py-1 text-sm text-muted-foreground"
                    >
                        Tired of apps that never get past the demo?
                    </p>
                    <h1
                        v-reveal
                        class="mt-6 reveal font-display text-4xl leading-[1.05] tracking-[-0.035em] text-balance delay-75 sm:text-6xl"
                    >
                        Build apps that don’t stay
                        <span class="text-muted-foreground">prototypes.</span>
                    </h1>
                    <p
                        v-reveal
                        class="mx-auto mt-5 max-w-2xl reveal text-lg text-balance text-muted-foreground delay-100"
                    >
                        With most AI builders, each change can break what
                        worked. Here every change passes fixed checks first.
                    </p>

                    <form
                        v-reveal
                        class="mx-auto mt-10 w-full max-w-2xl reveal text-left delay-150"
                        data-test="welcome-ask"
                        @submit.prevent="start"
                    >
                        <div
                            class="rounded-lg border border-input bg-background shadow-sm transition-shadow focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/30"
                        >
                            <label for="idea" class="sr-only"
                                >What do you want to make?</label
                            >
                            <textarea
                                id="idea"
                                ref="ideaField"
                                v-model="idea"
                                rows="3"
                                required
                                :placeholder="typed"
                                class="block w-full resize-none bg-transparent px-5 pt-4 pb-2 text-base outline-none placeholder:text-muted-foreground sm:text-lg"
                                data-test="welcome-idea"
                                @keydown="startOnEnter"
                            />
                            <div
                                class="flex items-center justify-between gap-3 pr-3 pb-3 pl-5"
                            >
                                <span class="text-sm text-muted-foreground"
                                    >Real code that stays yours.</span
                                >
                                <button
                                    type="submit"
                                    class="flex size-11 shrink-0 press items-center justify-center rounded-full bg-primary text-primary-foreground select-none hover:bg-primary/90 disabled:opacity-40 sm:size-10"
                                    :disabled="idea.trim() === ''"
                                    aria-label="Start my app"
                                    title="Start my app"
                                    data-test="welcome-hero-start"
                                >
                                    <ArrowUp class="size-4" />
                                </button>
                            </div>
                        </div>
                    </form>

                    <div
                        v-reveal
                        class="mt-4 flex reveal flex-wrap justify-center gap-2 delay-200"
                        aria-label="Ideas to start from"
                    >
                        <button
                            v-for="item in starters"
                            :key="item.key"
                            type="button"
                            class="min-h-11 press rounded-md border bg-background px-3 text-sm text-muted-foreground select-none hover:text-foreground sm:min-h-9"
                            @click="useStarter(item)"
                        >
                            {{ item.name }}
                        </button>
                    </div>
                </div>

                <figure
                    ref="stage"
                    v-reveal
                    class="mx-auto mt-16 max-w-6xl reveal px-4 delay-300 sm:mt-20 sm:px-10"
                    data-test="welcome-stage"
                >
                    <figcaption class="sr-only">
                        An example in the workspace: one change, from the ask to
                        ready for you to keep.
                    </figcaption>
                    <!-- The workspace, drawn with its own layout and words.
                         It is a picture: nothing in it can be pressed except
                         Play again. -->
                    <div
                        class="overflow-hidden rounded-t-xl border border-b-0 bg-background text-left shadow-[0_-8px_60px_-24px_rgb(0_0_0/0.25)] select-none"
                    >
                        <div
                            class="flex h-12 items-center justify-between gap-3 border-b px-3 text-sm"
                            aria-hidden="true"
                        >
                            <span class="flex min-w-0 items-center gap-3">
                                <ArrowLeft
                                    class="size-4 shrink-0 text-muted-foreground"
                                />
                                <span
                                    class="flex min-w-0 items-center gap-1.5 font-semibold"
                                >
                                    <span
                                        class="size-1.5 shrink-0 rounded-full bg-muted-foreground/60"
                                    />
                                    <span class="truncate">Studio Classes</span>
                                    <ChevronDown
                                        class="size-3.5 shrink-0 text-muted-foreground"
                                    />
                                </span>
                                <span
                                    class="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground tabular-nums"
                                >
                                    <ShieldCheck
                                        class="size-3.5 text-emerald-600 dark:text-emerald-400"
                                    />
                                    {{ beat >= ready ? 44 : 42 }} tests
                                </span>
                            </span>
                            <span class="flex items-center gap-2">
                                <Bell
                                    class="hidden size-4 text-muted-foreground sm:block"
                                />
                                <span
                                    class="inline-flex h-8 items-center gap-1.5 rounded-md border px-3 font-medium"
                                >
                                    <Share2 class="size-3.5" />
                                    Share
                                </span>
                            </span>
                        </div>

                        <div
                            class="grid h-[34rem] md:grid-cols-[21rem_minmax(0,1fr)]"
                        >
                            <div class="flex min-h-0 flex-col md:border-r">
                                <div
                                    class="flex items-center gap-2 border-b p-2"
                                    aria-hidden="true"
                                >
                                    <span
                                        class="grid flex-1 grid-cols-2 rounded-md bg-muted p-0.5 text-sm"
                                    >
                                        <span
                                            class="flex h-8 items-center justify-center gap-1.5 rounded-sm bg-background font-semibold shadow-xs"
                                        >
                                            <MessageSquare class="size-3.5" />
                                            Chat
                                        </span>
                                        <span
                                            class="flex h-8 items-center justify-center gap-1.5 text-muted-foreground"
                                        >
                                            <MousePointerClick
                                                class="size-3.5"
                                            />
                                            Design
                                        </span>
                                    </span>
                                    <Maximize2
                                        class="mx-1.5 size-3.5 text-muted-foreground"
                                    />
                                </div>
                                <div
                                    class="flex items-center justify-between border-b px-4 py-2.5 text-sm text-muted-foreground"
                                    aria-hidden="true"
                                >
                                    <span class="flex items-center gap-1.5">
                                        <ArrowLeft class="size-3.5" />
                                        All changes
                                    </span>
                                    <span class="flex items-center gap-1.5">
                                        <FileDiff class="size-3.5" />
                                        Details
                                    </span>
                                </div>

                                <div
                                    class="min-h-0 flex-1 space-y-4 overflow-hidden p-4"
                                >
                                    <div class="flex justify-end">
                                        <p
                                            class="max-w-[85%] rounded-lg rounded-br-sm bg-muted px-3.5 py-2.5 text-sm"
                                        >
                                            Let customers cancel a booking up to
                                            a day before.
                                        </p>
                                    </div>

                                    <div
                                        :class="[
                                            'flex gap-2.5 transition-opacity duration-base',
                                            beat >= 1
                                                ? 'opacity-100'
                                                : 'opacity-0',
                                        ]"
                                    >
                                        <span
                                            class="grid size-7 shrink-0 place-items-center rounded-full bg-muted"
                                            aria-hidden="true"
                                        >
                                            <Sparkles class="size-3.5" />
                                        </span>
                                        <div
                                            class="min-w-0 flex-1 space-y-3 pt-0.5 text-sm"
                                        >
                                            <p class="leading-relaxed">
                                                Adds a way to cancel a booking
                                                until a day before the class,
                                                and turns away later cancels.
                                            </p>

                                            <ol
                                                v-if="beat < ready"
                                                class="space-y-1.5"
                                            >
                                                <template
                                                    v-for="(
                                                        step, at
                                                    ) in stageSteps"
                                                    :key="step.label"
                                                >
                                                    <li
                                                        v-if="beat >= step.from"
                                                        class="flex items-center gap-2 pt-1 text-xs font-medium"
                                                    >
                                                        <CircleDot
                                                            class="size-3.5 shrink-0 text-muted-foreground"
                                                        />
                                                        {{ step.label }}
                                                    </li>
                                                    <template v-if="at === 2">
                                                        <li
                                                            v-for="check in heroChecks.slice(
                                                                0,
                                                                checksDone,
                                                            )"
                                                            :key="check"
                                                            class="flex items-center gap-2 text-xs text-muted-foreground"
                                                        >
                                                            <CircleCheck
                                                                class="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
                                                            />
                                                            {{ check }}
                                                        </li>
                                                    </template>
                                                </template>
                                            </ol>

                                            <p
                                                v-if="working"
                                                class="flex items-center gap-2 text-muted-foreground"
                                            >
                                                <LoaderCircle
                                                    class="size-4 animate-spin"
                                                />
                                                {{ stageNow }}…
                                                <span
                                                    class="text-xs tabular-nums"
                                                    >{{ elapsed }}s</span
                                                >
                                            </p>

                                            <template v-if="beat >= ready">
                                                <div class="flex gap-2">
                                                    <Check
                                                        class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                                                    />
                                                    <span>
                                                        <span
                                                            class="block font-medium"
                                                            >Cancelling a
                                                            booking</span
                                                        >
                                                        <span
                                                            class="block text-xs text-muted-foreground"
                                                            >Customers cancel up
                                                            to a day before.
                                                            After that, it says
                                                            it is too
                                                            late.</span
                                                        >
                                                    </span>
                                                </div>
                                                <p
                                                    class="flex gap-2 text-xs text-muted-foreground"
                                                >
                                                    <ShieldCheck
                                                        class="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
                                                    />
                                                    2 new tests passed, with the
                                                    app’s other 42.
                                                </p>
                                            </template>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    :class="[
                                        'flex gap-2 border-t px-4 pt-3 transition-opacity duration-panel',
                                        beat >= ready
                                            ? 'opacity-100'
                                            : 'pointer-events-none opacity-0',
                                    ]"
                                >
                                    <span
                                        class="flex h-9 flex-1 items-center justify-center rounded-md border text-sm font-medium"
                                        aria-hidden="true"
                                        >Try it first</span
                                    >
                                    <span
                                        class="flex h-9 flex-1 items-center justify-center rounded-md bg-primary text-sm font-medium text-primary-foreground"
                                        aria-hidden="true"
                                        >Keep it</span
                                    >
                                </div>
                                <div class="flex items-center gap-2 p-3">
                                    <span
                                        class="flex h-10 flex-1 items-center rounded-md border px-3 text-sm text-muted-foreground"
                                        aria-hidden="true"
                                        >Reply or ask for more…</span
                                    >
                                    <button
                                        v-if="!still"
                                        type="button"
                                        :class="[
                                            'grid size-10 shrink-0 press place-items-center rounded-md text-muted-foreground transition-opacity hover:bg-muted hover:text-foreground',
                                            beat >= ready
                                                ? 'opacity-100'
                                                : 'pointer-events-none opacity-0',
                                        ]"
                                        :tabindex="beat >= ready ? 0 : -1"
                                        aria-label="Play the example again"
                                        title="Play again"
                                        @click="playStage"
                                    >
                                        <RotateCcw class="size-4" />
                                    </button>
                                </div>
                            </div>

                            <!-- The app beside the chat, with the browser bar
                                 and tabs of the real workspace. -->
                            <div
                                class="hidden min-h-0 flex-col bg-muted/30 p-3 md:flex"
                                aria-hidden="true"
                            >
                                <div
                                    class="flex items-center gap-3 px-1 pb-3 text-sm text-muted-foreground"
                                >
                                    <ArrowLeft class="size-4" />
                                    <ArrowRight class="size-4" />
                                    <RotateCw class="size-4" />
                                    <span
                                        class="flex items-center gap-1 text-foreground"
                                        >/bookings
                                        <ChevronDown class="size-3.5" />
                                    </span>
                                    <span
                                        class="ml-auto hidden items-center gap-5 lg:flex"
                                    >
                                        <span
                                            class="border-b-2 border-foreground pb-1 text-foreground"
                                            >App</span
                                        >
                                        <span class="pb-1.5">Messages</span>
                                        <span class="pb-1.5">Problems</span>
                                        <span class="pb-1.5">Saved data</span>
                                        <span class="pb-1.5"
                                            >What happened</span
                                        >
                                    </span>
                                </div>
                                <div
                                    class="flex min-h-0 flex-1 overflow-hidden rounded-lg border bg-background"
                                >
                                    <div
                                        class="hidden w-48 shrink-0 flex-col border-r bg-muted/30 p-3 text-sm lg:flex"
                                    >
                                        <span
                                            class="flex items-center gap-2 font-semibold"
                                        >
                                            <span
                                                class="grid size-7 place-items-center rounded-md bg-foreground text-background"
                                            >
                                                <CalendarDays class="size-4" />
                                            </span>
                                            Studio Classes
                                        </span>
                                        <span
                                            class="mt-5 text-xs text-muted-foreground"
                                            >Platform</span
                                        >
                                        <span
                                            class="mt-2 flex items-center gap-2 rounded-md px-2 py-1.5"
                                        >
                                            <LayoutGrid class="size-4" />
                                            Dashboard
                                        </span>
                                        <span
                                            class="flex items-center gap-2 rounded-md px-2 py-1.5"
                                        >
                                            <CalendarDays class="size-4" />
                                            Classes
                                        </span>
                                        <span
                                            class="flex items-center gap-2 rounded-md bg-muted px-2 py-1.5 font-medium"
                                        >
                                            <Ticket class="size-4" />
                                            Your bookings
                                        </span>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="flex items-center gap-2 border-b px-4 py-3 text-sm"
                                        >
                                            <PanelLeft
                                                class="size-4 text-muted-foreground"
                                            />
                                            Your bookings
                                        </div>
                                        <div class="p-4">
                                            <div
                                                class="rounded-xl border p-5 shadow-xs"
                                            >
                                                <p class="font-semibold">
                                                    Your bookings
                                                </p>
                                                <p
                                                    class="mt-1 text-sm text-muted-foreground"
                                                >
                                                    Classes you have a place in.
                                                </p>
                                                <ul
                                                    class="mt-4 divide-y border-y"
                                                >
                                                    <li
                                                        v-for="booking in bookings"
                                                        :key="booking.name"
                                                        class="flex min-h-14 items-center justify-between gap-3 py-2 text-sm"
                                                    >
                                                        <span class="min-w-0">
                                                            <span
                                                                class="block font-medium"
                                                                >{{
                                                                    booking.name
                                                                }}</span
                                                            >
                                                            <span
                                                                class="block text-muted-foreground"
                                                                >{{
                                                                    booking.when
                                                                }}</span
                                                            >
                                                        </span>
                                                        <span
                                                            :class="[
                                                                'shrink-0 transition-opacity duration-panel',
                                                                beat >= ready
                                                                    ? 'opacity-100'
                                                                    : 'opacity-0',
                                                            ]"
                                                        >
                                                            <span
                                                                v-if="
                                                                    booking.late
                                                                "
                                                                class="text-muted-foreground"
                                                                >Too late to
                                                                cancel</span
                                                            >
                                                            <span
                                                                v-else
                                                                :class="[
                                                                    'inline-flex h-8 items-center rounded-md border px-3 font-medium',
                                                                    beat >=
                                                                        ready &&
                                                                        !still &&
                                                                        'animate-[ring-out_1.6s_ease-out_both]',
                                                                ]"
                                                                >Cancel</span
                                                            >
                                                        </span>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </figure>
            </section>

            <!-- The difference, shown: one change checked here, then by an
                 AI agent on its own. -->
            <section
                id="different"
                class="scroll-mt-12 bg-foreground text-background"
                data-test="welcome-different"
            >
                <div
                    class="mx-auto grid max-w-6xl gap-12 px-4 py-20 sm:px-10 sm:py-28 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:items-center lg:gap-16"
                >
                    <div v-reveal class="reveal">
                        <p
                            v-reveal
                            class="mb-5 reveal text-sm text-background/55 tabular-nums"
                        >
                            01&ensp;The checks
                        </p>
                        <h2
                            class="font-display text-3xl leading-[1.05] tracking-[-0.03em] text-balance sm:text-5xl"
                        >
                            The AI writes the code. Fixed checks decide if it
                            works.
                        </h2>
                        <p class="mt-5 text-lg text-pretty text-background/65">
                            An AI agent on its own picks its checks and decides
                            when it is done. Here the same checks run on every
                            change, and only they can pass it.
                        </p>
                        <div
                            class="mt-8 inline-flex flex-wrap rounded-md border border-background/20 p-1"
                            role="group"
                            aria-label="Who checks the change"
                        >
                            <button
                                v-for="option in wayOptions"
                                :key="option.key"
                                type="button"
                                :aria-pressed="way === option.key"
                                :class="[
                                    'min-h-11 rounded-sm px-4 text-sm font-medium transition-colors select-none sm:min-h-9',
                                    way === option.key
                                        ? 'bg-background text-foreground'
                                        : 'text-background/65 hover:text-background',
                                ]"
                                @click="way = option.key"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                    </div>

                    <div
                        ref="demo"
                        v-reveal
                        class="reveal rounded-xl border border-background/15 bg-background/5 p-2 delay-100"
                    >
                        <div
                            class="rounded-lg bg-background p-5 text-foreground sm:p-7"
                        >
                            <p class="text-sm text-muted-foreground">
                                You asked
                            </p>
                            <p class="mt-1 font-medium text-pretty">
                                Let customers cancel a booking up to a day
                                before.
                            </p>
                            <ol class="mt-6 divide-y border-y">
                                <li
                                    v-for="(step, at) in ways[way].steps"
                                    :key="`${way}-${step.label}`"
                                    :class="[
                                        'flex gap-3 py-3.5 transition-[opacity,translate] duration-panel',
                                        at < shownSteps
                                            ? 'opacity-100'
                                            : at === shownSteps
                                              ? 'opacity-60'
                                              : 'translate-y-1 opacity-0',
                                    ]"
                                >
                                    <span
                                        class="mt-0.5 flex size-5 shrink-0 items-center justify-center"
                                    >
                                        <LoaderCircle
                                            v-if="at === shownSteps"
                                            class="size-4 animate-spin text-muted-foreground"
                                        />
                                        <Check
                                            v-else-if="step.passed"
                                            class="size-4 text-emerald-600 dark:text-emerald-400"
                                            aria-label="Passed"
                                        />
                                        <Minus
                                            v-else
                                            class="size-4 text-muted-foreground"
                                            aria-label="Skipped"
                                        />
                                    </span>
                                    <span class="min-w-0">
                                        <span
                                            :class="[
                                                'block font-medium',
                                                !step.passed &&
                                                    'text-muted-foreground line-through decoration-muted-foreground/50',
                                            ]"
                                            >{{ step.label }}</span
                                        >
                                        <span
                                            class="block text-sm text-pretty text-muted-foreground"
                                            >{{ step.note }}</span
                                        >
                                    </span>
                                </li>
                            </ol>
                            <p
                                :class="[
                                    'mt-5 flex min-h-6 items-center gap-2 text-sm font-medium transition-opacity duration-panel',
                                    shownSteps > ways[way].steps.length
                                        ? 'opacity-100'
                                        : 'opacity-0',
                                    way === 'here'
                                        ? 'text-emerald-700 dark:text-emerald-400'
                                        : 'text-muted-foreground',
                                ]"
                            >
                                {{ ways[way].verdict }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- The product, one screen at a time. -->
            <section class="bg-muted/50">
                <div class="mx-auto max-w-6xl px-4 py-20 sm:px-10 sm:py-28">
                    <p
                        v-reveal
                        class="mb-5 reveal text-sm text-muted-foreground tabular-nums"
                    >
                        02&ensp;The workspace
                    </p>
                    <h2
                        v-reveal
                        class="max-w-2xl reveal font-display text-3xl leading-[1.05] tracking-[-0.03em] text-balance sm:text-5xl"
                    >
                        See it work before you keep it.
                    </h2>

                    <div
                        ref="screensBox"
                        v-reveal
                        class="mt-12 grid reveal gap-10 delay-100 lg:grid-cols-[minmax(0,4fr)_minmax(0,7fr)] lg:items-center lg:gap-14"
                        @pointerenter="hovering = true"
                        @pointerleave="hovering = false"
                        @focusin="hovering = true"
                        @focusout="hovering = false"
                    >
                        <ul class="divide-y border-y">
                            <li
                                v-for="(screen, at) in screens"
                                :key="screen.key"
                            >
                                <button
                                    type="button"
                                    :aria-expanded="active === at"
                                    class="relative block min-h-11 w-full py-4 text-left select-none"
                                    @click="pick(at)"
                                >
                                    <span
                                        :class="[
                                            'block font-display text-lg tracking-[-0.015em] transition-colors sm:text-xl',
                                            active === at
                                                ? 'text-foreground'
                                                : 'text-muted-foreground hover:text-foreground',
                                        ]"
                                        >{{ screen.title }}</span
                                    >
                                    <span
                                        :class="[
                                            'grid transition-[grid-template-rows,opacity] duration-panel',
                                            active === at
                                                ? 'grid-rows-[1fr] opacity-100'
                                                : 'grid-rows-[0fr] opacity-0',
                                        ]"
                                    >
                                        <span
                                            class="overflow-hidden text-pretty text-muted-foreground"
                                        >
                                            <span class="block pt-2">{{
                                                screen.text
                                            }}</span>
                                        </span>
                                    </span>
                                    <span
                                        v-if="active === at && cycling"
                                        :key="`bar-${at}`"
                                        class="absolute inset-x-0 -bottom-px h-px origin-left animate-[fill-across_7s_linear_forwards] bg-foreground"
                                        aria-hidden="true"
                                        @animationend="next"
                                    />
                                </button>
                            </li>
                        </ul>

                        <div
                            class="relative aspect-[4/5] overflow-hidden rounded-lg border bg-background sm:aspect-[16/11]"
                            data-test="welcome-example"
                        >
                            <template
                                v-for="(screen, at) in screens"
                                :key="screen.key"
                            >
                                <img
                                    v-if="screen.image"
                                    :src="screen.image"
                                    :alt="active === at ? screen.alt : ''"
                                    :aria-hidden="active !== at"
                                    loading="lazy"
                                    :class="[
                                        'absolute inset-0 size-full object-cover object-top-left transition-[opacity,scale] duration-linger',
                                        active === at
                                            ? 'scale-100 opacity-100'
                                            : 'scale-[1.02] opacity-0',
                                    ]"
                                />
                                <div
                                    v-else
                                    :class="[
                                        'absolute inset-0 flex flex-col justify-center p-6 transition-opacity duration-linger sm:p-10',
                                        active === at
                                            ? 'opacity-100'
                                            : 'pointer-events-none opacity-0',
                                    ]"
                                    :aria-hidden="active !== at"
                                >
                                    <p class="text-sm text-muted-foreground">
                                        Pretend this is down
                                    </p>
                                    <div
                                        class="mt-3 flex flex-wrap gap-2"
                                        role="group"
                                        aria-label="What you can pretend is down"
                                    >
                                        <button
                                            v-for="option in faults"
                                            :key="option.key"
                                            type="button"
                                            :tabindex="active === at ? 0 : -1"
                                            :aria-pressed="
                                                fault.key === option.key
                                            "
                                            :class="[
                                                'min-h-11 press rounded-md border px-3 text-sm select-none sm:min-h-9',
                                                fault.key === option.key
                                                    ? 'border-foreground bg-foreground font-medium text-background'
                                                    : 'text-muted-foreground hover:text-foreground',
                                            ]"
                                            @click="fault = option"
                                        >
                                            {{ option.label }}
                                        </button>
                                    </div>
                                    <p
                                        class="mt-5 min-h-12 max-w-md text-pretty"
                                    >
                                        {{
                                            fault.hint ||
                                            'Your app runs as it always does.'
                                        }}
                                    </p>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </section>

            <!-- How a kept change reports itself: each line says how the
                 app knows it, and what nothing checked is named. -->
            <section
                class="bg-foreground text-background"
                data-test="welcome-receipt"
            >
                <div
                    class="mx-auto grid max-w-6xl gap-12 px-4 py-20 sm:px-10 sm:py-28 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:items-center lg:gap-16"
                >
                    <div v-reveal class="reveal">
                        <p
                            v-reveal
                            class="mb-5 reveal text-sm text-background/55 tabular-nums"
                        >
                            03&ensp;The proof
                        </p>
                        <h2
                            class="font-display text-3xl leading-[1.05] tracking-[-0.03em] text-balance sm:text-5xl"
                        >
                            It tells you how it knows.
                        </h2>
                        <p class="mt-5 text-lg text-pretty text-background/65">
                            Every change you keep says what a test proved. What
                            nothing checked is named too, never passed off as
                            done.
                        </p>
                        <ul class="mt-8 space-y-2.5 text-sm">
                            <li
                                v-for="mark in marks"
                                :key="mark.key"
                                class="flex items-center gap-2.5"
                            >
                                <Check
                                    v-if="mark.key === 'tested'"
                                    class="size-4 text-emerald-400 dark:text-emerald-600"
                                />
                                <Minus
                                    v-else-if="mark.key === 'untouched'"
                                    class="size-4 text-background/50"
                                />
                                <CircleDashed
                                    v-else
                                    class="size-4 text-background/50"
                                />
                                <span class="first-letter:uppercase">{{
                                    mark.label
                                }}</span>
                            </li>
                        </ul>
                    </div>

                    <div
                        v-reveal
                        class="reveal rounded-xl border border-background/15 bg-background/5 p-2 delay-100"
                    >
                        <div
                            class="rounded-lg bg-background p-5 text-foreground sm:p-7"
                        >
                            <p class="text-sm text-muted-foreground">
                                You kept
                            </p>
                            <p class="mt-1 font-medium text-pretty">
                                Let customers cancel a booking up to a day
                                before.
                            </p>
                            <p
                                class="mt-5 flex flex-wrap items-baseline gap-x-2 border-t pt-4 text-sm"
                            >
                                <span
                                    class="font-medium text-amber-600 dark:text-amber-400"
                                    >Checked, with gaps</span
                                >
                                <span class="text-muted-foreground"
                                    >One thing below is not checked yet.</span
                                >
                            </p>
                            <div
                                v-for="group in receipt"
                                :key="group.title"
                                class="mt-6"
                            >
                                <h3 class="text-sm font-medium">
                                    {{ group.title }}
                                </h3>
                                <ul class="mt-2 divide-y border-y">
                                    <li
                                        v-for="line in group.lines"
                                        :key="line.text"
                                        class="grid grid-cols-[1.25rem_minmax(0,1fr)] gap-x-2 py-2.5"
                                    >
                                        <Check
                                            v-if="line.mark === 'tested'"
                                            class="mt-0.5 size-4 text-emerald-600 dark:text-emerald-400"
                                            aria-hidden="true"
                                        />
                                        <Minus
                                            v-else-if="
                                                line.mark === 'untouched'
                                            "
                                            class="mt-0.5 size-4 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <CircleDashed
                                            v-else
                                            class="mt-0.5 size-4 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <span class="text-pretty">
                                            {{ line.text }}
                                            <span
                                                class="block text-sm text-muted-foreground"
                                                >{{
                                                    markLabel(line.mark)
                                                }}</span
                                            >
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- The app stays an ordinary app with its own history: undo
                 any kept change, or take the code away. -->
            <section class="bg-muted/50" data-test="welcome-yours">
                <div
                    class="mx-auto grid max-w-6xl gap-12 px-4 py-20 sm:px-10 sm:py-28 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:items-center lg:gap-16"
                >
                    <div v-reveal class="reveal">
                        <p
                            v-reveal
                            class="mb-5 reveal text-sm text-muted-foreground tabular-nums"
                        >
                            04&ensp;Your code
                        </p>
                        <h2
                            class="font-display text-3xl leading-[1.05] tracking-[-0.03em] text-balance sm:text-5xl"
                        >
                            It stays yours.
                        </h2>
                        <p
                            class="mt-5 text-lg text-pretty text-muted-foreground"
                        >
                            Each change you keep is one step in your app’s
                            history. Undo any of them, even after others, or
                            download the code and run it without us.
                        </p>
                        <h3 class="mt-10 text-sm font-medium">
                            In every app from the first version
                        </h3>
                        <ul class="mt-3 space-y-2 text-sm">
                            <li
                                v-for="item in included"
                                :key="item"
                                class="flex gap-2"
                            >
                                <Check
                                    class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                                />
                                <span class="text-pretty">{{ item }}</span>
                            </li>
                        </ul>
                    </div>

                    <div
                        v-reveal
                        class="reveal overflow-hidden rounded-lg border bg-background delay-100"
                    >
                        <div
                            class="flex items-center justify-between gap-3 border-b px-5 py-3"
                        >
                            <p class="text-sm font-medium">History</p>
                            <span
                                class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-sm text-muted-foreground"
                            >
                                <Download class="size-3.5" />
                                Download code
                            </span>
                        </div>
                        <TransitionGroup
                            tag="ol"
                            class="divide-y"
                            enter-from-class="-translate-y-2 opacity-0"
                            enter-active-class="transition-[opacity,translate] duration-panel ease-settle"
                            move-class="transition-transform duration-panel ease-settle"
                        >
                            <li
                                v-for="(kept, at) in history"
                                :key="kept.id"
                                class="flex min-h-14 items-center gap-4 px-5 py-1.5"
                            >
                                <code
                                    class="shrink-0 font-mono text-xs text-muted-foreground"
                                    >{{ kept.id }}</code
                                >
                                <span
                                    :class="[
                                        'min-w-0 flex-1 truncate text-sm',
                                        kept.undoneBy &&
                                            'text-muted-foreground line-through decoration-muted-foreground/50',
                                    ]"
                                    >{{ kept.title }}</span
                                >
                                <span
                                    v-if="kept.undoneBy"
                                    class="shrink-0 text-xs text-muted-foreground"
                                    >Undone</span
                                >
                                <button
                                    v-else-if="
                                        !kept.undo && at < history.length - 1
                                    "
                                    type="button"
                                    class="min-h-11 shrink-0 press rounded-md px-2 text-sm text-muted-foreground select-none hover:bg-muted hover:text-foreground sm:min-h-8"
                                    :data-test="
                                        at === 0 ? 'welcome-undo' : undefined
                                    "
                                    @click="undo(kept)"
                                >
                                    Undo
                                </button>
                            </li>
                        </TransitionGroup>
                    </div>
                </div>
            </section>

            <!-- Ready-made ideas and looks: the same ones a new app can
                 start from. Picking one fills the box at the top. -->
            <section
                id="ideas"
                class="scroll-mt-12 bg-foreground text-background"
            >
                <div class="mx-auto max-w-6xl px-4 py-20 sm:px-10 sm:py-28">
                    <div
                        v-reveal
                        class="grid reveal gap-6 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)] lg:items-end lg:gap-12"
                    >
                        <div>
                            <p
                                v-reveal
                                class="mb-5 reveal text-sm text-background/55 tabular-nums"
                            >
                                05&ensp;Ideas
                            </p>
                            <h2
                                class="font-display text-3xl leading-[1.05] tracking-[-0.03em] text-balance sm:text-5xl"
                            >
                                Start from a ready-made idea.
                            </h2>
                        </div>
                        <p class="text-lg text-pretty text-background/65">
                            Each one fills in the idea, the look and what the
                            first version includes. Change any of it, or write
                            your own.
                        </p>
                    </div>

                    <ul
                        v-reveal
                        class="mt-12 grid reveal gap-x-10 gap-y-10 delay-100 sm:grid-cols-2 lg:grid-cols-3"
                        data-test="welcome-starters"
                    >
                        <li
                            v-for="starter in starters"
                            :key="starter.key"
                            class="flex flex-col border-t border-background/15 pt-5"
                        >
                            <h3 class="font-medium">{{ starter.name }}</h3>
                            <p class="mt-1 text-pretty text-background/65">
                                {{ starter.purpose }}
                            </p>
                            <ul
                                class="mt-4 space-y-1.5 text-sm text-background/80"
                            >
                                <li
                                    v-for="item in starter.includes.slice(0, 3)"
                                    :key="item"
                                    class="flex gap-2"
                                >
                                    <Check
                                        class="mt-0.5 size-4 shrink-0 text-background/50"
                                    />
                                    <span class="text-pretty">{{ item }}</span>
                                </li>
                            </ul>
                            <button
                                type="button"
                                class="mt-5 inline-flex min-h-11 items-center gap-1.5 self-start text-sm font-medium select-none hover:underline sm:min-h-8"
                                @click="useStarter(starter)"
                            >
                                Start with this
                                <ArrowRight class="size-4" />
                            </button>
                        </li>
                    </ul>

                    <div
                        v-if="designs.length > 0"
                        v-reveal
                        class="mt-16 reveal border-t border-background/15 pt-8"
                    >
                        <h3 class="font-medium">Four looks to begin with</h3>
                        <p class="mt-1 text-background/65">
                            Every look is yours to change, part by part.
                        </p>
                        <ul
                            class="mt-6 grid gap-x-10 gap-y-6 sm:grid-cols-2 lg:grid-cols-4"
                        >
                            <li
                                v-for="design in designs"
                                :key="design.key"
                                class="flex gap-3"
                            >
                                <span
                                    aria-hidden="true"
                                    class="mt-0.5 size-8 shrink-0 rounded-full border border-background/20"
                                    :style="{
                                        background: `linear-gradient(135deg, ${design.colors.background} 50%, ${design.colors.primary} 50%)`,
                                    }"
                                />
                                <span>
                                    <span class="block font-medium">{{
                                        design.name
                                    }}</span>
                                    <span
                                        class="block text-sm text-pretty text-background/65"
                                        >{{ design.description }}</span
                                    >
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- Plain answers to what people ask before they start. -->
            <section class="bg-muted/50">
                <div
                    class="mx-auto grid max-w-6xl gap-10 px-4 py-20 sm:px-10 sm:py-28 lg:grid-cols-[minmax(0,4fr)_minmax(0,7fr)] lg:gap-16"
                >
                    <div>
                        <p
                            v-reveal
                            class="mb-5 reveal text-sm text-muted-foreground tabular-nums"
                        >
                            06&ensp;Questions
                        </p>
                        <h2
                            v-reveal
                            class="reveal font-display text-3xl leading-[1.05] tracking-[-0.03em] text-balance sm:text-5xl"
                        >
                            Questions people ask.
                        </h2>
                    </div>
                    <div
                        v-reveal
                        class="reveal divide-y border-y delay-100"
                        data-test="welcome-questions"
                    >
                        <details
                            v-for="question in questions"
                            :key="question.ask"
                            class="group"
                        >
                            <summary
                                class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-4 py-4 font-medium select-none [&::-webkit-details-marker]:hidden"
                            >
                                {{ question.ask }}
                                <Plus
                                    class="size-4 shrink-0 text-muted-foreground transition-transform duration-base group-open:rotate-45"
                                    aria-hidden="true"
                                />
                            </summary>
                            <p
                                class="max-w-2xl pb-5 text-pretty text-muted-foreground"
                            >
                                {{ question.answer }}
                            </p>
                        </details>
                    </div>
                </div>
            </section>

            <!-- The page ends where it began: a box to start from, so no
                 one has to scroll back up. It shares the idea with the
                 box at the top. -->
            <section class="bg-foreground text-background">
                <div class="mx-auto max-w-6xl px-4 py-24 sm:px-10 sm:py-36">
                    <h2
                        v-reveal
                        class="max-w-3xl reveal font-display text-4xl leading-[1.02] tracking-[-0.035em] text-balance sm:text-7xl"
                    >
                        Start with a sentence.
                        <span class="text-background/50"
                            >Keep only what passes.</span
                        >
                    </h2>
                    <form
                        v-reveal
                        class="mt-12 flex max-w-2xl reveal gap-2 rounded-lg bg-background p-2 text-foreground delay-100 focus-within:ring-[3px] focus-within:ring-ring/50"
                        data-test="welcome-end"
                        @submit.prevent="start"
                    >
                        <label for="end-idea" class="sr-only"
                            >What do you want to make?</label
                        >
                        <input
                            id="end-idea"
                            v-model="idea"
                            type="text"
                            required
                            placeholder="Describe your app in a sentence"
                            class="min-w-0 flex-1 bg-transparent px-3 text-base outline-none placeholder:text-muted-foreground"
                            data-test="welcome-end-idea"
                        />
                        <button
                            type="submit"
                            class="inline-flex min-h-11 shrink-0 press items-center gap-2 rounded-md bg-primary px-5 font-medium text-primary-foreground select-none hover:bg-primary/90 disabled:opacity-40"
                            :disabled="idea.trim() === ''"
                            data-test="welcome-end-start"
                        >
                            Start my app
                            <ArrowRight class="size-4" />
                        </button>
                    </form>
                </div>
            </section>
        </main>

        <footer>
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-8 gap-y-4 px-4 pt-6 pb-10 sm:px-10"
            >
                <div class="flex items-center gap-4">
                    <AppLogo />
                    <p class="text-sm text-muted-foreground">
                        Real apps that stay yours.
                    </p>
                </div>
                <nav class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                    <a
                        href="#different"
                        class="text-muted-foreground hover:text-foreground"
                        >What is different</a
                    >
                    <a
                        href="#ideas"
                        class="text-muted-foreground hover:text-foreground"
                        >Ideas</a
                    >
                    <Link
                        v-if="!$page.props.auth.user"
                        :href="login()"
                        class="text-muted-foreground hover:text-foreground"
                        >Log in</Link
                    >
                </nav>
            </div>
        </footer>
    </div>
</template>
