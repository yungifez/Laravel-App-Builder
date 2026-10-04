<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Check, CircleDashed, Minus } from '@lucide/vue';
import PageMeta from '@/components/PageMeta.vue';
import { register } from '@/routes';
import { index } from '@/routes/projects';
import * as sampleDesign from '@/routes/sample-design';

// One change, from the ask to keeping it, in the order the workspace runs
// it. Every line must stay true of the product as it is: the checks are
// the ones the home page names, and the marks are the change page's own.
const steps: {
    title: string;
    body: string;
    points?: string[];
}[] = [
    {
        title: 'Say what you want.',
        body: 'Describe a change in plain words, as you would to a person. The AI works out what your app needs and makes the change.',
    },
    {
        title: 'The same checks run on every change.',
        body: 'The AI never decides it is done. A change is ready only when these checks pass. Your app’s packages are also scanned, and you are told about any serious known security problem.',
        points: [
            'Your app’s own tests pass',
            'The code fits together',
            'The code is set out tidily',
        ],
    },
    {
        title: 'It is tried when things go wrong.',
        body: 'The checks make email fail and outside services stop answering, then see if your app copes. When a change touches dates, they also try it on dates that often break code, such as a leap day and the last second of a year.',
    },
    {
        title: 'You see what was tested, and what was not.',
        body: 'Each part of the change says how the app knows it works. Nothing unchecked is hidden, so you know what to try yourself before you keep it.',
    },
    {
        title: 'Keep it, or undo it later.',
        body: 'Each change you keep is saved on its own. Undo yesterday’s change, and today’s stays. You can download your app’s code any time.',
    },
    {
        title: 'Change the look without the AI.',
        body: 'Click a part of your app and change its words, colours or corners. Design edits make no AI call, so they use none of your plan.',
    },
];

const marks = [
    {
        key: 'tested',
        label: 'Checked by a test',
        means: 'A test shows it works, and the test stays with your app.',
    },
    {
        key: 'untouched',
        label: 'Not touched by this change',
        means: 'This change did not alter the code for it.',
    },
    {
        key: 'unchecked',
        label: 'Not checked yet',
        means: 'No test covered it, so try it yourself before you keep the change.',
    },
];
</script>

<template>
    <PageMeta
        title="How it works"
        description="The AI makes each change, but fixed checks decide if it works. You see what was tested and what was not, and you can undo a kept change on its own."
    />

    <section class="mx-auto max-w-7xl px-4 pt-20 pb-24 sm:px-8 sm:pt-32">
        <h1
            class="max-w-4xl font-display text-4xl leading-[1.05] font-medium tracking-[-0.035em] text-balance sm:text-6xl"
        >
            The AI makes the change.
            <span class="text-muted-foreground"
                >Checks decide if it works.</span
            >
        </h1>
        <p class="mt-6 max-w-2xl text-lg text-pretty text-muted-foreground">
            This is why your app does not stay a prototype. Here is one change,
            from the ask to keeping it.
        </p>

        <ol class="mt-16 border-t sm:mt-24" data-test="how-it-works-steps">
            <li
                v-for="(step, at) in steps"
                :key="step.title"
                class="grid gap-4 border-b py-10 sm:py-14 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16"
            >
                <h2
                    class="flex gap-4 font-display text-2xl leading-[1.15] font-medium tracking-[-0.02em] text-balance sm:text-3xl"
                >
                    <span
                        class="w-7 shrink-0 text-muted-foreground tabular-nums"
                        aria-hidden="true"
                        >{{ at + 1 }}</span
                    >
                    {{ step.title }}
                </h2>
                <div class="pl-11 lg:pl-0">
                    <p class="max-w-2xl text-lg text-pretty">
                        {{ step.body }}
                    </p>
                    <ul v-if="step.points" class="mt-6 grid max-w-2xl gap-3">
                        <li
                            v-for="point in step.points"
                            :key="point"
                            class="flex gap-2 text-pretty"
                        >
                            <Check
                                class="mt-1 size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                                aria-hidden="true"
                            />
                            {{ point }}
                        </li>
                    </ul>
                    <dl v-if="at === 3" class="mt-6 grid max-w-2xl gap-4">
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
                                <span class="font-medium">{{
                                    mark.label
                                }}</span>
                            </dt>
                            <dd
                                class="col-start-2 text-pretty text-muted-foreground"
                            >
                                {{ mark.means }}
                            </dd>
                        </div>
                    </dl>
                    <Link
                        v-if="at === steps.length - 1"
                        :href="sampleDesign.show().url"
                        class="mt-6 inline-flex min-h-11 items-center gap-1.5 font-medium text-primary underline-offset-4 hover:underline pointer-fine:min-h-0"
                    >
                        Try the designer
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </div>
            </li>
        </ol>

        <div class="mt-12 flex flex-wrap items-center gap-x-6 gap-y-3 sm:mt-16">
            <Link
                :href="$page.props.auth.user ? index() : register()"
                class="group inline-flex min-h-11 press items-center gap-1.5 rounded-md bg-primary px-5 font-medium text-primary-foreground select-none hover:bg-primary/90 pointer-fine:min-h-10"
                data-test="how-it-works-start"
            >
                {{ $page.props.auth.user ? 'Your apps' : 'Start an app' }}
                <ArrowRight
                    class="size-4 transition-transform duration-quick group-hover:translate-x-0.5"
                    aria-hidden="true"
                />
            </Link>
            <p class="text-sm text-muted-foreground">
                Free to start. No card needed.
            </p>
        </div>
    </section>
</template>
