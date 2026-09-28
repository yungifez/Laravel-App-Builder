<script setup lang="ts">
import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import {
    Check,
    ChevronRight,
    CircleAlert,
    CircleCheck,
    CircleDashed,
    Link2,
    LoaderCircle,
    SearchCheck,
    ShieldCheck,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FeatureRequestController from '@/actions/App/Http/Controllers/FeatureRequestController';
import NotesDraftPanel from '@/components/NotesDraftPanel.vue';
import NotesPart from '@/components/NotesPart.vue';
import PartsMap from '@/components/PartsMap.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { when } from '@/lib/when';
import { index, show } from '@/routes/projects';
import { update as updateCompatibility } from '@/routes/projects/compatibility';
import { show as showUnderstanding } from '@/routes/projects/understanding';
import type {
    CheckFinding,
    NotesDraft,
    NotesSection,
    ProjectSummary,
    UnderstandingArea,
} from '@/types';

const props = defineProps<{
    project: Pick<ProjectSummary, 'id' | 'name'>;
    revision: string | null;
    about: { introduction: string; sections: NotesSection[] };
    guidance: string | null;
    // What the owner wants the app to achieve, in their words.
    goal: string | null;
    // Whether changes keep the app's old data and links working; chosen
    // when the owner set it, otherwise it follows whether the app is used.
    compatibility: { keep: boolean; chosen: boolean; in_use: boolean };
    areas: UnderstandingArea[];
    problems: string[];
    changes: { id: string; summary: string; at: string | null }[];
    // All the changes kept; changes lists only the latest.
    kept: number;
    // The owner's last look at this page, and how many changes were kept
    // after it; null on a first look.
    since: string | null;
    fresh: number;
    looks: number;
    // Problems caught and fixed before the owner saw the kept changes.
    caught: number;
    // Shortcuts in the code fixed on my own in the background, still kept.
    tidied: number;
    // Across the kept changes: tests they added, and screens found to fit.
    proven: { tests: number; screens: number };
    // The product decisions behind kept changes, newest change first, then
    // answers only the notes hold (no change).
    decisions: {
        change: string | null;
        summary: string | null;
        at: string | null;
        question: string | null;
        decision: string;
        by: 'owner' | 'builder';
    }[];
    // How many decisions there are in all.
    decided: number;
    draft: NotesDraft | null;
    check?: CheckFinding[];
}>();

const checking = ref(false);

// "today" and "yesterday" read on their own; a date needs "on".
function onDay(at: string | null): string {
    const said = when(at);

    return said === '' || said === 'today' || said === 'yesterday'
        ? said
        : `on ${said}`;
}

// Kept after the owner's last look at this page.
function isNew(at: string | null): boolean {
    return (
        props.since !== null &&
        at !== null &&
        new Date(at).getTime() > new Date(props.since).getTime()
    );
}

// What the owner asked for in a part, counted in one line: how many things,
// and how many a test still checks, when the latest test run says.
function askedForSummary(area: UnderstandingArea): string {
    const count = area.asked_for.length;
    const lost = area.asked_for.filter((item) => item.checked === false).length;
    const things = `${count} ${count === 1 ? 'thing' : 'things'} you asked for`;

    if (lost > 0) {
        return `${things}, ${lost} whose test has changed since`;
    }

    return area.asked_for.some((item) => item.checked === null)
        ? `${things}, each proved by a test`
        : `${things}, each still checked by a test`;
}

// Enough of a part's checks to show what they cover, without a wall of text.
const CHECKS_SHOWN = 8;

// Notes are Markdown; the owner reads them as plain text. Lines wrapped
// in the file are joined, and list markers become bullets.
function plain(text: string): string {
    return text
        .replace(/\*\*(.+?)\*\*|__(.+?)__/g, '$1$2')
        .replace(/([^\n])\n(?!\s*[-*]\s|\n)\s*/g, '$1 ')
        .replace(/^\s*[-*]\s+/gm, '• ');
}

type Entry = { term: string | null; text: string };

// A notes section is usually a list of "**Name**: what it means" lines
// (people, terms). Split it so each entry can stand on its own tile.
function entries(body: string): Entry[] {
    return body
        .replace(/\n(?!\s*[-*]\s)\s*/g, ' ')
        .split('\n')
        .map((line) => line.replace(/^\s*[-*]\s+/, '').trim())
        .filter((line) => line !== '')
        .map((line) => {
            const named = line.match(/^\*\*(.+?)\*\*\s*[:—–-]\s*(.+)$/);

            if (named) {
                return { term: named[1], text: capitalise(named[2]) };
            }

            const bold = line.match(/\*\*(.+?)\*\*/);

            return {
                term: bold ? capitalise(bold[1]) : null,
                text: plain(line),
            };
        });
}

function capitalise(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

const sections = computed(() =>
    props.about.sections.map((section) => {
        const items = entries(section.body);

        return {
            ...section,
            items,
            tiles: items.length > 0 && items.every((item) => item.term),
        };
    }),
);

const rules = computed(() =>
    props.areas.reduce((total, area) => total + area.rules.length, 0),
);

// How much I know, said in one quiet line under the heading.
const facts = computed(() =>
    [
        [props.areas.length, 'part', 'parts'],
        [rules.value, 'rule', 'rules'],
        [props.kept + props.looks, 'change kept', 'changes kept'],
        [props.decided, 'decision', 'decisions'],
        [
            props.caught,
            'problem fixed before you saw it',
            'problems fixed before you saw them',
        ],
        [
            props.tidied,
            'thing tidied in the background',
            'things tidied in the background',
        ],
        [props.proven.tests, 'test added', 'tests added'],
        [
            props.proven.screens,
            'screen checked on a phone',
            'screens checked on a phone',
        ],
    ]
        // Zeros say nothing the empty sections below don't already say.
        .filter(([count]) => count !== 0)
        .map(([count, one, many]) => `${count} ${count === 1 ? one : many}`)
        .join(' · '),
);

// Linking a part to another scrolls there and briefly marks it, so the
// owner sees which card the link meant.
const marked = ref<string | null>(null);

function visit(key: string): void {
    document
        .getElementById(`part-${key}`)
        ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    marked.value = key;
    window.setTimeout(() => (marked.value = null), 1600);
}

function runCheck(): void {
    router.reload({
        only: ['check'],
        onStart: () => (checking.value = true),
        onFinish: () => (checking.value = false),
    });
}

watch(
    () => props.project,
    (project) =>
        setLayoutProps({
            breadcrumbs: [
                { title: 'Your apps', href: index() },
                { title: project.name, href: show(project.id) },
                {
                    title: 'What I know',
                    href: showUnderstanding(project.id),
                },
            ],
        }),
    { immediate: true },
);
// Why changes keep, or do not keep, the old way working, in the owner's
// words. A new app nobody uses yet is changed cleanly; one in use carries
// what it has forward. The owner can choose either.
const compatibilityReason = computed(() => {
    const { keep, chosen, in_use } = props.compatibility;

    if (chosen) {
        return keep
            ? 'You chose this. Changes carry what your app already has forward.'
            : 'You chose this. Changes are made cleanly, without keeping the old way working.';
    }

    return in_use
        ? 'On, because people may use your app. Changes carry what it already has forward.'
        : 'Off, because nobody uses your app yet. Changes are made cleanly, without keeping the old way working.';
});

function setCompatibility(keep: boolean | null): void {
    router.put(
        updateCompatibility(props.project.id).url,
        { keep_old_working: keep },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="`${project.name}: what I know`" />

    <div
        class="mx-auto flex max-w-5xl flex-col gap-20 px-4 pt-12 pb-24 sm:px-8"
    >
        <NotesDraftPanel
            v-if="draft !== null"
            :project-id="project.id"
            :draft="draft"
        />

        <p v-if="revision === null" class="text-sm text-muted-foreground">
            This app has no history yet, so there is nothing to show.
        </p>

        <template v-else>
            <!-- What the app is for, and how much I know about it -->
            <section data-test="about">
                <NotesPart
                    :project-id="project.id"
                    :revision="revision"
                    part="introduction"
                    :text="about.introduction"
                    label="what it is for"
                    variant="icon"
                >
                    <h1
                        v-if="about.introduction"
                        class="max-w-3xl pr-10 text-3xl leading-tight font-semibold tracking-[-0.025em] text-balance"
                    >
                        {{ plain(about.introduction) }}
                    </h1>
                    <h1 v-else class="text-3xl text-muted-foreground">
                        What is your app for?
                    </h1>
                </NotesPart>

                <div class="mt-4 max-w-3xl" data-test="goal">
                    <NotesPart
                        :project-id="project.id"
                        :revision="revision"
                        :part="`section:Goal`"
                        :text="goal ?? ''"
                        label="the goal"
                        :rows="2"
                        hint="For example: fewer phone calls to the front desk."
                        :variant="goal ? 'icon' : 'text'"
                    >
                        <p v-if="goal" class="pr-10 text-lg">
                            <span class="text-muted-foreground">Goal:</span>
                            {{ plain(goal) }}
                        </p>
                        <p v-else class="text-sm text-muted-foreground">
                            What should this app achieve? Tell me, and I will
                            say how each change helps.
                        </p>
                    </NotesPart>
                </div>

                <div
                    class="mt-4 flex max-w-3xl items-start gap-3"
                    data-test="compatibility"
                >
                    <Checkbox
                        id="keep-old-working"
                        class="mt-1"
                        :model-value="compatibility.keep"
                        @update:model-value="setCompatibility($event === true)"
                    />
                    <div class="min-w-0">
                        <label for="keep-old-working" class="font-medium"
                            >Keep old information and links working</label
                        >
                        <p
                            class="text-sm text-muted-foreground"
                            data-test="compatibility-reason"
                        >
                            {{ compatibilityReason }}
                            <button
                                v-if="compatibility.chosen"
                                type="button"
                                class="ml-1 underline underline-offset-2 hover:text-foreground"
                                data-test="compatibility-automatic"
                                @click="setCompatibility(null)"
                            >
                                Decide for me
                            </button>
                        </p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-3">
                    <p
                        v-if="facts"
                        class="text-muted-foreground"
                        data-test="facts"
                    >
                        {{ facts }}
                    </p>
                    <Button
                        variant="outline"
                        class="h-11 gap-1.5 select-none sm:ml-auto sm:h-9"
                        :disabled="checking"
                        data-test="check-button"
                        @click="runCheck"
                    >
                        <LoaderCircle
                            v-if="checking"
                            class="size-4 animate-spin"
                        />
                        <SearchCheck v-else class="size-4" />
                        Check my app
                    </Button>
                </div>

                <!-- Quick check: gaps between these notes and the app -->
                <div
                    v-if="check !== undefined"
                    class="mt-4"
                    data-test="quick-check"
                >
                    <p
                        v-if="check.length === 0"
                        class="flex items-center gap-2 text-sm"
                        data-test="check-clear"
                    >
                        <CircleCheck class="size-4 text-green-600" />
                        No obvious problems found.
                    </p>
                    <ul v-else class="space-y-2" data-test="check-findings">
                        <li
                            v-for="finding in check"
                            :key="finding.title"
                            class="flex gap-2 text-sm"
                        >
                            <CircleAlert
                                class="mt-0.5 size-4 shrink-0 text-amber-500"
                            />
                            <div class="min-w-0">
                                <p>{{ finding.title }}</p>
                                <Collapsible v-if="finding.details.length">
                                    <CollapsibleTrigger
                                        class="min-h-11 text-xs text-muted-foreground underline-offset-4 select-none hover:underline sm:min-h-0"
                                    >
                                        Details
                                    </CollapsibleTrigger>
                                    <CollapsibleContent>
                                        <ul
                                            class="mt-1 space-y-0.5 font-mono text-xs break-all text-muted-foreground"
                                        >
                                            <li
                                                v-for="detail in finding.details"
                                                :key="detail"
                                            >
                                                {{ detail }}
                                            </li>
                                        </ul>
                                    </CollapsibleContent>
                                </Collapsible>
                            </div>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- The parts of the app: a map of how they tie together, then
                 one card each with everything I know about that part -->
            <section class="space-y-4" data-test="areas">
                <h2
                    class="flex items-baseline gap-2 text-xl font-semibold tracking-[-0.02em]"
                >
                    How your app works
                    <span
                        v-if="areas.length"
                        class="font-normal text-muted-foreground tabular-nums"
                        >{{ areas.length }}</span
                    >
                </h2>

                <p
                    v-if="areas.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No parts are described yet.
                </p>

                <template v-else>
                    <!-- More than four parts crowd a phone-sized ring; the rows
                         below say the same thing there -->
                    <PartsMap
                        :class="areas.length > 4 && 'hidden sm:block'"
                        :areas="areas"
                        @visit="visit"
                    />

                    <ul class="divide-y border-y">
                        <li
                            v-for="area in areas"
                            :id="`part-${area.key}`"
                            :key="area.key"
                            :class="[
                                'grid scroll-mt-20 gap-6 py-8 transition-colors duration-linger md:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] md:gap-12',
                                marked === area.key && 'bg-muted/40',
                            ]"
                            :data-test="`part-${area.key}`"
                        >
                            <div class="space-y-3">
                                <h3
                                    class="flex items-center gap-2 text-xl font-semibold tracking-tight break-words"
                                >
                                    {{ area.name }}
                                    <!-- Counted from what the app's tests ran, when known -->
                                    <span
                                        v-if="
                                            area.checked_by
                                                ? true
                                                : area.checked_by === null &&
                                                  area.tested
                                        "
                                        class="flex items-center gap-1 text-xs font-normal tracking-normal text-muted-foreground"
                                        title="Tests check this part"
                                        data-test="part-checked"
                                    >
                                        <ShieldCheck
                                            class="size-3.5 text-green-600"
                                        />
                                        {{
                                            area.checked_by
                                                ? `Checked by ${area.checked_by} ${area.checked_by === 1 ? 'test' : 'tests'}`
                                                : 'Tested'
                                        }}
                                    </span>
                                    <span
                                        v-else-if="area.checked_by === 0"
                                        class="flex items-center gap-1 text-xs font-normal tracking-normal text-muted-foreground"
                                        title="No test runs this part yet"
                                        data-test="part-unchecked"
                                    >
                                        <CircleDashed
                                            class="size-3.5 text-amber-600"
                                        />
                                        Nothing checks this yet
                                    </span>
                                </h3>
                                <NotesPart
                                    :project-id="project.id"
                                    :revision="revision"
                                    :part="`summary:${area.key}`"
                                    :text="area.summary ?? ''"
                                    label="what it does"
                                    :rows="3"
                                    variant="icon"
                                >
                                    <p class="pr-8 text-muted-foreground">
                                        {{
                                            area.summary ?? 'Not described yet.'
                                        }}
                                    </p>
                                </NotesPart>
                                <ul
                                    v-if="area.behaviors.length"
                                    class="flex flex-wrap gap-x-4 gap-y-1 text-sm font-medium"
                                    aria-label="People can"
                                >
                                    <li
                                        v-for="behavior in area.behaviors"
                                        :key="behavior"
                                    >
                                        {{ behavior }}
                                    </li>
                                </ul>
                                <p
                                    v-if="area.connections.length"
                                    class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground"
                                >
                                    <Link2 class="size-3.5" />
                                    <button
                                        v-for="connection in area.connections"
                                        :key="connection.to"
                                        type="button"
                                        :class="[
                                            'min-h-11 underline-offset-4 select-none hover:text-foreground sm:min-h-6',
                                            connection.strength === 'strong'
                                                ? 'underline'
                                                : 'underline decoration-dashed',
                                        ]"
                                        :title="connection.reason"
                                        @click="visit(connection.to)"
                                    >
                                        {{ connection.name }}
                                    </button>
                                </p>
                                <!-- The proof behind the count, in the tests' own words -->
                                <details
                                    v-if="area.checks.length"
                                    class="group text-sm"
                                    data-test="part-checks"
                                >
                                    <summary
                                        class="flex min-h-11 cursor-pointer list-none items-center gap-1.5 text-muted-foreground select-none hover:text-foreground sm:min-h-6"
                                    >
                                        <ChevronRight
                                            class="size-3.5 transition-transform group-open:rotate-90"
                                        />
                                        What the tests check
                                    </summary>
                                    <ul
                                        class="mt-2 space-y-1.5 pl-5 text-muted-foreground"
                                    >
                                        <li
                                            v-for="check in area.checks.slice(
                                                0,
                                                CHECKS_SHOWN,
                                            )"
                                            :key="check"
                                            class="flex gap-2"
                                        >
                                            <CircleCheck
                                                class="mt-1 size-3 shrink-0 text-green-600"
                                            />
                                            {{ check }}
                                        </li>
                                        <li
                                            v-if="
                                                area.checks.length >
                                                CHECKS_SHOWN
                                            "
                                            class="pl-5"
                                        >
                                            and
                                            {{
                                                area.checks.length -
                                                CHECKS_SHOWN
                                            }}
                                            more
                                        </li>
                                    </ul>
                                </details>
                                <!-- A part nothing checks: one tap asks for its tests -->
                                <Form
                                    v-else-if="area.checked_by === 0"
                                    v-bind="
                                        FeatureRequestController.store.form(
                                            project.id,
                                        )
                                    "
                                    v-slot="{ processing }"
                                >
                                    <input
                                        type="hidden"
                                        name="prompt"
                                        :value="`Add tests that check ${area.name} works as described`"
                                    />
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="h-11 select-none sm:h-8"
                                        :disabled="processing"
                                        data-test="part-ask-tests"
                                    >
                                        Ask for tests
                                    </Button>
                                </Form>
                                <!-- Product simplification the owner can ask
                                     for (direction 18 §13); nothing is removed
                                     until they say so -->
                                <Link
                                    v-if="area.behaviors.length > 1"
                                    :href="
                                        show(project.id, {
                                            query: {
                                                ask: `Show me the simplest version of ${area.name}, with fewer choices for people to make. Ask me before you remove anything.`,
                                            },
                                        })
                                    "
                                    class="-my-2 inline-flex min-h-11 items-center gap-1.5 text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline sm:min-h-0"
                                    data-test="part-simplify"
                                >
                                    Simplify this
                                </Link>
                            </div>

                            <div class="min-w-0 space-y-6">
                                <NotesPart
                                    :project-id="project.id"
                                    :revision="revision"
                                    :part="`rules:${area.key}`"
                                    :text="area.rules.join('\n')"
                                    :label="`the rules for ${area.name}`"
                                    :rows="Math.max(3, area.rules.length + 1)"
                                    hint="One rule per line."
                                    variant="icon"
                                >
                                    <p
                                        class="mb-3 text-sm font-medium text-muted-foreground"
                                    >
                                        Always true
                                    </p>
                                    <ul
                                        v-if="area.rules.length"
                                        class="space-y-2.5 pr-8"
                                    >
                                        <li
                                            v-for="rule in area.rules"
                                            :key="rule"
                                            class="flex gap-2.5"
                                        >
                                            <Check
                                                class="mt-1 size-3.5 shrink-0 text-muted-foreground"
                                            />
                                            <span class="min-w-0">{{
                                                plain(rule)
                                            }}</span>
                                        </li>
                                    </ul>
                                    <p
                                        v-else
                                        class="text-sm text-muted-foreground"
                                    >
                                        No rules yet.
                                    </p>
                                </NotesPart>
                                <!-- The owner's own requests, each proved by a test when kept -->
                                <details
                                    v-if="area.asked_for.length"
                                    class="group text-sm"
                                    data-test="part-asked-for"
                                >
                                    <summary
                                        class="flex min-h-11 cursor-pointer list-none items-center gap-1.5 text-muted-foreground select-none hover:text-foreground sm:min-h-6"
                                    >
                                        <ChevronRight
                                            class="size-3.5 transition-transform group-open:rotate-90"
                                        />
                                        {{ askedForSummary(area) }}
                                    </summary>
                                    <ul class="mt-3 space-y-2.5 pl-5">
                                        <li
                                            v-for="item in area.asked_for"
                                            :key="item.text"
                                            class="flex gap-2.5"
                                        >
                                            <CircleDashed
                                                v-if="item.checked === false"
                                                class="mt-1 size-3.5 shrink-0 text-amber-600"
                                            />
                                            <ShieldCheck
                                                v-else
                                                class="mt-1 size-3.5 shrink-0 text-green-600"
                                            />
                                            <span class="min-w-0">
                                                {{ item.text }}
                                                <span
                                                    v-if="
                                                        item.checked === false
                                                    "
                                                    class="text-amber-700 dark:text-amber-500"
                                                    data-test="asked-for-unchecked"
                                                    >The test that proved this
                                                    has changed or gone.</span
                                                >
                                            </span>
                                        </li>
                                    </ul>
                                </details>
                            </div>
                        </li>
                    </ul>
                </template>
            </section>

            <!-- People, terms and any other notes, side by side -->
            <div
                v-if="sections.length"
                class="grid gap-16 lg:grid-cols-2 lg:gap-12"
            >
                <section
                    v-for="section in sections"
                    :key="section.heading"
                    :data-test="`section-${section.heading}`"
                >
                    <NotesPart
                        :project-id="project.id"
                        :revision="revision"
                        :part="`section:${section.heading}`"
                        :text="section.body"
                        :label="section.heading.toLowerCase()"
                        :rows="6"
                        variant="icon"
                    >
                        <h2
                            class="mb-4 flex items-baseline gap-2 text-xl font-semibold tracking-[-0.02em]"
                        >
                            {{ section.heading }}
                            <span
                                v-if="section.tiles"
                                class="font-normal text-muted-foreground tabular-nums"
                                >{{ section.items.length }}</span
                            >
                        </h2>
                        <dl v-if="section.tiles" class="divide-y border-y">
                            <div
                                v-for="item in section.items"
                                :key="item.term ?? item.text"
                                class="flex gap-4 py-3"
                            >
                                <dt
                                    class="w-24 shrink-0 font-medium break-words"
                                >
                                    {{ item.term }}
                                </dt>
                                <dd
                                    class="min-w-0 break-words text-muted-foreground"
                                >
                                    {{ item.text }}
                                </dd>
                            </div>
                        </dl>
                        <p
                            v-else
                            class="max-w-prose whitespace-pre-line text-muted-foreground"
                        >
                            {{ plain(section.body) }}
                        </p>
                    </NotesPart>
                </section>
            </div>

            <!-- How it should be built, and what changed so far -->
            <div class="grid gap-16 lg:grid-cols-2 lg:gap-12">
                <section data-test="guidance">
                    <NotesPart
                        :project-id="project.id"
                        :revision="revision"
                        :part="`section:Engineering direction`"
                        :text="guidance ?? ''"
                        label="the guidance"
                        :rows="6"
                        hint="One point per line works well."
                        :variant="guidance ? 'icon' : 'text'"
                    >
                        <h2
                            class="mb-4 text-xl font-semibold tracking-[-0.02em]"
                        >
                            Guidance from your developer
                        </h2>
                        <p
                            v-if="guidance"
                            class="max-w-prose whitespace-pre-line"
                        >
                            {{ plain(guidance) }}
                        </p>
                        <p v-else class="text-sm text-muted-foreground">
                            None yet. I follow anything written here in every
                            change.
                        </p>
                    </NotesPart>
                </section>

                <section data-test="what-changed">
                    <h2 class="mb-4 text-xl font-semibold tracking-[-0.02em]">
                        What changed
                    </h2>
                    <p
                        v-if="fresh > 0"
                        class="-mt-2 mb-4 text-sm text-muted-foreground"
                        data-test="changed-since"
                    >
                        {{ fresh }}
                        {{ fresh === 1 ? 'change' : 'changes' }} since you last
                        looked {{ onDay(since) }}.
                    </p>

                    <ol
                        v-if="changes.length || looks > 0"
                        class="relative ml-1 border-l pl-5"
                    >
                        <li
                            v-for="change in changes"
                            :key="change.id"
                            class="relative"
                        >
                            <span
                                class="absolute top-4 -left-[1.6rem] size-2.5 rounded-full border-2 border-background bg-foreground"
                                aria-hidden="true"
                            />
                            <Link
                                :href="
                                    show(project.id, {
                                        query: { change: change.id },
                                    })
                                "
                                class="-mx-2 flex min-h-11 items-baseline justify-between gap-4 rounded-md px-2 py-2.5 transition-colors duration-quick hover:bg-muted/50"
                            >
                                <span class="min-w-0"
                                    >{{ change.summary
                                    }}<span
                                        v-if="isNew(change.at)"
                                        class="ml-2 rounded-sm bg-foreground/10 px-1.5 py-0.5 text-xs font-medium text-foreground"
                                        data-test="change-new"
                                        >New</span
                                    ></span
                                >
                                <span
                                    class="shrink-0 text-xs text-muted-foreground tabular-nums"
                                    >{{ when(change.at) }}</span
                                >
                            </Link>
                        </li>
                        <li v-if="looks > 0" class="relative py-2.5">
                            <span
                                class="absolute top-4 -left-[1.6rem] size-2.5 rounded-full border-2 border-background bg-muted-foreground/50"
                                aria-hidden="true"
                            />
                            <span class="text-muted-foreground"
                                >And {{ looks }}
                                {{ looks === 1 ? 'change' : 'changes' }} to how
                                it looks.</span
                            >
                        </li>
                    </ol>
                    <p v-else class="text-sm text-muted-foreground">
                        No changes kept yet.
                    </p>
                </section>
            </div>

            <section v-if="decisions.length" data-test="decisions">
                <h2
                    class="mb-1 flex items-baseline gap-2 text-xl font-semibold tracking-[-0.02em]"
                >
                    Decisions
                    <span
                        class="font-normal text-muted-foreground tabular-nums"
                        >{{ decided }}</span
                    >
                </h2>
                <p class="mb-4 max-w-prose text-sm text-muted-foreground">
                    Choices made along the way that your app now follows.
                </p>
                <ul class="border-t">
                    <li
                        v-for="(item, index) in decisions"
                        :key="`${item.change}-${index}`"
                        :class="
                            decisions[index + 1]?.change === item.change
                                ? 'pt-3'
                                : 'border-b py-3'
                        "
                    >
                        <p
                            v-if="item.question"
                            class="text-sm text-muted-foreground"
                        >
                            {{ item.question }}
                        </p>
                        <div class="flex items-start justify-between gap-4">
                            <p class="max-w-prose min-w-0 break-words">
                                {{ item.decision }}
                            </p>
                            <Link
                                :href="
                                    show(project.id, {
                                        query: {
                                            ask: `Change this: “${item.decision}”\n\nInstead, `,
                                        },
                                    })
                                "
                                class="-my-2 inline-flex min-h-11 shrink-0 items-center text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline sm:min-h-0"
                                data-test="change-decision"
                            >
                                Change
                            </Link>
                        </div>
                        <p
                            v-if="
                                item.change === null &&
                                decisions[index + 1]?.change !== item.change
                            "
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            You chose this
                        </p>
                        <Link
                            v-else-if="
                                decisions[index + 1]?.change !== item.change
                            "
                            :href="
                                show(project.id, {
                                    query: { change: item.change },
                                })
                            "
                            class="mt-1 inline-flex min-h-11 items-center text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline sm:min-h-0"
                        >
                            {{
                                item.by === 'owner'
                                    ? 'You chose this'
                                    : 'I chose this and you kept it'
                            }}
                            {{ onDay(item.at) }}
                        </Link>
                    </li>
                </ul>
            </section>

            <Collapsible v-if="problems.length">
                <CollapsibleTrigger
                    class="min-h-11 text-xs text-muted-foreground select-none hover:underline sm:min-h-0"
                >
                    Details: some notes could not be read
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <ul class="mt-1 font-mono text-xs text-muted-foreground">
                        <li v-for="problem in problems" :key="problem">
                            {{ problem }}
                        </li>
                    </ul>
                </CollapsibleContent>
            </Collapsible>
        </template>
    </div>
</template>
