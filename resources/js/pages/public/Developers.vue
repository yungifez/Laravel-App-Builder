<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { FileSearch, MessageSquare, PenLine } from '@lucide/vue';
import DeveloperApplicationController from '@/actions/App/Http/Controllers/DeveloperApplicationController';
import InputError from '@/components/InputError.vue';
import PageMeta from '@/components/PageMeta.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { stamp } from '@/lib/operations';
import { apply } from '@/routes/developers';
import { index as questions } from '@/routes/operations/developer-reviews';

// Developers ask to answer owners' questions. An operator decides; until
// then the person can change what they wrote.
defineProps<{
    signedIn: boolean;
    verified: boolean;
    operator: boolean;
    application: {
        about: string;
        link: string | null;
        status: 'waiting' | 'approved' | 'declined';
        applied_at: string | null;
    } | null;
}>();

const steps = [
    {
        icon: MessageSquare,
        title: 'An owner asks',
        body: 'About their whole app or about one change, in their own words.',
    },
    {
        icon: FileSearch,
        title: 'You take the question',
        body: 'You get what the app is for, its rules and decisions, the parts the question touches, and the code at that commit.',
    },
    {
        icon: PenLine,
        title: 'You answer',
        body: 'Write your answer and the rules you would hold every later change to. The owner keeps the rules they agree with.',
    },
];

const field =
    'w-full rounded-md border border-input bg-background px-3 py-2 text-base shadow-xs dark:bg-input/30 outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
</script>

<template>
    <PageMeta
        title="For developers"
        description="Answer app owners' questions when a decision needs an engineer, and set the rules later changes are held to."
    />

    <section class="mx-auto max-w-7xl px-4 pt-20 sm:px-8 sm:pt-32">
        <h1
            class="max-w-3xl font-display text-4xl leading-[1.05] font-medium tracking-[-0.035em] text-balance sm:text-6xl"
        >
            Lend your judgment to apps people build here.
            <span class="text-muted-foreground"
                >Owners ask when a decision needs an engineer.</span
            >
        </h1>

        <ul
            class="mt-12 grid gap-8 rounded-md bg-panel-green px-6 py-8 sm:mt-16 sm:grid-cols-3 sm:px-10 sm:py-12"
        >
            <li v-for="step in steps" :key="step.title" class="max-w-sm">
                <h2 class="flex items-center gap-2.5 font-medium">
                    <component
                        :is="step.icon"
                        class="size-4 shrink-0"
                        aria-hidden="true"
                    />
                    {{ step.title }}
                </h2>
                <p
                    class="mt-1.5 pl-6.5 text-sm text-pretty text-muted-foreground"
                >
                    {{ step.body }}
                </p>
            </li>
        </ul>
    </section>

    <section
        id="apply"
        class="mx-auto max-w-7xl scroll-mt-20 px-4 pt-28 pb-24 sm:px-8 sm:pt-40"
    >
        <h2
            class="max-w-3xl font-display text-3xl leading-[1.1] font-medium tracking-[-0.025em] text-balance sm:text-[2.75rem]"
        >
            Answer questions with us.
            <span class="text-muted-foreground"
                >Tell us what you have built, and a person decides.</span
            >
        </h2>

        <template v-if="operator">
            <p class="mt-3 max-w-xl text-muted-foreground">
                You answer questions as one of the people who run the platform
                already.
            </p>
            <Link
                :href="questions().url"
                class="mt-6 inline-flex min-h-11 press items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground select-none hover:bg-primary/90 sm:min-h-9"
                >See the questions</Link
            >
        </template>

        <template v-else-if="application?.status === 'approved'">
            <p
                class="mt-3 max-w-xl text-muted-foreground"
                data-test="developer-approved"
            >
                You can answer owners' questions. You hear when an owner asks
                one.
            </p>
            <Link
                :href="questions().url"
                class="mt-6 inline-flex min-h-11 press items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground select-none hover:bg-primary/90 sm:min-h-9"
                >See the questions</Link
            >
        </template>

        <template v-else-if="!signedIn">
            <p class="mt-3 max-w-xl text-muted-foreground">
                Log in first, or make an account.
            </p>
            <Link
                :href="apply().url"
                class="mt-6 inline-flex min-h-11 press items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground select-none hover:bg-primary/90 sm:min-h-9"
                data-test="developer-sign-in"
                >Log in to ask</Link
            >
        </template>

        <template v-else>
            <p
                v-if="application?.status === 'waiting'"
                class="mt-3 max-w-xl text-muted-foreground"
                data-test="developer-waiting"
            >
                We have your request from {{ stamp(application.applied_at) }}. A
                person reads it, and we tell you when we have decided. You can
                change what you wrote until then.
            </p>
            <p
                v-else-if="application?.status === 'declined'"
                class="mt-3 max-w-xl text-muted-foreground"
                data-test="developer-declined"
            >
                We could not take you on last time. You can ask again when you
                have more to show.
            </p>
            <p v-else class="mt-3 max-w-xl text-muted-foreground">
                A person reads every request and replies by email.
            </p>
            <p v-if="!verified" class="mt-2 max-w-xl text-sm text-amber-600">
                Confirm your email address first: we sent you a link when you
                made your account.
            </p>

            <Form
                v-bind="DeveloperApplicationController.store.form()"
                class="mt-8 max-w-xl space-y-6"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="about">What you have built with Laravel</Label>
                    <span class="text-sm text-muted-foreground"
                        >A few sentences: the apps, the parts you know best, and
                        for how long.</span
                    >
                    <textarea
                        id="about"
                        name="about"
                        rows="6"
                        required
                        minlength="40"
                        maxlength="3000"
                        :value="application?.about ?? ''"
                        :class="field"
                        data-test="developer-about"
                    />
                    <InputError :message="errors.about" />
                </div>

                <div class="grid gap-2">
                    <Label for="link">Where we can see your work</Label>
                    <Input
                        id="link"
                        name="link"
                        type="url"
                        :default-value="application?.link ?? ''"
                        placeholder="https://github.com/you"
                        data-test="developer-link"
                    />
                    <InputError :message="errors.link" />
                </div>

                <Button :disabled="processing" data-test="developer-apply">
                    {{
                        application?.status === 'waiting'
                            ? 'Save the changes'
                            : 'Ask to join'
                    }}
                </Button>
            </Form>
        </template>
    </section>
</template>
