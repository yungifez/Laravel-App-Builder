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

// Under the box, the checks every change passes tick off one by one, so
// the first screen shows the promise as well as saying it.
const heroChecks = [
    'Your app’s own tests',
    'Code fits together',
    'Tidy code',
    'Known security problems flagged',
];
const ticked = ref(still ? heroChecks.length : 0);
let ticking: ReturnType<typeof setInterval> | undefined;

function tickChecks(): void {
    ticking = setInterval(() => {
        ticked.value += 1;

        if (ticked.value >= heroChecks.length) {
            clearInterval(ticking);
        }
    }, 600);
}

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
        tickChecks();
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
    clearInterval(ticking);
    clearInterval(playing);
    seen?.disconnect();
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
                 go. What they type waits for them after signing up. -->
            <section id="start" class="bg-muted/50">
                <div
                    class="mx-auto flex min-h-[calc(100svh-4.5rem)] max-w-3xl flex-col justify-center px-4 py-20 text-center"
                >
                    <p
                        v-reveal
                        class="mx-auto reveal rounded-full border bg-background px-3 py-1 text-sm text-muted-foreground"
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

                    <ul
                        class="mx-auto mt-14 flex flex-wrap justify-center gap-x-5 gap-y-2 text-sm"
                        aria-label="Checked before you keep a change"
                        data-test="welcome-hero-checks"
                    >
                        <li
                            v-for="(check, at) in heroChecks"
                            :key="check"
                            class="flex items-center gap-1.5 transition-colors duration-base"
                            :class="
                                at < ticked
                                    ? 'text-foreground'
                                    : 'text-muted-foreground'
                            "
                        >
                            <Check
                                v-if="at < ticked"
                                class="size-4 text-emerald-600 dark:text-emerald-400"
                            />
                            <LoaderCircle
                                v-else
                                class="size-4 animate-spin motion-reduce:animate-none"
                            />
                            {{ check }}
                        </li>
                    </ul>
                </div>
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
                        <h2
                            class="font-display text-3xl leading-[1.05] tracking-[-0.03em] text-balance sm:text-5xl"
                        >
                            Start from a ready-made idea.
                        </h2>
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
                    <h2
                        v-reveal
                        class="reveal font-display text-3xl leading-[1.05] tracking-[-0.03em] text-balance sm:text-5xl"
                    >
                        Questions people ask.
                    </h2>
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

            <section class="bg-foreground text-background">
                <div
                    v-reveal
                    class="mx-auto flex max-w-6xl reveal flex-wrap items-end justify-between gap-8 px-4 py-20 sm:px-10 sm:py-28"
                >
                    <h2
                        class="font-display text-3xl leading-[1.05] tracking-[-0.03em] sm:text-5xl"
                    >
                        Start with a sentence.
                    </h2>
                    <a
                        href="#start"
                        class="inline-flex min-h-11 press items-center gap-2 rounded-md bg-primary px-6 font-medium text-primary-foreground select-none hover:bg-primary/90"
                        @click.prevent="backToStart"
                    >
                        Start an app
                    </a>
                </div>
            </section>
        </main>

        <footer>
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 pt-3 pb-8"
            >
                <AppLogo />
                <p class="text-sm text-muted-foreground">
                    Real apps that stay yours.
                </p>
            </div>
        </footer>
    </div>
</template>
