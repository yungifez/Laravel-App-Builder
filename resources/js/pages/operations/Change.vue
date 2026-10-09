<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed, watch } from 'vue';
import { duration, short, stamp, usd, words } from '@/lib/operations';
import { attention } from '@/routes/operations';
import { index, show } from '@/routes/operations/changes';
import type { ChangeHistory, TimeSplit } from '@/types';

const props = defineProps<{ history: ChangeHistory }>();

watch(
    () => props.history.change.id,
    (id) =>
        setLayoutProps({
            breadcrumbs: [
                { title: 'Operations', href: attention().url },
                { title: 'Changes', href: index().url },
                { title: `Change #${id}`, href: show(id).url },
            ],
        }),
    { immediate: true },
);

const change = computed(() => props.history.change);
const milestones = computed(() => props.history.milestones);

// Separate facts, each yes, no or not measured: never folded into one.
const facts = computed(() => [
    { label: 'Completed', value: milestones.value.completed },
    {
        label: 'Checks',
        value: milestones.value.verification,
    },
    { label: 'Kept', value: milestones.value.kept },
    { label: 'Undone', value: milestones.value.reverted },
    { label: 'Pushed', value: milestones.value.pushed },
    { label: 'Published', value: milestones.value.published },
    { label: 'Healthy', value: milestones.value.healthy },
]);

function factText(value: boolean | string | null): string {
    if (value === null) {
        return 'not measured';
    }

    if (typeof value === 'string') {
        return value;
    }

    return value ? 'yes' : 'no';
}

function factClass(value: boolean | string | null): string {
    if (value === null || value === false) {
        return 'text-muted-foreground';
    }

    return value === 'failed' || value === 'errored'
        ? 'text-destructive'
        : 'font-medium';
}

const kinds = [
    { key: 'queue', label: 'Waiting for a worker', color: 'bg-chart-5' },
    { key: 'machine', label: 'Working', color: 'bg-chart-2' },
    { key: 'owner', label: 'Waiting on the owner', color: 'bg-chart-1' },
] as const;

const totalTime = computed(
    () =>
        props.history.time.queue_seconds +
        props.history.time.machine_seconds +
        props.history.time.owner_seconds,
);

function part(split: TimeSplit, key: keyof TimeSplit): number {
    return split[key] ?? 0;
}
</script>

