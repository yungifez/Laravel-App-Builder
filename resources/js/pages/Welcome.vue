<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
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
    Paintbrush,
    Share2,
    TriangleAlert,
    Sparkles,
    Ticket,
} from '@lucide/vue';
import type { Directive } from 'vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { keepIdea } from '@/lib/startIdea';
import {
    contact,
    developers,
    login,
    pricing,
    privacy,
    register,
    terms,
} from '@/routes';
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
// The server cannot know the visitor's motion setting, so this is read
// once the page is mounted. Hydration keeps the server's classes.
const still = ref(false);

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
const endField = ref<HTMLTextAreaElement | null>(null);

// The empty box writes out one example idea, once, so a visitor sees the
// kind of sentence that works. It never loops: motion that goes on by
// itself is hard to read past. Focus shows the whole example at once.
const example = props.starters[0]?.purpose ?? '';
const typed = ref(example);
let typing: ReturnType<typeof setTimeout> | undefined;

function typeExample(): void {
    let length = 0;

    const step = (): void => {
        length += 1;
        typed.value = example.slice(0, length);

        if (length < example.length) {
            typing = setTimeout(step, 32);
        }
    };

    typed.value = '';
    typing = setTimeout(step, 700);
}

function stopTyping(): void {
    clearTimeout(typing);
    typed.value = example;
}

// Start never does nothing. On an empty box it puts the example in, ready
// to change, and says how to go on; nobody gets an app they did not pick.
const hint = ref(false);

watch(idea, () => (hint.value = false));

// A ready-made idea fills the box; from further down the page it also
// takes the visitor back up to it.
function useStarter(picked: Starter): void {
    idea.value = picked.purpose;
    starter.value = picked;
    backToStart();
}

// The idea waits in this tab; the new-app form on "Your apps" picks it up,
// after signing up if needed.
function start(field: HTMLTextAreaElement | null = ideaField.value): void {
    if (idea.value.trim() === '') {
        stopTyping();
        idea.value = example;
        starter.value = props.starters[0] ?? null;
        field?.focus();
        // Set after the watch on the idea, which clears it.
        setTimeout(() => (hint.value = true));

        return;
    }

    keepIdea(
        idea.value.trim(),
        starter.value?.purpose === idea.value.trim() ? starter.value.key : null,
    );
    router.visit(page.props.auth.user ? index() : register());
}

// The header's button is the same action as the box: an idea already
// typed goes with the visitor, never lost on the way to sign up.
function startTyped(event: MouseEvent): void {
    if (idea.value.trim() !== '') {
        event.preventDefault();
        start();
    }
}

// Enter starts the app, as in other builders; Shift and Enter adds a line.
function startOnEnter(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        start(event.target as HTMLTextAreaElement);
    }
}

// The last tile sends the visitor back up to the box, ready to type.
function backToStart(): void {
    window.scrollTo({ top: 0, behavior: still.value ? 'auto' : 'smooth' });
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
const beat = ref(-1);
const elapsed = ref(0);
const stage = ref<HTMLElement | null>(null);
let beating: ReturnType<typeof setTimeout> | undefined;
let counting: ReturnType<typeof setInterval> | undefined;
let stageSeen: IntersectionObserver | undefined;

function playStage(): void {
    clearTimeout(beating);
    clearInterval(counting);
    clearInterval(counting);

    if (still.value) {
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

// One change, checked two ways. "With checks" is what the platform does
// with every change; "Without checks" is an AI coding agent working on its
// own, which chooses its own checks. The steps play one by one, so the visitor
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
                note: 'We make email fail on purpose while we test. Your app copes.',
                passed: true,
            },
            {
                label: 'A step runs twice by mistake',
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
                label: 'A step runs twice by mistake',
                note: 'Not tried.',
                passed: false,
            },
            { label: 'What else changed', note: 'Not said.', passed: false },
        ],
        verdict: 'The AI says it is done. Three checks were not tried.',
    },
};

const wayOptions = [
    { key: 'here', label: 'With checks' },
    { key: 'alone', label: 'Without checks' },
] as const;

// It rests finished, so a visitor who scrolls past still sees the whole
// run; the switch plays the other way step by step.
const way = ref<'here' | 'alone'>('here');
const shownSteps = ref(99);
let playing: ReturnType<typeof setInterval> | undefined;

