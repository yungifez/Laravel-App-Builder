<script setup lang="ts">
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
import PersonPlanController from '@/actions/App/Http/Controllers/Operations/PersonPlanController';
import InputError from '@/components/InputError.vue';
import { stamp, usd } from '@/lib/operations';
import { attention } from '@/routes/operations';
import { index, show, signIn as signInAs } from '@/routes/operations/people';

// One person: who they are, their plan and use, their apps and what they
// wrote to us.
const props = defineProps<{
    person: {
        id: number;
        name: string;
        email: string;
        verified: boolean;
        joined_at: string | null;
        two_factor: boolean;
        plan: string;
        granted: string | null;
        granted_until: string | null;
        stripe: boolean;
        can_sign_in_as: boolean;
        usage: {
            percent: number;
            used_usd: number;
            allowance_usd: number;
            resets_on: string;
            unlimited: boolean;
        };
    };
    plans: { key: string; name: string }[];
    apps: { name: string; changes: number; created_at: string | null }[];
    messages: {
        id: number;
        message: string;
        sent_at: string | null;
        handled: boolean;
    }[];
}>();

const field =
    'min-h-11 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:min-h-9';

setLayoutProps({
    breadcrumbs: [
        { title: 'Operations', href: attention().url },
        { title: 'People', href: index().url },
        { title: props.person.name, href: show(props.person.id).url },
    ],
});
</script>

<template>
    <Head :title="person.name" />

    <div class="mx-auto w-full max-w-4xl space-y-10 px-4 py-6 sm:px-6">
        <section>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <h1 class="font-display text-3xl">{{ person.name }}</h1>
                <Link
                    v-if="person.can_sign_in_as"
                    :href="signInAs(person.id).url"
                    method="post"
                    as="button"
                    :preserve-state="false"
                    class="inline-flex min-h-11 items-center rounded-md border bg-background px-3 text-sm font-medium select-none hover:bg-muted sm:min-h-9"
                    data-test="sign-in-as"
                >
                    Sign in as {{ person.name }}
                </Link>
            </div>
            <p class="mt-1 text-muted-foreground">
                {{ person.email }}
                {{ person.verified ? '' : '· not verified' }} · joined
                {{ stamp(person.joined_at) }}
                {{ person.two_factor ? '· two-step sign-in on' : '' }}
            </p>
        </section>

        <section class="space-y-3" data-test="person-plan">
            <h2 class="text-lg font-semibold">
                {{ person.plan }}
                <span
                    v-if="person.stripe"
                    class="text-sm font-normal text-muted-foreground"
                    >· Stripe customer</span
                >
            </h2>
            <p v-if="person.usage.unlimited" class="text-muted-foreground">
                An operator: use has no limit. This month so far:
                {{ usd(person.usage.used_usd) }}.
            </p>
            <template v-else>
                <p class="text-muted-foreground">
                    {{ person.usage.percent }}% of this month's use ·
                    {{ usd(person.usage.used_usd) }} of
                    {{ usd(person.usage.allowance_usd) }} · starts again on
                    {{ person.usage.resets_on }}
                </p>
                <div
                    class="h-2 max-w-md overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    :aria-valuenow="person.usage.percent"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-label="This month's use"
                >
                    <div
                        class="h-full rounded-full"
                        :class="
                            person.usage.percent >= 100
                                ? 'bg-destructive'
                                : 'bg-foreground'
                        "
                        :style="{ width: `${person.usage.percent}%` }"
                    />
                </div>
            </template>

            <!-- A plan given without payment, for a tester or a partner.
                 A bigger paid plan still counts. -->
            <Form
                v-bind="PersonPlanController.update.form(person.id)"
                class="flex flex-wrap items-end gap-3 pt-2"
                v-slot="{ errors, processing }"
                data-test="grant-plan"
            >
                <label class="grid gap-1.5 text-sm">
                    <span class="font-medium">Give a plan without payment</span>
                    <select
                        name="plan"
                        :value="person.granted ?? ''"
                        :class="field"
                    >
                        <option value="">None</option>
                        <option
                            v-for="plan in plans"
                            :key="plan.key"
                            :value="plan.key"
                        >
                            {{ plan.name }}
                        </option>
                    </select>
                </label>
                <label class="grid gap-1.5 text-sm">
                    <span class="font-medium">Until</span>
                    <input
                        type="date"
                        name="until"
                        :value="person.granted_until ?? ''"
                        :class="field"
                    />
                </label>
                <button
                    type="submit"
                    :disabled="processing"
                    class="inline-flex min-h-11 items-center rounded-md border bg-background px-3 text-sm font-medium select-none hover:bg-muted sm:min-h-9"
                >
                    Save
                </button>
                <p class="w-full text-xs text-muted-foreground">
                    Leave the date empty to give it for good.
                </p>
                <InputError :message="errors.plan ?? errors.until" />
            </Form>
        </section>

        <section class="space-y-2">
            <h2 class="text-lg font-semibold">Apps</h2>
            <p v-if="apps.length === 0" class="text-muted-foreground">
                No apps yet.
            </p>
            <ul v-else class="divide-y border-y">
                <li
                    v-for="app in apps"
                    :key="`${app.name}-${app.created_at}`"
                    class="flex min-h-11 items-center justify-between gap-4 py-2"
                >
                    <span class="min-w-0 truncate font-medium">{{
                        app.name
                    }}</span>
                    <span class="shrink-0 text-sm text-muted-foreground"
                        >{{ app.changes }}
                        {{ app.changes === 1 ? 'change' : 'changes' }} · made
                        {{ stamp(app.created_at) }}</span
                    >
                </li>
            </ul>
        </section>

        <section class="space-y-2">
            <h2 class="text-lg font-semibold">Messages to us</h2>
            <p v-if="messages.length === 0" class="text-muted-foreground">
                None.
            </p>
            <ul v-else class="divide-y border-y">
                <li
                    v-for="message in messages"
                    :key="message.id"
                    class="space-y-1 py-3"
                >
                    <p class="text-xs text-muted-foreground">
                        {{ stamp(message.sent_at) }} ·
                        {{ message.handled ? 'handled' : 'not handled' }}
                    </p>
                    <p class="whitespace-pre-line">{{ message.message }}</p>
                </li>
            </ul>
        </section>
    </div>
</template>
