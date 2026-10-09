<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { stamp } from '@/lib/operations';
import { attention } from '@/routes/operations';
import { index, update } from '@/routes/operations/developers';
import { show as person } from '@/routes/operations/people';
import type { Paginated } from '@/types';

// People who asked to answer owners' questions, the ones still waiting
// first. Approving lets them take questions; declining takes that away.
type Row = {
    id: number;
    person: string;
    email: string;
    user_id: number;
    about: string;
    link: string | null;
    status: 'waiting' | 'approved' | 'declined';
    answered: number;
    decided_by: string | null;
    applied_at: string | null;
};

defineProps<{ applications: Paginated<Row> }>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Operations', href: attention().url },
        { title: 'Developers', href: index().url },
    ],
});

function decide(application: Row, approved: boolean): void {
    router.put(
        update(application.id).url,
        { approved },
        { preserveScroll: true },
    );
}

function state(application: Row): string {
    const by = application.decided_by ? ` by ${application.decided_by}` : '';

    if (application.status === 'approved') {
        return `Approved${by} · ${application.answered} answered`;
    }

    return application.status === 'declined' ? `Declined${by}` : 'Waiting';
}

const button =
    'inline-flex min-h-11 items-center rounded-md border bg-background px-3 text-sm select-none hover:bg-muted sm:min-h-8';
</script>

<template>
    <Head title="Developers" />

    <div class="mx-auto w-full max-w-4xl space-y-6 px-4 py-6 sm:px-6">
        <p
            v-if="applications.data.length === 0"
            class="text-muted-foreground"
            data-test="no-developers"
        >
            Nobody has asked to answer questions yet.
        </p>

        <ul v-else class="divide-y border-y">
            <li
                v-for="application in applications.data"
                :key="application.id"
                class="space-y-2 py-4"
                data-test="developer-row"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <p class="text-sm">
                        <Link
                            :href="person(application.user_id).url"
                            class="font-medium text-primary hover:underline"
                            >{{ application.person }}</Link
                        >
                        <span class="text-muted-foreground">
                            {{ application.email }} ·
                            {{ stamp(application.applied_at) }} ·
                        </span>
                        <span
                            :class="
                                application.status === 'waiting'
                                    ? 'font-medium text-amber-600'
                                    : 'text-muted-foreground'
                            "
                            >{{ state(application) }}</span
                        >
                    </p>
                    <div class="flex gap-2">
                        <button
                            v-if="application.status !== 'approved'"
                            type="button"
                            :class="button"
                            data-test="developer-approve"
                            @click="decide(application, true)"
                        >
                            Approve
                        </button>
                        <button
                            v-if="application.status !== 'declined'"
                            type="button"
                            :class="button"
                            data-test="developer-decline"
                            @click="decide(application, false)"
                        >
                            {{
                                application.status === 'approved'
                                    ? 'Take access away'
                                    : 'Decline'
                            }}
                        </button>
                    </div>
                </div>
                <p class="whitespace-pre-line">{{ application.about }}</p>
                <a
                    v-if="application.link"
                    :href="application.link"
                    target="_blank"
                    rel="noopener noreferrer nofollow"
                    class="inline-block text-sm break-all text-primary hover:underline"
                    >{{ application.link }}</a
                >
            </li>
        </ul>

        <nav
            v-if="applications.last_page > 1"
            class="flex items-center justify-between text-sm"
        >
            <Link
                v-if="applications.prev_page_url"
                :href="applications.prev_page_url"
                class="min-h-11 content-center text-primary sm:min-h-9"
                >Newer</Link
            >
            <span class="text-muted-foreground"
                >Page {{ applications.current_page }} of
                {{ applications.last_page }}</span
            >
            <Link
                v-if="applications.next_page_url"
                :href="applications.next_page_url"
                class="min-h-11 content-center text-primary sm:min-h-9"
                >Older</Link
            >
        </nav>
    </div>
</template>
