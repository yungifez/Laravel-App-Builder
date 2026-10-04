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
    Share2,
    TriangleAlert,
    Sparkles,
    Ticket,
    SkipForward,
} from '@lucide/vue';
import type { Directive } from 'vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import PublicFooter from '@/components/PublicFooter.vue';
import ReadyMadeMenu from '@/components/ReadyMadeMenu.vue';
import { faults } from '@/lib/appFaults';
import { keepIdea } from '@/lib/startIdea';
import { home, login, pricing, register } from '@/routes';
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

// The ready-made app on show in its section; the first one until the
// visitor picks another.
const shownStarterKey = ref(props.starters[0]?.key ?? '');
const shownStarter = computed(
    () =>
        props.starters.find((item) => item.key === shownStarterKey.value) ??
        null,
);

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

const playingStage = computed(() => beat.value >= 0 && beat.value < ready);

function endStage(): void {
    clearTimeout(beating);
    clearInterval(counting);
    beat.value = ready;
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
                note: '44 tests, the same ones every time. The AI cannot change how they run.',
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
                label: 'A stranger opens the staff page',
                note: 'Someone who is not signed in is turned away.',
                passed: true,
            },
            {
                label: 'Other dates',
                note: 'The change uses dates, so the tests run again on a leap day and at New Year.',
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
            {
                label: 'A stranger opens the staff page',
                note: 'Not tried.',
                passed: false,
            },
            { label: 'Other dates', note: 'Not tried.', passed: false },
            { label: 'What else changed', note: 'Not said.', passed: false },
        ],
        verdict: 'The AI says it is done. Five checks were not tried.',
    },
};

const wayOptions = [
    { key: 'here', label: 'With checks' },
    { key: 'alone', label: 'Without checks' },
] as const;

// It plays once it scrolls into view: both ways run side by side, a row
// at a time, so the visitor watches one get checked while the other is
// only said to work. It rests finished for the server and for anyone who
// asks for less motion; the switch and "Play again" run it again.
const way = ref<'here' | 'alone'>('here');
const shownSteps = ref(99);
let playing: ReturnType<typeof setInterval> | undefined;
let hinting: ReturnType<typeof setTimeout> | undefined;

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

// On wide screens both ways show at once, resting finished.
const wayKeys = wayOptions.map((option) => option.key);
const wayLabel = (key: 'here' | 'alone'): string =>
    wayOptions.find((option) => option.key === key)?.label ?? '';
const shown = (_key: 'here' | 'alone'): number => shownSteps.value;
const demoDone = computed(
    () => shownSteps.value > ways[way.value].steps.length,
);
const checksPanel = ref<HTMLElement | null>(null);

// The first few things the workspace lets an owner pretend are down,
// in its own words, so the page names only what really exists. Each one
// shows what a visitor of an app that copes would see after cancelling.
const whatIfs = faults.slice(0, 4);
const whatIf = ref<string>('none');
const whatIfTouched = ref(false);
const whatIfPanel = ref<HTMLElement | null>(null);

const whatIfViews: Record<string, { says: string; happened: string }> = {
    none: {
        says: 'Booking cancelled. We emailed you to confirm.',
        happened: 'Saved the cancel · Sent an email',
    },
    mail: {
        says: 'Booking cancelled. Your email follows as soon as we can send it.',
        happened: 'Saved the cancel · Email kept to send again',
    },
    http: {
        says: 'Booking cancelled. Your calendar updates once it answers.',
        happened: 'Saved the cancel · Calendar asked again later',
    },
    file: {
        says: 'Booking cancelled. We could not keep a copy of your receipt.',
        happened: 'Saved the cancel · Receipt not stored',
    },
};

function pickWhatIf(key: string): void {
    whatIfTouched.value = true;
    whatIf.value = key;
}

// The designer, as the workspace has it, in its own words: pick a look,
// or click a part of the app and change it. The real one saves each edit
// as code, with no AI involved, so the demo claims no more than that.
type DesignPart = { kind: 'heading' } | { kind: 'cancel'; row: number };
type CancelLook = { words?: string; colour?: string; corners?: string };

const designRows = [
    { name: 'Pilates', when: 'Thursday, 18:30' },
    { name: 'Morning yoga', when: 'Saturday, 09:00' },
    { name: 'Evening spin', when: 'Monday, 19:00' },
];
const lookKey = ref(props.designs[0]?.key ?? '');
const look = computed(
    () =>
        props.designs.find((design) => design.key === lookKey.value) ??
        props.designs[0],
);
const designPart = ref<DesignPart | null>(null);
const onlyThisOne = ref(false);
const headingLook = ref<{ words?: string; colour?: string }>({});
const cancelLooks = ref<CancelLook[]>(designRows.map(() => ({})));
const designEdits = ref(0);
const designKept = ref(false);
const designTouched = ref(false);
const designPanel = ref<HTMLElement | null>(null);
let designHinting: ReturnType<typeof setTimeout> | undefined;

const colourChoices = [
    { key: 'background', label: 'Plain' },
    { key: 'primary', label: 'Main colour' },
    { key: 'foreground', label: 'Text colour' },
    { key: 'accent', label: 'Highlight' },
] as const;
const cornerChoices = [
    { key: '0', label: 'Square' },
    { key: 'look', label: 'Rounded' },
    { key: '9999px', label: 'Pill' },
];

