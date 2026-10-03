<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import DeveloperApplicationController from '@/actions/App/Http/Controllers/DeveloperApplicationController';
import InputError from '@/components/InputError.vue';
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
        title: 'An owner asks',
        body: 'About their whole app or about one change, in their own words.',
    },
    {
        title: 'You take the question',
        body: 'You get what the app is for, its rules and decisions, the parts the question touches, and the code at that commit.',
    },
    {
        title: 'You answer',
        body: 'A short answer, what you noticed, and guidance. The owner keeps the guidance they agree with, and every later change follows it.',
    },
];

const field =
    'w-full rounded-md border border-input bg-background px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
</script>

<template>
    <Head title="For developers" />

    <section class="bg-muted/50">
        <div class="mx-auto max-w-6xl px-4 pt-16 pb-16 sm:px-6 sm:pt-24">
            <h1
                class="max-w-2xl font-display text-4xl leading-[1.05] sm:text-5xl"
            >
                Lend your judgment to apps people build here
            </h1>
            <p class="mt-4 max-w-xl text-lg text-muted-foreground">
                Owners build Laravel apps with us in plain words. When a
                decision needs an engineer, they ask one of our developers. You
                write no code: you answer, and your answer shapes every change
                after it.
            </p>

            <ol class="mt-12 grid max-w-4xl gap-8 sm:grid-cols-3">
                <li
                    v-for="(step, at) in steps"
                    :key="step.title"
                    class="border-t border-foreground/15 pt-4"
                >
                    <span class="text-sm text-muted-foreground">{{
                        at + 1
                    }}</span>
                    <h2 class="mt-1 font-medium">{{ step.title }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ step.body }}
                    </p>
                </li>
            </ol>
        </div>
    </section>

    <section id="apply" class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <h2 class="text-2xl font-semibold tracking-[-0.02em]">
            Answer questions with us
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
                Log in, or make an account, and then tell us what you have
                built.
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
