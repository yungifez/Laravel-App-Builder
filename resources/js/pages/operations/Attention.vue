<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { duration, stamp, usd, words } from '@/lib/operations';
import { attention as attentionRoute } from '@/routes/operations';
import { index as projectsIndex } from '@/routes/projects';
import { index as changesIndex } from '@/routes/operations/changes';
import { index as developerReviewsIndex } from '@/routes/operations/developer-reviews';
import { index as developersIndex } from '@/routes/operations/developers';
import { index as messagesIndex } from '@/routes/operations/messages';
import { index as peopleIndex } from '@/routes/operations/people';
import type { Attention } from '@/types';

// Hosts bill in cents, in their own currency.
function money(cents: number | null, currency: string | null): string {
    return cents === null
        ? 'Unknown'
        : new Intl.NumberFormat(undefined, {
              style: 'currency',
              currency: currency ?? 'USD',
          }).format(cents / 100);
}

const props = defineProps<{
    attention: Attention;
    waitingQuestions: number;
    newMessages: number;
    waitingDevelopers: number;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Your apps', href: projectsIndex().url },
        { title: 'Operations', href: attentionRoute().url },
    ],
});

const windows = [1, 7, 30];

// Silent queues come first: nothing else moves while they are down.
const silentQueues = computed(() =>
    props.attention.workers.filter((queue) => queue.attention),
);

const allClear = computed(
    () => silentQueues.value.length === 0 && props.attention.items.length === 0,
);

const completeness = computed(
    () =>
        ({
            none: 'Nothing recorded',
            complete: 'Complete',
            partial: 'Partial: a lower bound',
        })[props.attention.spend.completeness],
);
</script>