// A part starts plain, with the look's own corners, and the panel shows
// those as chosen until the owner picks something else.
const chosenColour = computed(() => pickedLook.value.colour ?? 'background');
const chosenCorners = computed(() => pickedLook.value.corners ?? 'look');

function cancelStyle(row: number): Record<string, string | undefined> {
    const { colour, corners } = cancelLooks.value[row];
    const filled = colour !== undefined && colour !== 'background';

    return {
        borderRadius:
            corners === undefined || corners === 'look'
                ? look.value?.radius
                : corners,
        borderColor: look.value?.colors.border,
        background: filled ? lookColour(colour) : undefined,
        color: !filled
            ? undefined
            : colour === 'accent'
              ? look.value?.colors.foreground
              : look.value?.colors.background,
    };
}

function lookColour(key: string | undefined): string | undefined {
    return key === undefined
        ? undefined
        : look.value?.colors[key as keyof DesignOption['colors']];
}

function pickLook(key: string): void {
    designTouched.value = true;
    lookKey.value = key;
}

function pickPart(part: DesignPart): void {
    designTouched.value = true;
    designPart.value = part;
}

function isPicked(part: DesignPart): boolean {
    const now = designPart.value;

    return (
        now !== null &&
        now.kind === part.kind &&
        (now.kind === 'heading' ||
            (part.kind === 'cancel' && now.row === part.row))
    );
}

// The other Cancel buttons are drawn dashed: an edit to one changes them
// too, unless the owner chose only this one.
function isLikeIt(row: number): boolean {
    const now = designPart.value;

    return now?.kind === 'cancel' && now.row !== row && !onlyThisOne.value;
}

const pickedLook = computed<CancelLook>(() => {
    const now = designPart.value;

    if (now === null) {
        return {};
    }

    return now.kind === 'heading'
        ? headingLook.value
        : cancelLooks.value[now.row];
});

function setLook(edit: CancelLook): void {
    const now = designPart.value;

    if (now === null) {
        return;
    }

    if (now.kind === 'heading') {
        headingLook.value = { ...headingLook.value, ...edit };
    } else {
        cancelLooks.value = cancelLooks.value.map((current, row) =>
            row === now.row || !onlyThisOne.value
                ? { ...current, ...edit }
                : current,
        );
    }

    designTouched.value = true;
    designKept.value = false;
    designEdits.value++;
}

function undoDesign(): void {
    headingLook.value = {};
    cancelLooks.value = designRows.map(() => ({}));
    designEdits.value = 0;
}

function keepDesign(): void {
    designEdits.value = 0;
    designKept.value = true;
}

// A kept change reports how it knows each thing, in the words the change
// page uses. What nothing checked is named, never passed off as done.
type Mark = 'tested' | 'untouched' | 'unchecked';

const marks: { key: Mark; label: string; means: string }[] = [
    {
        key: 'tested',
        label: 'checked by a test',
        means: 'A test shows it works, and the test stays with your app.',
    },
    {
        key: 'untouched',
        label: 'not touched by this change',
        means: 'This change did not alter the code for it.',
    },
    {
        key: 'unchecked',
        label: 'not checked yet',
        means: 'No test covered it, so try it yourself before you keep the change.',
    },
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
            { text: 'Staff see the freed place', mark: 'unchecked' },
        ],
    },
];

// The receipt fills in line by line once it is seen; the verdict comes
// last, after the line nothing checked.
const receiptTotal = receipt.reduce(
    (sum, group) => sum + group.lines.length,
    0,
);
const receiptAt = (group: number, line: number): number =>
    receipt
        .slice(0, group)
        .reduce((sum, earlier) => sum + earlier.lines.length, 0) + line;
const receiptShown = ref(99);
const receiptCard = ref<HTMLElement | null>(null);
let filling: ReturnType<typeof setInterval> | undefined;

function fillReceipt(): void {
    clearInterval(filling);
    receiptShown.value = 0;
    filling = setInterval(() => {
        receiptShown.value += 1;

        if (receiptShown.value > receiptTotal) {
            clearInterval(filling);
        }
    }, 420);
}

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

const questions: { ask: string; answer: string; pricing?: boolean }[] = [
    {
        ask: 'Why do apps from AI builders stay prototypes?',
        answer: 'Because the AI decides when its own work is done. A change can quietly break something that worked, and nothing makes sure it is checked. Here it is, before you keep it.',
    },
    {
        ask: 'What does it cost?',
        answer: 'It is free to start, with no card needed. The free plan includes some AI use each month, and bigger plans include more.',
        pricing: true,
    },
    {
        ask: 'What does “not checked yet” mean?',
        answer: 'No test covered that part of the change, so we name it instead of calling it done. Try that part yourself before you keep the change.',
    },
    {
        ask: 'Why do you make email fail on purpose?',
        answer: 'Real apps meet failures, like email being down or a step running twice. While we test, we cause them on purpose, so you see how your app copes before your visitors do.',
    },
    {
        ask: 'Can I change how it looks without the AI?',
        answer: 'Yes. Click any part of your app and set how it looks. Design edits use no AI.',
    },
    {
        ask: 'Who owns the code?',
        answer: 'You do. Each change you keep is saved in your app’s history, and you can download the code any time.',
    },
    {
        ask: 'What happens when a change fails its checks?',
        answer: 'Nothing is kept, and your app stays as it was. You see what went wrong in plain words. When it is our fault, we say so.',
    },
    {
        ask: 'Can my app take payments or send email?',
        answer: 'Yes. Ask for it, and paste in the key your payment or email service gives you. Then it works like any other change.',
    },
];

