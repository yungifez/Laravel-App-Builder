<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { stamp, usd } from '@/lib/operations';
import { attention } from '@/routes/operations';
import { index, show } from '@/routes/operations/people';

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
        stripe: boolean;
        usage: {
            percent: number;
            used_usd: number;
            allowance_usd: number;
            resets_on: string;
            unlimited: boolean;
        };
    };
    apps: { name: string; changes: number; created_at: string | null }[];
    messages: {
        id: number;
        message: string;
        sent_at: string | null;
        handled: boolean;
    }[];
}>();

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
            <h1 class="font-display text-3xl">{{ person.name }}</h1>
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