function play(): void {
    clearInterval(playing);

    if (still.value) {
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

// Small things the workspace does, each true today.
const features = [
    {
        icon: MousePointerClick,
        title: 'Try it before you keep it',
        text: 'Each change opens in your app first. Keep it, or ask for something else.',
    },
    {
        icon: Paintbrush,
        title: 'Change the look exactly',
        text: 'Click any part of your app and set how it looks. Design edits use no AI.',
    },
    {
        icon: TriangleAlert,
        title: 'See what happens when things fail',
        text: 'Pick what goes wrong and use your app as a visitor would. Nothing is really sent.',
    },
    {
        icon: Share2,
        title: 'Share it with a link',
        text: 'Send a link so others can try your app while you build it.',
    },
];

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

// The app's history: one step per change kept. Undo adds a new step on
// top, as the real undo does, and the first one stays in the history.
type Kept = {
    id: number;
    title: string;
    when: string;
    undone?: boolean;
    undo?: boolean;
};

const history = ref<Kept[]>([
    {
        id: 4,
        title: 'Let customers cancel a booking up to a day before',
        when: 'Today',
    },
    {
        id: 3,
        title: 'Send a reminder the day before a class',
        when: 'Yesterday',
    },
    {
        id: 2,
        title: 'Add a waiting list when a class is full',
        when: '3 days ago',
    },
    { id: 1, title: 'Start Studio Classes', when: 'Last week' },
]);

function undo(kept: Kept): void {
    kept.undone = true;
    history.value.unshift({
        id: history.value.length + 1,
        title: `Undo “${kept.title}”`,
        when: 'Just now',
        undo: true,
    });
}

// What every new app has before the owner asks for anything. Payments
// and email need the owner's own keys, so they are added, not included.
const included = [
    'Accounts and sign-in',
    'A real database you can look into',
    'A link anyone can use to try your app',
];

const questions: { ask: string; answer: string; pricing?: boolean }[] = [
    {
        ask: 'Why do apps from AI builders stay prototypes?',
        answer: 'Because the AI decides when its own work is done. A change can quietly break something that worked, and nothing makes sure it is checked. Here the same tests run on every change, and only they can pass it.',
    },
    {
        ask: 'What does it cost?',
        answer: 'It is free to start, with no card needed. The free plan includes some AI use each month, and bigger plans include more.',
        pricing: true,
    },
    {
        ask: 'Do I need to know how to code?',
        answer: 'No. You say what you want in plain words and see it in your app. Developers can read every change too.',
    },
    {
        ask: 'Who owns the code?',
        answer: 'You do. Each change you keep is saved in your app’s history, and you can download the code any time.',
    },
    {
        ask: 'What happens when a change fails its checks?',
        answer: 'The try is undone, and your app stays as it was. You see what went wrong in plain words. When it is our fault, we say so.',
    },
    {
        ask: 'What if I change my mind?',
        answer: 'Undo any change you kept, on its own, even after others.',
    },
    {
        ask: 'Can my app take payments or send email?',
        answer: 'Yes. Ask for it, and add your Stripe or Resend key when asked. Then it works like any other change.',
    },
];

onMounted(() => {
    still.value = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!still.value) {
        typeExample();
    }

    // The sticky header must never cover what has focus or an anchor.
    document.documentElement.style.scrollPaddingTop = '4.5rem';

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
});

onBeforeUnmount(() => {
    clearTimeout(typing);
    clearTimeout(beating);
    clearInterval(counting);
    clearInterval(playing);
    seen?.disconnect();
    stageSeen?.disconnect();
    document.documentElement.style.scrollPaddingTop = '';
});
</script>

<template>
    <Head title="Build real software without losing control" />

    <!-- Focus rings use the full ring colour here: the app-wide one is too
         faint to see on the blue Start button (WCAG 1.4.11). -->
    <div
        class="min-h-svh overflow-x-clip bg-background text-foreground [&_:is(a,button,summary):focus-visible]:outline-2 [&_:is(a,button,summary):focus-visible]:outline-offset-2 [&_:is(a,button,summary):focus-visible]:outline-ring"
    >
        <header class="sticky top-0 z-30 border-b bg-background">
            <div
                class="mx-auto flex h-14 max-w-7xl items-center justify-between gap-3 px-4 sm:px-8"
            >
                <div class="flex min-w-0 items-center gap-8">
                    <AppLogo />
                    <nav class="hidden items-center gap-6 text-sm md:flex">
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
                            :href="pricing()"
                            class="text-muted-foreground hover:text-foreground"
                            >Pricing</Link
                        >
                    </nav>
                </div>
                <nav class="flex items-center gap-1 text-sm">
                    <Link
                        :href="pricing()"
                        class="inline-flex min-h-11 items-center rounded-md px-2 text-muted-foreground select-none hover:text-foreground md:hidden pointer-fine:min-h-9"
                        >Pricing</Link
                    >
                    <Link
                        v-if="$page.props.auth.user"
                        :href="index()"
                        class="inline-flex min-h-11 press items-center rounded-md bg-primary px-4 font-medium text-primary-foreground select-none hover:bg-primary/90 pointer-fine:min-h-9"
                        data-test="welcome-apps"
                    >
                        Your apps
                    </Link>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="inline-flex min-h-11 items-center rounded-md px-2 text-muted-foreground select-none hover:text-foreground sm:px-3 pointer-fine:min-h-9"
                        >
                            Log in
                        </Link>
                        <Link
                            :href="register()"
                            class="inline-flex min-h-11 press items-center rounded-md bg-primary px-3 font-medium whitespace-nowrap text-primary-foreground select-none hover:bg-primary/90 sm:px-4 pointer-fine:min-h-9"
                            data-test="welcome-start"
                            @click="startTyped"
                        >
                            Start an app
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <main>
            <!-- The first screen is the box: say what the app is for, and
                 go. What they type waits for them after signing up. Then
                 the real workspace makes one change, on a soft panel. -->
            <section id="start" class="mx-auto max-w-7xl px-4 sm:px-8">
                <div
                    class="mx-auto flex max-w-3xl flex-col pt-20 text-center sm:pt-32"
                >
                    <h1
                        class="font-display text-5xl leading-[1.02] font-medium tracking-[-0.04em] text-balance sm:text-7xl"
                    >
                        Build apps that don’t stay prototypes.
                    </h1>
                    <p
                        class="mx-auto mt-6 max-w-2xl text-lg text-balance text-muted-foreground sm:text-xl"
                    >
                        AI builders often break what worked. Here the same tests
                        check every change before you keep&nbsp;it.
                    </p>

                    <form
                        class="mx-auto mt-10 w-full max-w-2xl text-left"
                        data-test="welcome-ask"
                        @submit.prevent="start()"
                    >
                        <div
                            class="rounded-xl border border-input bg-background shadow-lg shadow-black/5 transition-shadow focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/30"
                        >
                            <label for="idea" class="sr-only"
                                >What do you want to make?</label
                            >
                            <textarea
                                id="idea"
                                ref="ideaField"
                                v-model="idea"
                                rows="3"
                                :placeholder="typed"
                                aria-describedby="idea-help"
                                class="block w-full resize-none bg-transparent px-5 pt-4 pb-2 text-base outline-none placeholder:text-muted-foreground sm:text-lg"
                                data-test="welcome-idea"
                                @focus="stopTyping"
                                @keydown="startOnEnter"
                            />
                            <div
                                class="flex items-center justify-between gap-3 pr-3 pb-3 pl-5"
                            >
                                <span
                                    id="idea-help"
                                    class="text-sm text-muted-foreground"
                                    aria-live="polite"
                                    >{{
                                        hint
                                            ? 'Change it, or press Start again.'
                                            : 'Free to start. No card needed.'
                                    }}</span
                                >
                                <button
                                    type="submit"
                                    class="inline-flex min-h-11 shrink-0 press items-center gap-1.5 rounded-md bg-primary px-4 font-medium text-primary-foreground select-none hover:bg-primary/90 pointer-fine:min-h-10"
                                    data-test="welcome-hero-start"
                                >
                                    Start
                                    <ArrowRight class="size-4" />
                                </button>
                            </div>
                        </div>
                    </form>

                    <div
                        class="mt-4 flex flex-wrap items-center justify-center gap-x-1 text-sm"
                        aria-label="Ideas to start from"
                    >
                        <span
                            class="w-full text-muted-foreground sm:mr-1 sm:w-auto"
                            >Try</span
                        >
                        <button
                            v-for="(item, at) in starters"
                            :key="item.key"
                            type="button"
                            :class="[
                                'min-h-11 rounded-md px-2 underline decoration-border underline-offset-4 select-none hover:decoration-foreground pointer-fine:min-h-8',
                                at >= 3 && 'hidden sm:inline-block',
                            ]"
                            @click="useStarter(item)"
                        >
                            {{ item.name }}
                        </button>
                    </div>
                </div>

                <div
                    class="mt-20 rounded-md bg-panel-blue px-3 pt-10 pb-3 sm:mt-24 sm:px-10 sm:pt-16 sm:pb-16"
                >
                    <figure
                        ref="stage"
                        class="mx-auto max-w-6xl"
                        data-test="welcome-stage"
                    >
                        <figcaption class="sr-only">
                            An example in the workspace: one change, from the
                            ask to ready for you to keep.
                        </figcaption>
                        <!-- The workspace, drawn with its own layout and words.
                         It is a picture: nothing in it can be pressed except
                         Play again. -->
                        <div
                            class="overflow-hidden rounded-xl border bg-background text-left shadow-2xl shadow-black/10 select-none"
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
                                        <span class="truncate"
                                            >Studio Classes</span
                                        >
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
                                class="grid h-[26rem] md:h-[34rem] md:grid-cols-[21rem_minmax(0,1fr)]"
                            >
                                <!-- On a phone the app shows, not the chat:
                                     the made thing is the point. -->
                                <div
                                    class="hidden min-h-0 flex-col md:flex md:border-r"
                                >
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
                                                <MessageSquare
                                                    class="size-3.5"
                                                />
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
                                                Let customers cancel a booking
                                                up to a day before.
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
                                                    Adds a way to cancel a
                                                    booking until a day before
                                                    the class, and turns away
                                                    later cancels.
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
                                                            v-if="
                                                                beat >=
                                                                step.from
                                                            "
                                                            class="flex items-center gap-2 pt-1 text-xs font-medium"
                                                        >
                                                            <CircleDot
                                                                class="size-3.5 shrink-0 text-muted-foreground"
                                                            />
                                                            {{ step.label }}
                                                        </li>
                                                        <template
                                                            v-if="at === 2"
                                                        >
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
                                                                >Customers
                                                                cancel up to a
                                                                day before.
                                                                After that, it
                                                                says it is too
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
                                                        2 new tests passed, with
                                                        the app’s other 42.
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
                                    class="flex min-h-0 flex-col bg-muted/30 p-3"
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
                                            <span class="pb-1.5"
                                                >Saved data</span
                                            >
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
                                                    <CalendarDays
                                                        class="size-4"
                                                    />
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
                                                        Classes you have a place
                                                        in.
                                                    </p>
                                                    <ul
                                                        class="mt-4 divide-y border-y"
                                                    >
                                                        <li
                                                            v-for="booking in bookings"
                                                            :key="booking.name"
                                                            class="flex min-h-14 items-center justify-between gap-3 py-2 text-sm"
                                                        >
                                                            <span
                                                                class="min-w-0"
                                                            >
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
                                                                    beat >=
                                                                    ready
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
                </div>
            </section>

            <!-- The claim, then its proof, then what the owner keeps. Each
                 section is one idea: a short two-tone heading, then the
                 thing itself on a soft panel. -->
            <section
                id="different"
                class="mx-auto max-w-7xl px-4 pt-24 sm:px-8 lg:pt-32"
                data-test="welcome-different"
            >
                <h2
                    v-reveal
                    class="max-w-3xl reveal font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-balance sm:text-[2.75rem]"
                >
                    Tests decide if it works, not the&nbsp;AI.
                    <span class="text-muted-foreground"
                        >The AI plans and writes the code.</span
                    >
                </h2>
                <div
                    class="mt-12 flex flex-col items-center rounded-md bg-muted px-3 py-10 sm:px-10 sm:py-16"
                >
                    <div
                        class="mb-8 inline-flex rounded-md border bg-background p-1"
                        role="group"
                        aria-label="Compare one change with and without checks"
                    >
                        <button
                            v-for="option in wayOptions"
                            :key="option.key"
                            type="button"
                            :aria-pressed="way === option.key"
                            :class="[
                                'min-h-11 rounded-sm px-4 text-sm font-medium transition-colors select-none pointer-fine:min-h-9',
                                way === option.key
                                    ? 'bg-foreground text-background'
                                    : 'text-muted-foreground hover:text-foreground',
                            ]"
                            @click="way = option.key"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                    <div
                        v-reveal
                        class="mx-auto w-full max-w-xl reveal delay-100"
                    >
                        <div
                            class="rounded-xl border bg-background p-5 shadow-2xl shadow-black/10 sm:p-7"
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
                                            aria-hidden="true"
                                        />
                                        <Check
                                            v-else-if="step.passed"
                                            class="size-4 text-emerald-600 dark:text-emerald-400"
                                            aria-hidden="true"
                                        />
                                        <Minus
                                            v-else
                                            class="size-4 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <span class="min-w-0">
                                        <span
                                            :class="[
                                                'block font-medium',
                                                !step.passed &&
                                                    'text-muted-foreground line-through decoration-muted-foreground/50',
                                            ]"
                                            >{{ step.label
                                            }}<span class="sr-only">{{
                                                step.passed
                                                    ? ', passed'
                                                    : ', not tried'
                                            }}</span></span
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
                                aria-live="polite"
                            >
                                {{
                                    shownSteps > ways[way].steps.length
                                        ? ways[way].verdict
                                        : ''
                                }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- How a kept change reports itself: each line says how the
                 app knows it, and what nothing checked is named. -->
            <section
                class="mx-auto max-w-7xl px-4 pt-24 sm:px-8 lg:pt-32"
                data-test="welcome-receipt"
            >
                <h2
                    v-reveal
                    class="max-w-3xl reveal font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-balance sm:text-[2.75rem]"
                >
                    Every change shows what was&nbsp;tested.
                    <span class="text-muted-foreground">And what was not.</span>
                </h2>
                <div
                    class="mt-12 rounded-md bg-panel-green px-3 py-10 sm:px-10 sm:py-16"
                >
                    <div
                        v-reveal
                        class="mx-auto w-full max-w-xl reveal delay-100"
                    >
                        <div
                            class="rounded-xl border bg-background p-5 shadow-2xl shadow-black/10 sm:p-7"
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
                                    class="font-medium text-amber-700 dark:text-amber-400"
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
            <section
                class="mx-auto max-w-7xl px-4 pt-24 sm:px-8 lg:pt-32"
                data-test="welcome-yours"
            >
                <h2
                    v-reveal
                    class="max-w-3xl reveal font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-balance sm:text-[2.75rem]"
                >
                    It stays yours.
                    <span class="text-muted-foreground"
                        >Undo any change, or download the code.</span
                    >
                </h2>
                <div
                    class="mt-12 grid items-center gap-10 rounded-md bg-muted px-3 py-10 sm:px-10 sm:py-16 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]"
                >
                    <div class="px-2 sm:px-0">
                        <h3 class="font-medium">
                            In every app from the first version
                        </h3>
                        <ul class="mt-4 space-y-2.5">
                            <li
                                v-for="item in included"
                                :key="item"
                                class="flex gap-2.5"
                            >
                                <Check
                                    class="mt-1 size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                                    aria-hidden="true"
                                />
                                <span class="text-pretty">{{ item }}</span>
                            </li>
                        </ul>
                        <p
                            class="mt-5 text-sm text-pretty text-muted-foreground"
                        >
                            Add when you need them: card payments and sending
                            email.
                        </p>
                    </div>
                    <div
                        v-reveal
                        class="mx-auto w-full max-w-xl reveal overflow-hidden rounded-xl border bg-background shadow-2xl shadow-black/10 delay-100"
                    >
                        <div
                            class="flex items-center justify-between gap-3 border-b px-5 py-3"
                        >
                            <p class="text-sm font-medium">History</p>
                            <span
                                class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-sm text-muted-foreground"
                                aria-hidden="true"
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
                                <span class="min-w-0 flex-1">
                                    <span
                                        :class="[
                                            'block truncate text-sm',
                                            kept.undone &&
                                                'text-muted-foreground line-through decoration-muted-foreground/50',
                                        ]"
                                        >{{ kept.title }}</span
                                    >
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{ kept.when }}</span
                                    >
                                </span>
                                <span
                                    v-if="kept.undone"
                                    class="shrink-0 text-xs text-muted-foreground"
                                    >Undone</span
                                >
                                <button
                                    v-else-if="
                                        !kept.undo && at < history.length - 1
                                    "
                                    type="button"
                                    class="min-h-11 shrink-0 press rounded-md px-2 text-sm text-muted-foreground select-none hover:bg-muted hover:text-foreground pointer-fine:min-h-8"
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

            <!-- What else the owner can do while building, each in a line. -->
            <section
                class="mx-auto max-w-7xl px-4 pt-24 sm:px-8 lg:pt-32"
                data-test="welcome-features"
            >
                <h2
                    v-reveal
                    class="max-w-3xl reveal font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-balance sm:text-[2.75rem]"
                >
                    What you can do while you&nbsp;build.
                    <span class="text-muted-foreground"
                        >None of it needs code.</span
                    >
                </h2>
                <ul
                    v-reveal
                    class="mt-12 grid reveal gap-x-16 gap-y-10 delay-100 md:grid-cols-2"
                >
                    <li
                        v-for="feature in features"
                        :key="feature.title"
                        class="grid max-w-md grid-cols-[1.25rem_minmax(0,1fr)] gap-x-3 border-t pt-5"
                    >
                        <component
                            :is="feature.icon"
                            class="mt-0.5 size-5"
                            aria-hidden="true"
                        />
                        <div>
                            <h3 class="text-base font-medium">
                                {{ feature.title }}
                            </h3>
                            <p class="mt-1.5 text-pretty text-muted-foreground">
                                {{ feature.text }}
                            </p>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Ready-made ideas: the same ones a new app can start from.
                 Picking one fills the box at the top. -->
            <section
                id="ideas"
                class="mx-auto max-w-7xl px-4 pt-24 sm:px-8 lg:pt-32"
            >
                <h2
                    v-reveal
                    class="max-w-3xl reveal font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-balance sm:text-[2.75rem]"
                >
                    Start from a ready-made&nbsp;idea.
                    <span class="text-muted-foreground"
                        >Each comes with a first version to try.</span
                    >
                </h2>
                <ul
                    v-reveal
                    class="mt-12 grid reveal gap-x-8 gap-y-10 delay-100 sm:grid-cols-2 lg:grid-cols-5"
                    data-test="welcome-starters"
                >
                    <li
                        v-for="item in starters"
                        :key="item.key"
                        class="flex flex-col border-t pt-5"
                    >
                        <h3 class="font-medium">{{ item.name }}</h3>
                        <p
                            class="mt-2 flex-1 text-sm text-pretty text-muted-foreground"
                        >
                            {{ item.purpose }}
                        </p>
                        <button
                            type="button"
                            class="mt-4 inline-flex min-h-11 items-center gap-1.5 self-start text-sm font-medium underline decoration-border underline-offset-4 select-none hover:decoration-foreground pointer-fine:min-h-8"
                            @click="useStarter(item)"
                        >
                            Start with this
                            <ArrowRight class="size-3.5" aria-hidden="true" />
                        </button>
                    </li>
                </ul>
                <p
                    v-if="designs.length > 0"
                    class="mt-12 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-muted-foreground"
                >
                    Each starts in one of four looks, and you can change it:
                    <span
                        v-for="design in designs"
                        :key="design.key"
                        class="inline-flex items-center gap-2 text-foreground"
                    >
                        <span
                            aria-hidden="true"
                            class="size-4 rounded-full border"
                            :style="{
                                background: `linear-gradient(135deg, ${design.colors.background} 50%, ${design.colors.primary} 50%)`,
                            }"
                        />
                        {{ design.name }}
                    </span>
                </p>
            </section>

            <!-- Plain answers to what people ask before they start. -->
            <section
                id="questions"
                class="mx-auto grid max-w-7xl gap-10 px-4 pt-24 sm:px-8 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16 lg:pt-32"
            >
                <h2
                    v-reveal
                    class="max-w-3xl reveal font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-balance sm:text-[2.75rem] lg:sticky lg:top-24 lg:self-start"
                >
                    Questions people&nbsp;ask.
                    <span class="text-muted-foreground"
                        >Plain answers before you start.</span
                    >
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
                            class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-4 py-5 font-medium select-none [&::-webkit-details-marker]:hidden"
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
                            <Link
                                v-if="question.pricing"
                                :href="pricing()"
                                class="font-medium text-primary underline-offset-4 hover:underline"
                                >See the plans</Link
                            >
                        </p>
                    </details>
                </div>
            </section>

            <!-- The page ends where it began: the box, at the moment a
                 visitor has decided. It shares the idea with the box at
                 the top. -->
            <section
                class="mx-auto max-w-7xl px-4 pt-24 pb-24 sm:px-8 lg:pt-32 lg:pb-32"
                data-test="welcome-end"
            >
                <h2
                    v-reveal
                    class="mx-auto max-w-3xl reveal text-center font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-balance sm:text-[2.75rem]"
                >
                    Start with a sentence.
                    <span class="text-muted-foreground"
                        >Keep only what passes.</span
                    >
                </h2>
                <form
                    v-reveal
                    class="mx-auto mt-10 max-w-2xl reveal delay-100"
                    @submit.prevent="start(endField)"
                >
                    <div
                        class="rounded-xl border border-input bg-background shadow-lg shadow-black/5 transition-shadow focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/30"
                    >
                        <label for="idea-end" class="sr-only"
                            >What do you want to make?</label
                        >
                        <textarea
                            id="idea-end"
                            ref="endField"
                            v-model="idea"
                            rows="2"
                            :placeholder="example"
                            aria-describedby="idea-end-help"
                            class="block w-full resize-none bg-transparent px-5 pt-4 pb-2 text-base outline-none placeholder:text-muted-foreground sm:text-lg"
                            data-test="welcome-end-idea"
                            @keydown="startOnEnter"
                        />
                        <div
                            class="flex items-center justify-between gap-3 pr-3 pb-3 pl-5"
                        >
                            <span
                                id="idea-end-help"
                                class="text-sm text-muted-foreground"
                                aria-live="polite"
                                >{{
                                    hint
                                        ? 'Change it, or press Start again.'
                                        : 'Free to start. No card needed.'
                                }}</span
                            >
                            <button
                                type="submit"
                                class="inline-flex min-h-11 shrink-0 press items-center gap-1.5 rounded-md bg-primary px-4 font-medium text-primary-foreground select-none hover:bg-primary/90 pointer-fine:min-h-10"
                                data-test="welcome-end-start"
                            >
                                Start
                                <ArrowRight class="size-4" aria-hidden="true" />
                            </button>
                        </div>
                    </div>
                </form>
            </section>
        </main>

        <footer class="border-t">
            <div
                class="mx-auto grid max-w-7xl gap-10 px-4 py-14 text-sm sm:grid-cols-2 sm:px-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]"
            >
                <div>
                    <div class="flex items-center gap-2"><AppLogo /></div>
                    <p class="mt-3 text-muted-foreground">
                        Real apps that stay yours.
                    </p>
                </div>
                <nav aria-label="Product">
                    <p class="font-medium">Product</p>
                    <ul class="mt-3 space-y-2 text-muted-foreground">
                        <li>
                            <a href="#different" class="hover:text-foreground"
                                >What is different</a
                            >
                        </li>
                        <li>
                            <a href="#ideas" class="hover:text-foreground"
                                >Ideas</a
                            >
                        </li>
                        <li>
                            <a href="#questions" class="hover:text-foreground"
                                >Questions</a
                            >
                        </li>
                        <li>
                            <Link
                                :href="pricing()"
                                class="hover:text-foreground"
                                >Pricing</Link
                            >
                        </li>
                    </ul>
                </nav>
                <nav aria-label="Company">
                    <p class="font-medium">Company</p>
                    <ul class="mt-3 space-y-2 text-muted-foreground">
                        <li>
                            <Link
                                :href="developers()"
                                class="hover:text-foreground"
                                >For developers</Link
                            >
                        </li>
                        <li>
                            <Link
                                :href="contact()"
                                class="hover:text-foreground"
                                >Contact</Link
                            >
                        </li>
                        <li>
                            <Link :href="terms()" class="hover:text-foreground"
                                >Terms</Link
                            >
                        </li>
                        <li>
                            <Link
                                :href="privacy()"
                                class="hover:text-foreground"
                                >Privacy</Link
                            >
                        </li>
                    </ul>
                </nav>
                <nav aria-label="Account">
                    <p class="font-medium">Account</p>
                    <ul class="mt-3 space-y-2 text-muted-foreground">
                        <li v-if="$page.props.auth.user">
                            <Link :href="index()" class="hover:text-foreground"
                                >Your apps</Link
                            >
                        </li>
                        <template v-else>
                            <li>
                                <Link
                                    :href="register()"
                                    class="hover:text-foreground"
                                    >Start an app</Link
                                >
                            </li>
                            <li>
                                <Link
                                    :href="login()"
                                    class="hover:text-foreground"
                                    >Log in</Link
                                >
                            </li>
                        </template>
                    </ul>
                </nav>
            </div>
        </footer>
    </div>
</template>