onMounted(() => {
    still.value = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!still.value) {
        typeExample();
    }

    // The sticky header must never cover what has focus or an anchor.
    document.documentElement.style.scrollPaddingTop = '4.5rem';

    // Without motion there is no hint to watch, so a part starts picked.
    if (still.value) {
        designPart.value = { kind: 'cancel', row: 1 };
    }

    // The demos below wait for the visitor, so they play in view.
    if (!still.value) {
        if (checksPanel.value !== null) {
            shownSteps.value = -1;
            whenSeen(checksPanel.value, play);
        }

        if (receiptCard.value !== null) {
            receiptShown.value = -1;
            whenSeen(receiptCard.value, fillReceipt);
        }

        // The designer opens on a Cancel button, as if clicked, so the
        // visitor sees that parts of the app can be picked.
        if (designPanel.value !== null) {
            whenSeen(designPanel.value, () => {
                designHinting = setTimeout(() => {
                    if (!designTouched.value) {
                        designPart.value = { kind: 'cancel', row: 1 };
                    }
                }, 1200);
            });
        }

        // One hint that the list can be pressed, unless they got there first.
        if (whatIfPanel.value !== null) {
            whenSeen(whatIfPanel.value, () => {
                hinting = setTimeout(() => {
                    if (!whatIfTouched.value) {
                        whatIf.value = 'mail';
                    }
                }, 1400);
            });
        }
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
});

