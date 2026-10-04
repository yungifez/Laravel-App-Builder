<script setup lang="ts">
import { Plus } from '@lucide/vue';
import PageMeta from '@/components/PageMeta.vue';

// Each line must stay true of the product as it is. The comment above
// each part names the code that makes it true; change the words when
// that code changes. Anything only partly true stays off this page.
const parts = [
    {
        // AccessProbes, run by VerifyFeatureRequest; a found way in fails
        // the change.
        heading: 'Changes to your records are tried by strangers.',
        tail: 'Before you keep it.',
        lines: [
            'When a change touches who may see or change something, the checks sign in as someone else, and also try while signed out. They try to open, change or remove what is not theirs.',
            'If one of them gets through, the change is not kept, and you see what got through.',
        ],
    },
    {
        // builder.agents.protected_paths: the AI's edits there are thrown
        // away, and the fixed tests are put back before they run.
        heading: 'The AI cannot change the checks.',
        tail: 'So it cannot pass by moving the goal.',
        lines: [
            'The tests that guard your app, and the files that decide how they run, are kept apart. If the AI edits them, the edit is thrown away before the checks start.',
        ],
    },
    {
        // Project::service_keys is cast encrypted and hidden; the keys
        // reach only Preview and the publishing hosts, never WriteBrief.
        heading: 'Your keys stay out of the AI’s hands.',
        tail: 'Your app gets them when it runs.',
        lines: [
            'Keys you paste for a payment or email service are stored encrypted. We never show them again, even to you.',
            'They go only to your app’s preview and to the app you publish. The AI that writes your changes is never given them.',
        ],
    },
    {
        // VerifyFeatureRequest::auditPackages: high and critical only, and
        // advice, never a reason to fail the change.
        heading: 'We look for known problems in the parts your app uses.',
        tail: 'And tell you what we find.',
        lines: [
            'Each change looks up the outside code packages your app relies on in public lists of security problems. When one has a serious problem, you are told.',
            'This does not stop the change, because a problem in a package is rarely the change’s doing.',
        ],
    },
];

// What people ask, answered only as far as the code backs it.
const questions = [
    {
        // PreviewGateway: owner-only access, noindex; ShareApp and
        // builder.preview.share_days.
        ask: 'Who can see my app before I publish it?',
        answer: 'Only you, while you are signed in. Search engines are told not to list it. If you share a link, it stops working after a set number of days, or as soon as you stop sharing.',
    },
    {
        // fortify.php features; essentials SetDefaultPassword in production.
        ask: 'How is my account kept safe?',
        answer: 'You confirm your email address when you sign up. You can add a second step at sign-in, with a code from an app on your phone, or sign in with a passkey instead of a password. Passwords must be at least 12 characters, mix letters, numbers and symbols, and not appear in known leaks.',
    },
    {
        // ProjectDownloadController and PackProject.
        ask: 'Can I take my code and leave?',
        answer: 'Yes. Download your app’s code any time. It is yours, so a developer can take it over without asking us.',
    },
    {
        // resources/markdown/privacy.md, "Who else sees it".
        ask: 'Who else sees my code?',
        answer: 'To plan, write and check a change, we send your request and the parts of your code it needs to the AI providers named in our privacy notice. We do not sell your data.',
    },
];
</script>

<template>
    <PageMeta
        title="Security"
        description="Changes to your records are tried by someone who should not get in, before you keep them. Your service keys never reach the AI."
    />

    <section class="mx-auto max-w-7xl px-4 pt-20 pb-24 sm:px-8 sm:pt-32">
        <h1
            class="max-w-3xl font-display text-4xl leading-[1.05] font-medium tracking-[-0.035em] text-balance sm:text-6xl"
        >
            Safe by checking, not by promise.
            <span class="text-muted-foreground"
                >Here is what we check today.</span
            >
        </h1>

        <div
            v-for="part in parts"
            :key="part.heading"
            class="mt-28 grid gap-6 sm:mt-40 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16"
        >
            <h2
                class="font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-balance sm:text-[2.75rem]"
            >
                {{ part.heading }}
                <span class="text-muted-foreground">{{ part.tail }}</span>
            </h2>
            <div class="max-w-xl space-y-4 text-lg text-pretty lg:pt-2">
                <p
                    v-for="(line, at) in part.lines"
                    :key="line"
                    :class="at > 0 && 'text-muted-foreground'"
                >
                    {{ line }}
                </p>
            </div>
        </div>

        <div
            class="mt-28 grid gap-10 sm:mt-40 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16"
        >
            <h2
                class="font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-balance sm:text-[2.75rem]"
            >
                Questions about&nbsp;safety.
            </h2>
            <div class="divide-y border-y" data-test="security-questions">
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
                    <p class="max-w-2xl pb-5 text-pretty text-muted-foreground">
                        {{ question.answer }}
                    </p>
                </details>
            </div>
        </div>
    </section>
</template>