<template>
    <Head :title="`Change #${change.id}`" />

    <div class="mx-auto w-full max-w-5xl space-y-10 px-4 py-6 sm:px-6">
        <!-- The answer first: how the change ended up. -->
        <section class="space-y-4" data-test="summary">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-semibold" data-test="outcome">
                    {{ change.outcome_label }}
                </h2>
                <span class="text-sm text-muted-foreground">
                    {{ change.project.name }} · {{ change.owner }} ·
                    {{ stamp(change.created_at) }}
                </span>
            </div>

            <dl
                class="grid grid-cols-2 gap-x-6 border-y py-2 text-sm sm:grid-cols-4 lg:grid-cols-7"
                data-test="milestones"
            >
                <div
                    v-for="fact in facts"
                    :key="fact.label"
                    class="flex justify-between gap-2 py-1 lg:flex-col"
                >
                    <dt class="text-muted-foreground">{{ fact.label }}</dt>
                    <dd :class="factClass(fact.value)">
                        {{ factText(fact.value) }}
                    </dd>
                </div>
            </dl>

            <div v-if="totalTime > 0" class="space-y-2" data-test="time">
                <div class="flex h-3 w-full overflow-hidden rounded-sm">
                    <div
                        v-for="kind in kinds"
                        :key="kind.key"
                        :class="kind.color"
                        :style="{
                            width: `${(history.time[`${kind.key}_seconds`] / totalTime) * 100}%`,
                        }"
                    />
                </div>
                <dl class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    <div
                        v-for="kind in kinds"
                        :key="kind.key"
                        class="flex items-center gap-2"
                    >
                        <span
                            class="size-2.5 rounded-sm"
                            :class="kind.color"
                            aria-hidden="true"
                        />
                        <dt class="text-muted-foreground">{{ kind.label }}</dt>
                        <dd class="tabular-nums">
                            {{ duration(history.time[`${kind.key}_seconds`]) }}
                        </dd>
                    </div>
                    <div
                        v-if="history.time.until_kept_seconds !== null"
                        class="flex items-center gap-2"
                    >
                        <dt class="text-muted-foreground">Then kept after</dt>
                        <dd class="tabular-nums">
                            {{ duration(history.time.until_kept_seconds) }}
                        </dd>
                    </div>
                </dl>
            </div>

            <p class="text-sm break-words whitespace-pre-line">
                <span class="text-muted-foreground">Asked: </span
                >{{ change.request }}
            </p>
            <p v-if="change.error" class="text-sm break-words text-destructive">
                {{ change.error }}
            </p>

            <p
                v-if="
                    history.related.parent ||
                    history.related.retry_of ||
                    history.related.retries.length ||
                    history.related.follow_ups.length
                "
                class="flex flex-wrap gap-x-4 gap-y-1 text-sm"
                data-test="related"
            >
                <Link
                    v-if="history.related.parent"
                    :href="show(history.related.parent).url"
                    class="text-primary"
                    >Follows up on #{{ history.related.parent.slice(-8) }}</Link
                >
                <Link
                    v-if="history.related.retry_of"
                    :href="show(history.related.retry_of).url"
                    class="text-primary"
                    >Retry of #{{ history.related.retry_of.slice(-8) }}</Link
                >
                <Link
                    v-for="id in history.related.retries"
                    :key="`retry-${id}`"
                    :href="show(id).url"
                    class="text-primary"
                    >Retried as #{{ id.slice(-8) }}</Link
                >
                <Link
                    v-for="id in history.related.follow_ups"
                    :key="`follow-${id}`"
                    :href="show(id).url"
                    class="text-primary"
                    >Followed up by #{{ id.slice(-8) }}</Link
                >
            </p>
        </section>

        <section
            v-if="history.decisions.length"
            class="space-y-2"
            data-test="decisions"
        >
            <h2 class="text-lg font-semibold">Decisions before building</h2>
            <ul class="divide-y border-y text-sm">
                <li
                    v-for="decision in history.decisions"
                    :key="decision.name"
                    class="flex flex-wrap justify-between gap-x-4 py-2"
                >
                    <span
                        >{{ words(decision.name) }}:
                        <span class="font-medium">{{ decision.choice }}</span>
                        <span class="text-muted-foreground">
                            ({{ Math.round(decision.confidence * 100) }}% sure{{
                                decision.acted ? ', acted on' : ', not used'
                            }})</span
                        ></span
                    >
                    <span class="text-muted-foreground tabular-nums"
                        >{{ decision.latency_ms }} ms</span
                    >
                </li>
            </ul>
        </section>

        <section
            v-for="run in history.runs"
            :key="run.id"
            class="space-y-4"
            :data-test="`run-${run.id}`"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-semibold">
                    Attempt {{ run.attempt }}
                    <span class="font-normal text-muted-foreground"
                        >· run {{ run.id }} · {{ run.driver }}</span
                    >
                </h2>
                <span
                    class="text-sm"
                    :class="
                        run.status === 'failed'
                            ? 'font-medium text-destructive'
                            : ''
                    "
                    >{{ words(run.status)
                    }}<template v-if="run.stop_reason">
                        · {{ words(run.stop_reason) }}</template
                    ></span
                >
            </div>
            <p v-if="run.error" class="text-sm break-words text-destructive">
                {{ run.error }}
            </p>

            <!-- Where the time went, state by state. -->
            <table class="w-full text-sm" data-test="segments">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr class="border-b">
                        <th class="py-2 font-normal">State</th>
                        <th class="py-2 font-normal">From</th>
                        <th class="hidden py-2 font-normal sm:table-cell">
                            Split
                        </th>
                        <th class="py-2 text-right font-normal">Took</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="(segment, index) in run.segments" :key="index">
                        <td class="py-2">{{ words(segment.status) }}</td>
                        <td class="py-2 text-muted-foreground tabular-nums">
                            {{ stamp(segment.started_at) }}
                        </td>
                        <td class="hidden py-2 sm:table-cell">
                            <div
                                v-if="segment.seconds > 0"
                                class="flex h-2 w-32 overflow-hidden rounded-sm bg-muted"
                            >
                                <div
                                    v-for="kind in kinds"
                                    :key="kind.key"
                                    :class="kind.color"
                                    :style="{
                                        width: `${(part(segment.split, kind.key) / segment.seconds) * 100}%`,
                                    }"
                                />
                            </div>
                        </td>
                        <td class="py-2 text-right tabular-nums">
                            {{ duration(segment.seconds) }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <div v-if="run.model_calls.length" class="space-y-2">
                <h3 class="font-medium">Model calls</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-test="model-calls">
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr class="border-b">
                                <th class="py-2 font-normal">Role</th>
                                <th class="py-2 font-normal">Model</th>
                                <th class="py-2 text-right font-normal">
                                    Tokens in / out
                                </th>
                                <th class="py-2 text-right font-normal">
                                    Cost
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="(call, index) in run.model_calls"
                                :key="index"
                            >
                                <td class="py-2">
                                    {{ call.role ?? '—' }}
                                    <span
                                        v-if="
                                            call.status &&
                                            call.status !== 'completed'
                                        "
                                        class="text-xs text-destructive"
                                        >{{ words(call.status) }}</span
                                    >
                                </td>
                                <td class="py-2 break-all">
                                    {{ call.provider ?? '?' }} /
                                    {{ call.model ?? '?' }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{ call.input_tokens.toLocaleString() }} /
                                    {{ call.output_tokens.toLocaleString() }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    <template v-if="call.cost_usd !== null"
                                        >{{ usd(call.cost_usd) }}
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >{{ call.cost_source }}</span
                                        ></template
                                    >
                                    <span v-else class="text-destructive"
                                        >unknown</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div
                v-for="verification in run.verifications"
                :key="verification.id"
                class="space-y-2"
                data-test="verification"
            >
                <h3 class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="font-medium"
                        >Checks: {{ verification.status }}</span
                    >
                    <span class="text-sm text-muted-foreground tabular-nums"
                        >waited {{ duration(verification.wait_seconds) }}, ran
                        {{ duration(verification.seconds) }}</span
                    >
                </h3>
                <p class="text-sm text-muted-foreground">
                    {{ verification.meaning }}
                </p>
                <ul class="divide-y border-y text-sm">
                    <li
                        v-for="result in verification.results"
                        :key="result.name"
                        class="py-2"
                    >
                        <div class="flex justify-between gap-4">
                            <span>{{ result.name }}</span>
                            <span
                                class="tabular-nums"
                                :class="
                                    result.outcome === 'passed'
                                        ? 'text-muted-foreground'
                                        : 'font-medium text-destructive'
                                "
                                >{{ result.outcome }} ·
                                {{ duration(result.duration_ms / 1000) }}</span
                            >
                        </div>
                        <pre
                            v-if="result.output"
                            class="mt-1 max-h-48 overflow-auto rounded-sm bg-muted p-2 text-xs whitespace-pre-wrap"
                            >{{ result.output }}</pre>
                    </li>
                </ul>
            </div>

            <div v-if="run.failed_commands.length" class="space-y-2">
                <h3 class="font-medium">Commands that failed</h3>
                <ul class="divide-y border-y text-sm">
                    <li
                        v-for="(command, index) in run.failed_commands"
                        :key="index"
                        class="space-y-1 py-2"
                    >
                        <div class="flex justify-between gap-4">
                            <code class="min-w-0 text-xs break-all">{{
                                command.command
                            }}</code>
                            <span
                                class="shrink-0 text-destructive tabular-nums"
                                >{{
                                    command.timed_out
                                        ? 'timed out'
                                        : `exit ${command.exit_code}`
                                }}</span
                            >
                        </div>
                        <pre
                            v-if="command.output"
                            class="max-h-48 overflow-auto rounded-sm bg-muted p-2 text-xs whitespace-pre-wrap"
                            >{{ command.output }}</pre>
                    </li>
                </ul>
            </div>

            <details class="group text-sm">
                <summary
                    class="flex min-h-11 cursor-pointer list-none items-center gap-2 select-none"
                >
                    <ChevronRight
                        class="size-4 text-muted-foreground transition-transform group-open:rotate-90"
                    />
                    Everything the run logged
                    <span class="text-muted-foreground"
                        >({{ run.events.length }} events<template
                            v-if="run.operations.length"
                            >,
                            {{
                                run.operations.reduce(
                                    (sum, operation) => sum + operation.count,
                                    0,
                                )
                            }}
                            tool operations</template
                        >)</span
                    >
                </summary>
                <p
                    v-if="run.operations.length"
                    class="py-2 text-muted-foreground"
                >
                    <span
                        v-for="operation in run.operations"
                        :key="operation.tool"
                        class="mr-3 inline-block"
                        >{{ operation.tool }} × {{ operation.count
                        }}<span
                            v-if="operation.failed"
                            class="text-destructive"
                        >
                            ({{ operation.failed }} refused or failed)</span
                        ></span
                    >
                </p>
                <ol class="divide-y border-y">
                    <li
                        v-for="event in run.events"
                        :key="event.sequence"
                        class="flex gap-4 py-1.5"
                    >
                        <span
                            class="w-28 shrink-0 text-xs text-muted-foreground tabular-nums"
                            >{{ stamp(event.at) }}</span
                        >
                        <span class="min-w-0 break-words">{{
                            event.summary
                        }}</span>
                    </li>
                </ol>
                <p class="pt-2 text-xs text-muted-foreground">
                    Settings version
                    <span class="font-mono">{{
                        short(run.config_version)
                    }}</span>
                </p>
            </details>
        </section>

        <section
            v-if="history.verifications.length"
            class="space-y-2"
            data-test="other-verifications"
        >
            <h2 class="text-lg font-semibold">Checks asked for later</h2>
            <ul class="divide-y border-y text-sm">
                <li
                    v-for="verification in history.verifications"
                    :key="verification.id"
                    class="flex justify-between gap-4 py-2"
                >
                    <span>{{ verification.status }}</span>
                    <span class="text-muted-foreground tabular-nums">{{
                        stamp(verification.finished_at)
                    }}</span>
                </li>
            </ul>
        </section>

        <section
            v-if="history.previews.length"
            class="space-y-2"
            data-test="previews"
        >
            <h2 class="text-lg font-semibold">Previews</h2>
            <ul class="divide-y border-y text-sm">
                <li
                    v-for="preview in history.previews"
                    :key="preview.id"
                    class="space-y-1 py-2"
                >
                    <div class="flex justify-between gap-4">
                        <span
                            :class="
                                preview.status === 'failed'
                                    ? 'font-medium text-destructive'
                                    : ''
                            "
                            >Preview {{ preview.id }}:
                            {{ preview.status }}</span
                        >
                        <span class="text-muted-foreground tabular-nums"
                            >started in
                            {{ duration(preview.start_seconds) }}</span
                        >
                    </div>
                    <p
                        v-if="preview.error"
                        class="break-words text-destructive"
                    >
                        {{ preview.error }}
                    </p>
                </li>
            </ul>
        </section>

        <section class="space-y-2" data-test="identity">
            <h2 class="text-lg font-semibold">Code</h2>
            <dl class="divide-y border-y text-sm">
                <div class="flex justify-between gap-4 py-2">
                    <dt>Built on</dt>
                    <dd class="font-mono">{{ short(change.base_revision) }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-2">
                    <dt>Change</dt>
                    <dd class="font-mono">
                        <template v-if="change.patch"
                            >{{ short(change.patch.sha256) }}
                            <span class="font-sans text-muted-foreground"
                                >({{ change.patch.files }} files)</span
                            ></template
                        >
                        <template v-else>—</template>
                    </dd>
                </div>
                <div class="flex justify-between gap-4 py-2">
                    <dt>Kept as</dt>
                    <dd>
                        <span class="font-mono">{{
                            short(change.commit_sha)
                        }}</span>
                        <span
                            v-if="change.accepted_at"
                            class="ml-2 text-muted-foreground tabular-nums"
                            >{{ stamp(change.accepted_at) }}</span
                        >
                    </dd>
                </div>
                <div
                    v-if="change.revert_sha"
                    class="flex justify-between gap-4 py-2"
                >
                    <dt>Undone by</dt>
                    <dd>
                        <span class="font-mono">{{
                            short(change.revert_sha)
                        }}</span>
                        <span class="ml-2 text-muted-foreground tabular-nums">{{
                            stamp(change.reverted_at)
                        }}</span>
                    </dd>
                </div>
            </dl>
        </section>

        <section class="space-y-2" data-test="deployments">
            <h2 class="text-lg font-semibold">Publishes that contain it</h2>
            <p
                v-if="history.deployments.length === 0"
                class="text-muted-foreground"
            >
                None recorded.
            </p>
            <ul v-else class="divide-y border-y text-sm">
                <li
                    v-for="deployment in history.deployments"
                    :key="deployment.id"
                    class="space-y-1 py-2"
                >
                    <div class="flex flex-wrap justify-between gap-x-4">
                        <span
                            :class="
                                deployment.status === 'failed' ||
                                deployment.status === 'needs_attention'
                                    ? 'font-medium text-destructive'
                                    : ''
                            "
                            >{{ words(deployment.status) }}
                            <span class="font-mono text-muted-foreground">{{
                                short(deployment.commit_sha)
                            }}</span></span
                        >
                        <span class="text-muted-foreground tabular-nums"
                            >pushed {{ stamp(deployment.pushed_at) }}</span
                        >
                    </div>
                    <p
                        v-if="deployment.error"
                        class="break-words text-destructive"
                    >
                        {{ deployment.error }}
                    </p>
                </li>
            </ul>
        </section>
    </div>
</template>