onBeforeUnmount(() => {
    clearTimeout(typing);
    clearTimeout(beating);
    clearInterval(counting);
    clearInterval(playing);
    clearInterval(filling);
    clearTimeout(hinting);
    clearTimeout(designHinting);
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
                    <Link :href="home()" class="flex items-center gap-2">
                        <AppLogo />
                    </Link>
                    <nav class="hidden items-center gap-6 text-sm md:flex">
                        <ReadyMadeMenu :starters="starters" />
                        <Link
                            :href="pricing()"
                            class="text-muted-foreground transition-colors hover:text-foreground"
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
                        Here the AI never decides it’s done. Checks do, and they
                        tell you what they did not&nbsp;cover.
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
                                    class="group inline-flex min-h-11 shrink-0 press items-center gap-1.5 rounded-md bg-primary px-4 font-medium text-primary-foreground select-none hover:bg-primary/90 pointer-fine:min-h-10"
                                    data-test="welcome-hero-start"
                                >
                                    Start
                                    <ArrowRight
                                        class="size-4 transition-transform duration-base group-hover:translate-x-0.5"
                                    />
                                </button>
                            </div>
                        </div>
                    </form>
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
                            class="overflow-hidden rounded-xl border bg-background text-left shadow-2xl shadow-black/10 select-none dark:border-input"
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
                                <!-- On a phone the chat shows, not the app:
                                     its reply says what the tests proved. -->
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
                                                        class="flex gap-2 font-medium"
                                                    >
                                                        <ShieldCheck
                                                            class="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
                                                        />
                                                        2 new tests that fail
                                                        without this change and
                                                        pass with it. The other
                                                        42 still pass.
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
                                            class="grid size-10 shrink-0 press place-items-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                                            :aria-label="
                                                playingStage
                                                    ? 'Skip to the end of the example'
                                                    : 'Play the example again'
                                            "
                                            :title="
                                                playingStage
                                                    ? 'Skip to the end'
                                                    : 'Play again'
                                            "
                                            @click="
                                                playingStage
                                                    ? endStage()
                                                    : playStage()
                                            "
                                        >
                                            <SkipForward
                                                v-if="playingStage"
                                                class="size-4"
                                            />
                                            <RotateCcw v-else class="size-4" />
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
                                                class="mt-5 flex items-center gap-2 rounded-md px-2 py-1.5"
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
                    class="max-w-5xl reveal font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-pretty sm:text-[2.75rem]"
                >
                    Checks decide if it works, not the&nbsp;AI.
                    <span class="text-muted-foreground"
                        >So a change can’t quietly break what worked.</span
                    >
                </h2>
                <div
                    ref="checksPanel"
                    class="mt-12 flex flex-col items-center rounded-md bg-muted px-3 py-10 sm:px-10 sm:py-16"
                >
                    <div
                        class="mb-8 inline-flex rounded-md border bg-background p-1 lg:hidden"
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
                        class="mx-auto grid w-full max-w-xl reveal gap-8 delay-100 lg:max-w-5xl lg:grid-cols-2"
                    >
                        <div
                            v-for="key in wayKeys"
                            :key="key"
                            :class="[
                                key === way ? 'flex' : 'hidden lg:flex',
                                'flex-col rounded-xl border bg-background p-5 shadow-2xl shadow-black/10 sm:p-7 dark:border-input',
                            ]"
                        >
                            <p class="mb-5 hidden font-medium lg:block">
                                {{ wayLabel(key) }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                You asked
                            </p>
                            <p class="mt-1 font-medium text-pretty">
                                Let customers cancel a booking up to a day
                                before.
                            </p>
                            <ol class="mt-6 divide-y border-y">
                                <li
                                    v-for="(step, at) in ways[key].steps"
                                    :key="`${key}-${step.label}`"
                                    :class="[
                                        'flex gap-3 py-3.5 transition-[opacity,translate] duration-panel',
                                        at < shown(key)
                                            ? 'opacity-100'
                                            : at === shown(key)
                                              ? 'opacity-60'
                                              : 'translate-y-1 opacity-0',
                                    ]"
                                >
                                    <span
                                        class="mt-0.5 flex size-5 shrink-0 items-center justify-center"
                                    >
                                        <LoaderCircle
                                            v-if="at === shown(key)"
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
                                                    'text-muted-foreground',
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
                                    'mt-auto flex min-h-6 items-center gap-2 pt-5 text-sm font-medium transition-opacity duration-panel',
                                    shown(key) > ways[key].steps.length
                                        ? 'opacity-100'
                                        : 'opacity-0',
                                    key === 'here'
                                        ? 'text-emerald-700 dark:text-emerald-400'
                                        : 'text-muted-foreground',
                                ]"
                                aria-live="polite"
                            >
                                {{
                                    shown(key) > ways[key].steps.length
                                        ? ways[key].verdict
                                        : ''
                                }}
                            </p>
                        </div>
                    </div>
                    <button
                        v-if="!still"
                        type="button"
                        :class="[
                            'mt-8 inline-flex min-h-11 press items-center gap-2 rounded-md px-3 text-sm font-medium text-muted-foreground select-none hover:bg-background hover:text-foreground pointer-fine:min-h-9',
                            demoDone
                                ? 'opacity-100'
                                : 'pointer-events-none opacity-0',
                        ]"
                        :tabindex="demoDone ? 0 : -1"
                        @click="play"
                    >
                        <RotateCcw class="size-4" aria-hidden="true" />
                        Play again
                    </button>
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
                    class="max-w-5xl reveal font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-pretty sm:text-[2.75rem]"
                >
                    Every change shows what was&nbsp;tested.
                    <span class="text-muted-foreground">And what was not.</span>
                </h2>
                <div
                    class="mt-12 grid items-start gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]"
                >
                    <!-- What each mark means, as the change page uses them. -->
                    <dl class="grid max-w-md gap-5 lg:pt-8">
                        <div
                            v-for="mark in marks"
                            :key="mark.key"
                            class="grid grid-cols-[1.25rem_minmax(0,1fr)] gap-x-3"
                        >
                            <dt class="contents">
                                <Check
                                    v-if="mark.key === 'tested'"
                                    class="mt-1 size-4 text-emerald-600 dark:text-emerald-400"
                                    aria-hidden="true"
                                />
                                <Minus
                                    v-else-if="mark.key === 'untouched'"
                                    class="mt-1 size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <CircleDashed
                                    v-else
                                    class="mt-1 size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span
                                    class="font-medium first-letter:uppercase"
                                    >{{ mark.label }}</span
                                >
                            </dt>
                            <dd
                                class="col-start-2 mt-1 text-pretty text-muted-foreground"
                            >
                                {{ mark.means }}
                            </dd>
                        </div>
                    </dl>
                    <div
                        v-reveal
                        class="w-full max-w-xl reveal delay-100 lg:justify-self-end"
                    >
                        <div
                            ref="receiptCard"
                            class="rounded-xl border bg-background p-5 shadow-2xl shadow-black/10 sm:p-7 dark:border-input"
                        >
                            <p class="text-sm text-muted-foreground">
                                You kept
                            </p>
                            <p class="mt-1 font-medium text-pretty">
                                Let customers cancel a booking up to a day
                                before.
                            </p>
                            <p
                                :class="[
                                    'mt-5 flex flex-wrap items-baseline gap-x-2 border-t pt-4 text-sm transition-opacity duration-panel',
                                    receiptShown > receiptTotal
                                        ? 'opacity-100'
                                        : 'opacity-0',
                                ]"
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
                                v-for="(group, g) in receipt"
                                :key="group.title"
                                class="mt-6"
                            >
                                <h3 class="text-sm font-medium">
                                    {{ group.title }}
                                </h3>
                                <ul class="mt-2 divide-y border-y">
                                    <li
                                        v-for="(line, l) in group.lines"
                                        :key="line.text"
                                        :class="[
                                            'grid grid-cols-[1.25rem_minmax(0,1fr)] gap-x-2 py-2.5 transition-[opacity,translate] duration-panel',
                                            receiptAt(g, l) < receiptShown
                                                ? 'opacity-100'
                                                : 'translate-y-1 opacity-0',
                                        ]"
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
                    class="max-w-5xl reveal font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-pretty sm:text-[2.75rem]"
                >
                    Undo one change.
                    <span class="text-muted-foreground"
                        >The rest&nbsp;stays.</span
                    >
                </h2>
                <div
                    class="mt-12 grid items-center gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]"
                >
                    <div>
                        <p class="max-w-md text-lg text-pretty">
                            Each change you keep is saved on its own. Undo
                            yesterday’s reminder, and today’s change stays.
                        </p>
                        <p
                            class="mt-4 max-w-md text-pretty text-muted-foreground"
                        >
                            You can download the code any time.
                        </p>
                    </div>
                    <div
                        v-reveal
                        class="mx-auto w-full max-w-xl reveal overflow-hidden rounded-xl border bg-background shadow-2xl shadow-black/10 delay-100 dark:border-input"
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

            <!-- What-if, as the workspace has it: pick what goes down, and a
                 small copy of the app shows what a visitor sees. -->
            <section
                class="mx-auto max-w-7xl px-4 pt-24 sm:px-8 lg:pt-32"
                data-test="welcome-features"
            >
                <h2
                    v-reveal
                    class="max-w-5xl reveal font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-pretty sm:text-[2.75rem]"
                >
                    See what your app does when things go&nbsp;wrong.
                    <span class="text-muted-foreground"
                        >Before your visitors&nbsp;do.</span
                    >
                </h2>
                <div
                    ref="whatIfPanel"
                    class="mt-12 grid items-center gap-10 rounded-md bg-muted px-3 py-10 sm:px-10 sm:py-16 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]"
                    data-test="welcome-what-if"
                >
                    <div class="px-2 sm:px-0">
                        <p class="max-w-md text-lg text-pretty">
                            Pick what goes wrong, then use your app as a visitor
                            would. Nothing is really sent.
                        </p>
                        <div
                            class="mt-6 grid gap-1"
                            role="group"
                            aria-label="What goes wrong"
                        >
                            <button
                                v-for="fault in whatIfs"
                                :key="fault.key"
                                type="button"
                                :aria-pressed="whatIf === fault.key"
                                :class="[
                                    'flex min-h-11 press items-center gap-3 rounded-md border px-3 text-left text-sm select-none',
                                    whatIf === fault.key
                                        ? 'border-border bg-background font-medium shadow-xs'
                                        : 'border-transparent text-muted-foreground hover:bg-background/60 hover:text-foreground',
                                ]"
                                @click="pickWhatIf(fault.key)"
                            >
                                <span
                                    :class="[
                                        'size-2 shrink-0 rounded-full transition-colors duration-base',
                                        whatIf === fault.key
                                            ? fault.key === 'none'
                                                ? 'bg-emerald-600 dark:bg-emerald-400'
                                                : 'bg-amber-600 dark:bg-amber-400'
                                            : 'bg-muted-foreground/30',
                                    ]"
                                    aria-hidden="true"
                                />
                                {{ fault.label }}
                            </button>
                        </div>
                    </div>

                    <div
                        v-reveal
                        class="mx-auto w-full max-w-md reveal delay-100"
                    >
                        <div
                            class="overflow-hidden rounded-xl border bg-background shadow-2xl shadow-black/10 dark:border-input"
                            aria-live="polite"
                        >
                            <div
                                class="flex items-center gap-2 border-b px-4 py-3 text-sm font-semibold"
                            >
                                <span
                                    class="grid size-6 place-items-center rounded-md bg-foreground text-background"
                                    aria-hidden="true"
                                >
                                    <CalendarDays class="size-3.5" />
                                </span>
                                Studio Classes
                            </div>
                            <div class="space-y-4 p-4">
                                <p
                                    :key="whatIf"
                                    :class="[
                                        'flex gap-2.5 rounded-md border px-3 py-2.5 text-sm motion-safe:animate-[rise-in_300ms_var(--ease-settle)_both]',
                                        whatIf === 'none'
                                            ? 'border-emerald-600/25 bg-emerald-600/5'
                                            : 'border-amber-600/30 bg-amber-600/5',
                                    ]"
                                >
                                    <Check
                                        v-if="whatIf === 'none'"
                                        class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                                        aria-hidden="true"
                                    />
                                    <TriangleAlert
                                        v-else
                                        class="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-400"
                                        aria-hidden="true"
                                    />
                                    {{ whatIfViews[whatIf].says }}
                                </p>
                                <div class="divide-y border-y text-sm">
                                    <div
                                        class="flex items-center justify-between py-3"
                                    >
                                        <span>
                                            <span class="block font-medium"
                                                >Pilates</span
                                            >
                                            <span class="text-muted-foreground"
                                                >Thursday, 18:30</span
                                            >
                                        </span>
                                        <span class="text-muted-foreground"
                                            >Cancelled</span
                                        >
                                    </div>
                                    <div
                                        class="flex items-center justify-between py-3"
                                    >
                                        <span>
                                            <span class="block font-medium"
                                                >Morning yoga</span
                                            >
                                            <span class="text-muted-foreground"
                                                >Saturday, 09:00</span
                                            >
                                        </span>
                                        <span
                                            class="rounded-md border px-2.5 py-1 font-medium"
                                            aria-hidden="true"
                                            >Cancel</span
                                        >
                                    </div>
                                </div>
                            </div>
                            <p
                                :key="`happened-${whatIf}`"
                                class="border-t bg-muted/40 px-4 py-2.5 text-xs text-muted-foreground motion-safe:animate-[rise-in_300ms_var(--ease-settle)_80ms_both]"
                            >
                                What happened:
                                {{ whatIfViews[whatIf].happened }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- The designer: pick a look, or click a part of the app and
                 change it, with the workspace panel's own words. -->
            <section
                class="mx-auto max-w-7xl px-4 pt-24 sm:px-8 lg:pt-32"
                data-test="welcome-designer"
            >
                <h2
                    v-reveal
                    class="max-w-5xl reveal font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-pretty sm:text-[2.75rem]"
                >
                    Click a part of your app and change&nbsp;it.
                    <span class="text-muted-foreground"
                        >You don’t have to ask the&nbsp;AI.</span
                    >
                </h2>
                <div
                    ref="designPanel"
                    class="mt-12 grid items-start gap-8 rounded-md bg-panel-blue px-3 py-10 sm:px-10 sm:py-16 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]"
                >
                    <div class="min-w-0">
                        <div
                            v-if="designs.length > 0"
                            class="grid grid-cols-2 gap-1 sm:flex sm:items-center"
                            role="group"
                            aria-label="Look"
                        >
                            <span
                                class="col-span-2 text-sm text-muted-foreground sm:mr-2"
                                >Look</span
                            >
                            <button
                                v-for="design in designs"
                                :key="design.key"
                                type="button"
                                :aria-pressed="lookKey === design.key"
                                :class="[
                                    'inline-flex min-h-11 press items-center gap-2 rounded-md border px-3 text-sm select-none pointer-fine:min-h-9',
                                    lookKey === design.key
                                        ? 'border-border bg-background font-medium shadow-xs'
                                        : 'border-transparent text-muted-foreground hover:bg-background/60 hover:text-foreground',
                                ]"
                                @click="pickLook(design.key)"
                            >
                                <span
                                    aria-hidden="true"
                                    class="size-3.5 rounded-full border"
                                    :style="{
                                        background: design.colors.primary,
                                    }"
                                />
                                {{ design.name }}
                            </button>
                        </div>

                        <!-- The app in the chosen look. Each part is a real
                             button, so a keyboard can pick it too. -->
                        <div
                            class="mt-4 overflow-hidden border shadow-2xl shadow-black/10 transition-[background-color,color,border-radius] duration-base motion-reduce:transition-none"
                            :style="{
                                background: look?.colors.background,
                                color: look?.colors.foreground,
                                borderColor: look?.colors.border,
                                borderRadius: look?.radius,
                            }"
                            aria-label="Studio Classes, the app you are changing"
                            role="group"
                        >
                            <div
                                class="flex items-center gap-2 border-b px-4 py-3 text-sm font-semibold"
                                :style="{ borderColor: look?.colors.border }"
                            >
                                <span
                                    class="grid size-6 place-items-center rounded-md transition-colors duration-base"
                                    :style="{
                                        background: look?.colors.primary,
                                        color: look?.colors[
                                            'primary-foreground'
                                        ],
                                    }"
                                    aria-hidden="true"
                                >
                                    <CalendarDays class="size-3.5" />
                                </span>
                                Studio Classes
                            </div>
                            <div class="p-4 sm:p-5">
                                <button
                                    type="button"
                                    :aria-pressed="
                                        isPicked({ kind: 'heading' })
                                    "
                                    :class="[
                                        'block rounded-sm text-left text-lg font-semibold outline-offset-4 transition-[color,outline-color] duration-quick',
                                        isPicked({ kind: 'heading' })
                                            ? 'outline-2 outline-[#7c3aed]'
                                            : 'pointer-fine:hover:outline-1 pointer-fine:hover:outline-[#a78bfa] pointer-fine:hover:outline-dashed',
                                    ]"
                                    :style="{
                                        color:
                                            headingLook.colour === 'background'
                                                ? undefined
                                                : lookColour(
                                                      headingLook.colour,
                                                  ),
                                    }"
                                    @click="pickPart({ kind: 'heading' })"
                                >
                                    {{ headingLook.words || 'Your bookings' }}
                                </button>
                                <div
                                    class="mt-3 divide-y border-y text-sm"
                                    :style="{
                                        borderColor: look?.colors.border,
                                    }"
                                >
                                    <div
                                        v-for="(row, index) in designRows"
                                        :key="row.name"
                                        class="flex items-center justify-between gap-3 py-3"
                                        :style="{
                                            borderColor: look?.colors.border,
                                        }"
                                    >
                                        <span class="min-w-0">
                                            <span class="block font-medium">{{
                                                row.name
                                            }}</span>
                                            <span
                                                :style="{
                                                    color: look?.colors[
                                                        'muted-foreground'
                                                    ],
                                                }"
                                                >{{ row.when }}</span
                                            >
                                        </span>
                                        <button
                                            type="button"
                                            :aria-pressed="
                                                isPicked({
                                                    kind: 'cancel',
                                                    row: index,
                                                })
                                            "
                                            :aria-label="`Cancel button for ${row.name}`"
                                            :data-test="`designer-cancel-${index}`"
                                            :class="[
                                                'min-h-9 shrink-0 border px-3 font-medium outline-offset-3 transition-[background-color,color,border-radius,outline-color] duration-base motion-reduce:transition-none',
                                                isPicked({
                                                    kind: 'cancel',
                                                    row: index,
                                                })
                                                    ? 'outline-2 outline-[#7c3aed]'
                                                    : isLikeIt(index)
                                                      ? 'outline-1 outline-[#a78bfa] outline-dashed'
                                                      : 'pointer-fine:hover:outline-1 pointer-fine:hover:outline-[#a78bfa] pointer-fine:hover:outline-dashed',
                                            ]"
                                            :style="cancelStyle(index)"
                                            @click="
                                                pickPart({
                                                    kind: 'cancel',
                                                    row: index,
                                                })
                                            "
                                        >
                                            {{
                                                cancelLooks[index].words ||
                                                'Cancel'
                                            }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- The panel beside it, as the workspace shows it. -->
                    <div
                        class="flex min-h-[22rem] flex-col rounded-xl border bg-background text-sm shadow-xl shadow-black/5 dark:border-input"
                    >
                        <div
                            v-if="designPart === null"
                            class="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center text-muted-foreground"
                        >
                            <MousePointerClick
                                class="size-5"
                                aria-hidden="true"
                            />
                            Click any part of your app
                        </div>
                        <div
                            v-else
                            :key="
                                designPart.kind === 'heading'
                                    ? 'heading'
                                    : 'cancel'
                            "
                            class="flex-1 space-y-5 p-5 motion-safe:animate-[rise-in_250ms_var(--ease-settle)_both]"
                        >
                            <div>
                                <p class="font-medium">
                                    {{
                                        designPart.kind === 'heading'
                                            ? 'Heading'
                                            : 'Cancel button'
                                    }}
                                </p>
                                <p
                                    v-if="designPart.kind === 'cancel'"
                                    class="mt-1 text-muted-foreground"
                                >
                                    One item of a list. A change here changes
                                    every item.
                                </p>
                            </div>
                            <div
                                v-if="designPart.kind === 'cancel'"
                                class="flex rounded-md bg-muted p-0.5"
                                role="group"
                                aria-label="Which ones change"
                            >
                                <button
                                    v-for="choice in [
                                        { one: false, label: 'All like it' },
                                        { one: true, label: 'Only this one' },
                                    ]"
                                    :key="choice.label"
                                    type="button"
                                    :aria-pressed="onlyThisOne === choice.one"
                                    :class="[
                                        'min-h-11 flex-1 rounded px-2 text-sm transition-colors duration-quick select-none pointer-fine:min-h-8',
                                        onlyThisOne === choice.one
                                            ? 'bg-background font-medium shadow-xs'
                                            : 'text-muted-foreground hover:text-foreground',
                                    ]"
                                    @click="onlyThisOne = choice.one"
                                >
                                    {{ choice.label }}
                                </button>
                            </div>
                            <label class="block">
                                <span class="text-xs font-medium">Words</span>
                                <input
                                    :value="
                                        pickedLook.words ??
                                        (designPart.kind === 'heading'
                                            ? 'Your bookings'
                                            : 'Cancel')
                                    "
                                    class="mt-1.5 block h-11 w-full rounded-md bg-muted px-3 outline-none focus-visible:ring-2 focus-visible:ring-ring/50 pointer-fine:h-9"
                                    maxlength="24"
                                    @change="
                                        setLook({
                                            words: (
                                                $event.target as HTMLInputElement
                                            ).value,
                                        })
                                    "
                                />
                            </label>
                            <div role="group" aria-label="Colour">
                                <span class="text-xs font-medium">Colour</span>
                                <div class="mt-1.5 flex gap-1.5">
                                    <button
                                        v-for="choice in colourChoices"
                                        :key="choice.key"
                                        type="button"
                                        :aria-label="choice.label"
                                        :title="choice.label"
                                        :aria-pressed="
                                            chosenColour === choice.key
                                        "
                                        :class="[
                                            'grid size-11 place-items-center rounded-md border transition-shadow duration-quick pointer-fine:size-8',
                                            chosenColour === choice.key
                                                ? 'ring-2 ring-[#7c3aed] ring-offset-2 ring-offset-background'
                                                : 'hover:ring-1 hover:ring-border',
                                        ]"
                                        :style="{
                                            background: lookColour(choice.key),
                                            color:
                                                choice.key === 'primary' ||
                                                choice.key === 'foreground'
                                                    ? look?.colors.background
                                                    : look?.colors.foreground,
                                        }"
                                        @click="setLook({ colour: choice.key })"
                                    >
                                        <Check
                                            v-if="chosenColour === choice.key"
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </div>
                            </div>
                            <div
                                v-if="designPart.kind === 'cancel'"
                                role="group"
                                aria-label="Corners"
                            >
                                <span class="text-xs font-medium">Corners</span>
                                <div
                                    class="mt-1.5 flex rounded-md bg-muted p-0.5"
                                >
                                    <button
                                        v-for="choice in cornerChoices"
                                        :key="choice.key"
                                        type="button"
                                        :aria-pressed="
                                            chosenCorners === choice.key
                                        "
                                        :class="[
                                            'min-h-11 flex-1 rounded px-2 text-sm transition-colors duration-quick select-none pointer-fine:min-h-8',
                                            chosenCorners === choice.key
                                                ? 'bg-background font-medium shadow-xs'
                                                : 'text-muted-foreground hover:text-foreground',
                                        ]"
                                        :data-test="`designer-corners-${choice.label.toLowerCase()}`"
                                        @click="
                                            setLook({ corners: choice.key })
                                        "
                                    >
                                        {{ choice.label }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Edits wait to be kept, as in the workspace. -->
                        <div
                            class="flex min-h-14 items-center gap-2 border-t px-4 py-2 text-xs"
                            aria-live="polite"
                        >
                            <template v-if="designEdits > 0">
                                <p class="min-w-0 flex-1 text-muted-foreground">
                                    {{
                                        designEdits === 1
                                            ? '1 edit not kept yet'
                                            : `${designEdits} edits not kept yet`
                                    }}
                                </p>
                                <button
                                    type="button"
                                    class="min-h-11 rounded-md px-2.5 font-medium text-muted-foreground select-none hover:bg-muted hover:text-foreground pointer-fine:min-h-8"
                                    @click="undoDesign"
                                >
                                    Undo all
                                </button>
                                <button
                                    type="button"
                                    class="min-h-11 press rounded-md bg-primary px-3 font-medium text-primary-foreground select-none hover:bg-primary/90 pointer-fine:min-h-8"
                                    data-test="designer-keep"
                                    @click="keepDesign"
                                >
                                    Keep
                                </button>
                            </template>
                            <p
                                v-else-if="designKept"
                                class="flex items-center gap-1.5 text-muted-foreground"
                            >
                                <Check
                                    class="size-3.5 text-emerald-600 dark:text-emerald-400"
                                    aria-hidden="true"
                                />
                                Kept as a change you can undo.
                            </p>
                            <p v-else class="text-muted-foreground">
                                No AI, so it uses none of your plan.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Ready-made ideas: the same ones a new app can start from.
                 Picking one fills the box at the top. -->
            <section
                id="ideas"
                class="mx-auto max-w-7xl px-4 pt-24 sm:px-8 lg:pt-32"
            >
                <h2
                    v-reveal
                    class="max-w-5xl reveal font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-pretty sm:text-[2.75rem]"
                >
                    Start from a ready-made&nbsp;app.
                    <span class="text-muted-foreground"
                        >Then change&nbsp;anything.</span
                    >
                </h2>
                <div
                    v-reveal
                    class="mt-12 grid reveal items-start gap-8 delay-100 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] lg:gap-16"
                >
                    <div
                        class="grid gap-1"
                        role="group"
                        aria-label="Ready-made apps"
                        data-test="welcome-starters"
                    >
                        <button
                            v-for="item in starters"
                            :key="item.key"
                            type="button"
                            :aria-pressed="shownStarter?.key === item.key"
                            :class="[
                                'group grid min-h-11 press gap-0.5 rounded-md border px-4 py-3 text-left select-none',
                                shownStarter?.key === item.key
                                    ? 'border-border bg-muted'
                                    : 'border-transparent hover:bg-muted/60',
                            ]"
                            @click="shownStarterKey = item.key"
                        >
                            <span class="font-medium">{{ item.name }}</span>
                            <span
                                class="text-sm text-pretty text-muted-foreground"
                                >{{ item.purpose }}</span
                            >
                        </button>
                    </div>

                    <div v-if="shownStarter" class="lg:pt-1">
                        <div
                            :key="shownStarter.key"
                            class="rounded-xl border bg-background p-5 shadow-2xl shadow-black/10 motion-safe:animate-[rise-in_300ms_var(--ease-settle)_both] sm:p-7 dark:border-input"
                        >
                            <p class="text-sm text-muted-foreground">
                                The first version of {{ shownStarter.name }}
                                already does this
                            </p>
                            <ul class="mt-4 divide-y border-y">
                                <li
                                    v-for="(line, at) in shownStarter.includes"
                                    :key="line"
                                    class="flex gap-2.5 py-3 text-pretty motion-safe:animate-[rise-in_300ms_var(--ease-settle)_both]"
                                    :style="{
                                        animationDelay: `${80 + at * 70}ms`,
                                    }"
                                >
                                    <Check
                                        class="mt-1 size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                                        aria-hidden="true"
                                    />
                                    {{ line }}
                                </li>
                            </ul>
                            <button
                                type="button"
                                class="group mt-6 inline-flex min-h-11 press items-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground select-none hover:bg-primary/90 pointer-fine:min-h-10"
                                @click="useStarter(shownStarter)"
                            >
                                Start with {{ shownStarter.name }}
                                <ArrowRight
                                    class="size-4 transition-transform duration-base group-hover:translate-x-0.5"
                                    aria-hidden="true"
                                />
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Plain answers to what people ask before they start. -->
            <section
                id="questions"
                class="mx-auto grid max-w-7xl gap-10 px-4 pt-24 sm:px-8 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16 lg:pt-32"
            >
                <h2
                    v-reveal
                    class="max-w-3xl reveal font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-pretty sm:text-[2.75rem] lg:sticky lg:top-24 lg:self-start"
                >
                    Questions people&nbsp;ask.
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
                                class="group inline-flex min-h-11 shrink-0 press items-center gap-1.5 rounded-md bg-primary px-4 font-medium text-primary-foreground select-none hover:bg-primary/90 pointer-fine:min-h-10"
                                data-test="welcome-end-start"
                            >
                                Start
                                <ArrowRight
                                    class="size-4 transition-transform duration-base group-hover:translate-x-0.5"
                                    aria-hidden="true"
                                />
                            </button>
                        </div>
                    </div>
                </form>
            </section>
        </main>

        <PublicFooter />
    </div>
</template>