<template>
    <Head title="Operations" />

    <div class="mx-auto w-full max-w-5xl space-y-10 px-4 py-6 sm:px-6">
        <nav
            class="flex flex-wrap items-center justify-between gap-3"
            aria-label="Window"
        >
            <div class="flex gap-1" data-test="window">
                <Link
                    v-for="days in windows"
                    :key="days"
                    :href="attentionRoute({ query: { days } }).url"
                    class="flex min-h-11 items-center rounded-md px-3 text-sm select-none sm:min-h-9"
                    :class="
                        days === attention.days
                            ? 'bg-muted font-medium'
                            : 'text-muted-foreground hover:bg-muted/60'
                    "
                    preserve-scroll
                >
                    {{ days === 1 ? '24 hours' : `${days} days` }}
                </Link>
            </div>
            <div class="flex flex-wrap gap-x-4">
                <Link
                    :href="peopleIndex().url"
                    class="flex min-h-11 items-center gap-1 text-sm font-medium text-foreground/80 select-none hover:text-foreground sm:min-h-9"
                    data-test="people"
                >
                    People <ChevronRight class="size-4" />
                </Link>
                <Link
                    :href="messagesIndex().url"
                    class="flex min-h-11 items-center gap-1 text-sm font-medium text-foreground/80 select-none hover:text-foreground sm:min-h-9"
                    data-test="messages"
                >
                    Messages
                    <span
                        v-if="newMessages > 0"
                        class="text-amber-600"
                        data-test="messages-new"
                        >· {{ newMessages }} new</span
                    >
                    <ChevronRight class="size-4" />
                </Link>
                <Link
                    :href="developerReviewsIndex().url"
                    class="flex min-h-11 items-center gap-1 text-sm font-medium text-foreground/80 select-none hover:text-foreground sm:min-h-9"
                    data-test="developer-questions"
                >
                    Questions for developers
                    <span
                        v-if="waitingQuestions > 0"
                        class="text-amber-600"
                        data-test="questions-waiting"
                        >· {{ waitingQuestions }} waiting</span
                    >
                    <ChevronRight class="size-4" />
                </Link>
                <Link
                    :href="developersIndex().url"
                    class="flex min-h-11 items-center gap-1 text-sm font-medium text-foreground/80 select-none hover:text-foreground sm:min-h-9"
                    data-test="developers"
                >
                    Developers
                    <span
                        v-if="waitingDevelopers > 0"
                        class="text-amber-600"
                        data-test="developers-waiting"
                        >· {{ waitingDevelopers }} waiting</span
                    >
                    <ChevronRight class="size-4" />
                </Link>
                <Link
                    :href="changesIndex().url"
                    class="flex min-h-11 items-center gap-1 text-sm font-medium text-foreground/80 select-none hover:text-foreground sm:min-h-9"
                    data-test="all-changes"
                >
                    All changes <ChevronRight class="size-4" />
                </Link>
            </div>
        </nav>

        <!-- The answer first: what is wrong right now. -->
        <section class="space-y-2" data-test="needs-attention">
            <h2 class="text-lg font-semibold">Needs attention</h2>
            <p
                v-if="allClear"
                class="text-muted-foreground"
                data-test="all-clear"
            >
                Nothing stuck, silent or overdue.
            </p>
            <ul v-else class="divide-y border-y">
                <li
                    v-for="queue in silentQueues"
                    :key="`queue-${queue.queue}`"
                    class="flex min-h-11 items-center justify-between gap-4 py-2"
                    data-test="attention-queue"
                >
                    <span class="min-w-0">
                        Queue <span class="font-mono">{{ queue.queue }}</span>
                        <span class="text-muted-foreground">
                            {{
                                !queue.heard_from
                                    ? '— no worker has ever reported'
                                    : queue.alive === 0
                                      ? '— no worker is answering'
                                      : `— oldest job waiting ${duration(queue.oldest_wait_seconds)}`
                            }}
                        </span>
                    </span>
                    <span
                        class="shrink-0 font-medium text-destructive tabular-nums"
                        >{{ queue.backlog ?? '?' }} waiting</span
                    >
                </li>
                <li
                    v-for="item in attention.items"
                    :key="item.key"
                    :data-test="`attention-${item.key}`"
                >
                    <details class="group">
                        <summary
                            class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-4 py-2 select-none"
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <ChevronRight
                                    class="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-90"
                                />
                                <span class="truncate">{{ item.title }}</span>
                            </span>
                            <span
                                class="shrink-0 font-medium text-destructive tabular-nums"
                                >{{ item.count }}</span
                            >
                        </summary>
                        <ul class="space-y-1 pb-3 pl-6 text-sm">
                            <li
                                v-for="(record, index) in item.records"
                                :key="index"
                                class="flex min-w-0 flex-wrap items-baseline justify-between gap-x-4"
                            >
                                <span class="min-w-0">
                                    <Link
                                        v-if="record.href"
                                        :href="record.href"
                                        class="font-medium underline-offset-4 hover:underline"
                                        >{{ record.label }}</Link
                                    >
                                    <span v-else class="font-medium">{{
                                        record.label
                                    }}</span>
                                    <span
                                        v-if="record.detail"
                                        class="ml-2 break-words text-muted-foreground"
                                        >{{ record.detail }}</span
                                    >
                                </span>
                                <span
                                    class="shrink-0 text-xs text-muted-foreground tabular-nums"
                                    >{{ stamp(record.at) }}</span
                                >
                            </li>
                            <li
                                v-if="item.count > item.records.length"
                                class="text-muted-foreground"
                            >
                                and {{ item.count - item.records.length }} more
                                <Link
                                    v-if="item.href"
                                    :href="item.href"
                                    class="ml-1 text-primary underline-offset-4 hover:underline"
                                    >see all</Link
                                >
                            </li>
                        </ul>
                    </details>
                </li>
            </ul>
        </section>

        <section class="space-y-2" data-test="queues">
            <h2 class="text-lg font-semibold">Queues</h2>
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr class="border-b">
                        <th class="py-2 font-normal">Queue</th>
                        <th class="py-2 text-right font-normal">Workers</th>
                        <th class="py-2 text-right font-normal">Waiting</th>
                        <th class="py-2 text-right font-normal">Oldest</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="queue in attention.workers"
                        :key="queue.queue"
                        :data-test="`queue-${queue.queue}`"
                    >
                        <td class="py-2 font-mono">{{ queue.queue }}</td>
                        <td
                            class="py-2 text-right tabular-nums"
                            :class="
                                queue.alive === 0
                                    ? 'font-medium text-destructive'
                                    : ''
                            "
                        >
                            {{
                                queue.heard_from
                                    ? `${queue.alive} of ${queue.workers.length}`
                                    : 'no heartbeat'
                            }}
                        </td>
                        <td class="py-2 text-right tabular-nums">
                            {{ queue.backlog ?? 'unknown' }}
                        </td>
                        <td class="py-2 text-right tabular-nums">
                            {{ duration(queue.oldest_wait_seconds) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="space-y-2" data-test="failures">
            <h2 class="text-lg font-semibold">
                Failures and stops
                <span class="font-normal text-muted-foreground"
                    >since {{ stamp(attention.since) }}</span
                >
            </h2>
            <p
                v-if="attention.failures.length === 0"
                class="text-muted-foreground"
            >
                None.
            </p>
            <table v-else class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr class="border-b">
                        <th class="py-2 font-normal">Stage</th>
                        <th class="py-2 font-normal">Reason</th>
                        <th class="py-2 text-right font-normal">Count</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="failure in attention.failures"
                        :key="`${failure.stage}-${failure.reason}`"
                        data-test="failure"
                    >
                        <td class="py-2">{{ words(failure.stage) }}</td>
                        <td class="py-2">{{ words(failure.reason) }}</td>
                        <td class="py-2 text-right tabular-nums">
                            <Link
                                v-if="failure.href"
                                :href="failure.href"
                                class="font-medium text-primary underline-offset-4 hover:underline"
                                >{{ failure.count }}</Link
                            >
                            <span v-else>{{ failure.count }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="text-sm text-muted-foreground">
                <Link
                    :href="
                        changesIndex({ query: { outcome: 'waiting_on_owner' } })
                            .url
                    "
                    class="underline-offset-4 hover:underline"
                    >{{ attention.waiting_on_owner }} waiting on their
                    owner</Link
                >
                — not failures; the owner has a question or a choice to make.
            </p>
        </section>

        <section class="space-y-2" data-test="edit-to-screen">
            <h2 class="text-lg font-semibold">Visual edit to screen</h2>
            <dl class="divide-y border-y text-sm">
                <div class="flex justify-between py-2">
                    <dt>Typical</dt>
                    <dd class="tabular-nums">
                        {{ duration(attention.rebuilds.median_seconds) }}
                    </dd>
                </div>
                <div class="flex justify-between py-2">
                    <dt>Slowest 1 in 10</dt>
                    <dd class="tabular-nums">
                        {{ duration(attention.rebuilds.p90_seconds) }}
                    </dd>
                </div>
                <div class="flex justify-between py-2">
                    <dt>Slowest</dt>
                    <dd class="tabular-nums">
                        {{ duration(attention.rebuilds.max_seconds) }}
                    </dd>
                </div>
                <div class="flex justify-between py-2">
                    <dt>Edits measured</dt>
                    <dd class="tabular-nums">
                        {{ attention.rebuilds.measured }} of
                        {{ attention.rebuilds.edits }}
                        <span
                            v-if="attention.rebuilds.slow > 0"
                            class="ml-1 text-destructive"
                            >({{ attention.rebuilds.slow }} slow)</span
                        >
                    </dd>
                </div>
            </dl>
            <p
                v-if="attention.rebuilds.not_seen > 0"
                class="text-sm text-muted-foreground"
            >
                {{ attention.rebuilds.not_seen }} edits were never shown by a
                rebuild: no preview was running, or every rebuild after them
                failed.
            </p>
        </section>

        <section class="space-y-2" data-test="spend">
            <h2 class="flex flex-wrap items-baseline justify-between gap-2">
                <span class="text-lg font-semibold">Model spend</span>
                <span
                    class="text-sm"
                    :class="
                        attention.spend.completeness === 'partial'
                            ? 'text-destructive'
                            : 'text-muted-foreground'
                    "
                    data-test="completeness"
                    >{{ completeness }}</span
                >
            </h2>
            <dl class="divide-y border-y text-sm">
                <div class="flex justify-between py-2 font-medium">
                    <dt>Recorded</dt>
                    <dd class="tabular-nums" data-test="spend-total">
                        {{ usd(attention.spend.total_usd) }}
                    </dd>
                </div>
                <div class="flex justify-between py-2">
                    <dt>Reported by the provider</dt>
                    <dd class="tabular-nums">
                        {{ usd(attention.spend.reported_usd) }}
                    </dd>
                </div>
                <div class="flex justify-between py-2">
                    <dt>Estimated from our prices</dt>
                    <dd class="tabular-nums">
                        {{ usd(attention.spend.estimated_usd) }}
                    </dd>
                </div>
                <div class="flex justify-between py-2">
                    <dt>Calls</dt>
                    <dd class="tabular-nums">{{ attention.spend.calls }}</dd>
                </div>
                <div
                    class="flex justify-between py-2"
                    :class="
                        attention.spend.unpriced_calls > 0
                            ? 'text-destructive'
                            : ''
                    "
                >
                    <dt>Calls with no known cost</dt>
                    <dd class="tabular-nums" data-test="unpriced">
                        {{ attention.spend.unpriced_calls }}
                    </dd>
                </div>
                <div class="flex justify-between py-2">
                    <dt>Decision calls</dt>
                    <dd class="tabular-nums">
                        {{ attention.spend.decision_calls }}
                    </dd>
                </div>
                <div
                    v-if="attention.spend.unmetered_decision_calls > 0"
                    class="flex justify-between py-2 text-destructive"
                >
                    <dt>Requests decided before metering</dt>
                    <dd class="tabular-nums">
                        {{ attention.spend.unmetered_decision_calls }}
                    </dd>
                </div>
                <div class="flex justify-between py-2">
                    <dt>Setting apps up</dt>
                    <dd class="tabular-nums">
                        {{ usd(attention.spend.setup_usd) }}
                        <span class="text-muted-foreground"
                            >({{ attention.spend.setup_calls }} calls)</span
                        >
                    </dd>
                </div>
                <div class="flex justify-between py-2 text-muted-foreground">
                    <dt>Boxes and hosting</dt>
                    <dd>not recorded</dd>
                </div>
            </dl>
            <p
                v-if="attention.spend.undated_setup_calls > 0"
                class="text-sm text-muted-foreground"
            >
                {{ attention.spend.undated_setup_calls }} older setup calls have
                no date and are left out.
            </p>
        </section>

        <section
            v-for="host in attention.hosting"
            :key="host.host"
            class="space-y-2"
            :data-test="`hosting-${host.host}`"
        >
            <h2 class="flex flex-wrap items-baseline justify-between gap-2">
                <span class="text-lg font-semibold"
                    >Hosting spend ({{ words(host.host) }})</span
                >
                <span class="text-sm text-muted-foreground"
                    >This billing period, from the host</span
                >
            </h2>
            <p v-if="host.error" class="text-sm text-destructive">
                The host did not answer, so its spend is unknown.
            </p>
            <dl v-else class="divide-y border-y text-sm">
                <div class="flex justify-between py-2 font-medium">
                    <dt>Total</dt>
                    <dd class="tabular-nums" data-test="hosting-total">
                        {{ money(host.total_cents, host.currency) }}
                    </dd>
                </div>
                <div
                    v-for="app in host.apps"
                    :key="app.name"
                    class="flex justify-between gap-4 py-2"
                >
                    <dt class="min-w-0 truncate">
                        {{ app.name }}
                        <span
                            v-if="app.project_id === null"
                            class="text-muted-foreground"
                            >(not one of our projects)</span
                        >
                    </dt>
                    <dd class="tabular-nums">
                        {{ money(app.cents, host.currency) }}
                    </dd>
                </div>
            </dl>
        </section>
    </div>
</template>
