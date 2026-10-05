<script setup lang="ts">
import { computed } from 'vue';
import type { ProjectCommit, ProjectTelemetry } from '@/types';

const props = defineProps<{
    sourcePath: string | null;
    telemetry: ProjectTelemetry;
    history: ProjectCommit[];
}>();

// A share is never shown without what it is a share of.
function share(part: number, whole: number) {
    return whole === 0
        ? null
        : {
              count: `${part} of ${whole}`,
              percent: `${Math.round((part / whole) * 100)}%`,
          };
}

function dollars(amount: number | null): string {
    return amount === null ? '–' : `$${amount.toFixed(2)}`;
}

function day(at: string): string {
    return new Date(at).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

// The answer first: what was asked, what stayed, what it cost.
const headline = computed(() => [
    { label: 'Asked', value: String(props.telemetry.requests) },
    { label: 'Kept', value: String(props.telemetry.accepted) },
    { label: 'Undone', value: String(props.telemetry.reverted) },
    { label: 'Spent', value: dollars(props.telemetry.cost_usd) },
]);

const firstTry = computed(() =>
    share(props.telemetry.first_attempt_passed, props.telemetry.runs_verified),
);
const unasked = computed(() =>
    share(props.telemetry.with_unexpected_changes, props.telemetry.reviewed),
);
const notesBehind = computed(() =>
    share(props.telemetry.with_notes_behind, props.telemetry.reviewed),
);

const actions = computed(() => {
    const done = props.telemetry.owner_actions;

    return `${done.adjustments} adjusted · ${done.stops} stopped · ${done.retries} asked again · ${done.undos} undone`;
});
</script>

<template>
    <div class="space-y-8 text-sm" data-test="project-details">
        <section
            v-if="telemetry.requests > 0 || telemetry.visual_edits > 0"
            class="space-y-6"
            data-test="project-telemetry"
        >
            <dl class="grid grid-cols-4 divide-x border-y">
                <div
                    v-for="item in headline"
                    :key="item.label"
                    class="px-3 py-3 first:pl-0"
                >
                    <dt class="text-xs text-muted-foreground">
                        {{ item.label }}
                    </dt>
                    <dd class="mt-1 text-xl font-semibold tabular-nums">
                        {{ item.value }}
                    </dd>
                </div>
            </dl>

            <dl class="divide-y">
                <div class="flex items-baseline justify-between gap-4 py-2">
                    <dt class="text-muted-foreground">Cost per kept change</dt>
                    <dd class="tabular-nums">
                        {{ dollars(telemetry.cost_per_accepted_change_usd) }}
                    </dd>
                </div>
                <div class="py-2">
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-muted-foreground">
                            Passed on the first try
                        </dt>
                        <dd class="tabular-nums">
                            {{ firstTry?.count ?? '–' }}
                            <span
                                v-if="firstTry"
                                class="ml-1.5 text-muted-foreground"
                                >{{ firstTry.percent }}</span
                            >
                        </dd>
                    </div>
                    <dd
                        v-if="telemetry.first_attempt_unverified > 0"
                        class="mt-0.5 text-xs text-muted-foreground"
                        data-test="first-attempt-unverified"
                    >
                        {{ telemetry.first_attempt_unverified }} more passed
                        with nothing to test the change
                    </dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 py-2">
                    <dt class="text-muted-foreground">
                        Changed parts not asked about
                    </dt>
                    <dd class="tabular-nums">
                        {{ unasked?.count ?? '–' }}
                        <span
                            v-if="unasked"
                            class="ml-1.5 text-muted-foreground"
                            >{{ unasked.percent }}</span
                        >
                    </dd>
                </div>
                <div
                    class="flex items-baseline justify-between gap-4 py-2"
                    data-test="notes-behind"
                >
                    <dt class="text-muted-foreground">
                        Changes that left notes out of date
                    </dt>
                    <dd class="tabular-nums">
                        {{ notesBehind?.count ?? '–' }}
                        <span
                            v-if="notesBehind"
                            class="ml-1.5 text-muted-foreground"
                            >{{ notesBehind.percent }}</span
                        >
                    </dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 py-2">
                    <dt class="text-muted-foreground">
                        Repairs before keeping
                    </dt>
                    <dd class="tabular-nums">
                        {{ telemetry.repairs_before_acceptance ?? '–' }}
                    </dd>
                </div>
                <div class="py-2" data-test="owner-actions">
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-muted-foreground">
                            Times you stepped in, per kept change
                        </dt>
                        <dd class="tabular-nums">
                            {{
                                telemetry.owner_actions_per_accepted_change ??
                                '–'
                            }}
                        </dd>
                    </div>
                    <dd class="mt-0.5 text-xs text-muted-foreground">
                        {{ actions }}
                    </dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 py-2">
                    <dt class="text-muted-foreground">
                        Changes to how it looks
                    </dt>
                    <dd class="tabular-nums">{{ telemetry.visual_edits }}</dd>
                </div>
                <div
                    v-if="telemetry.setup_cost_usd > 0"
                    class="flex items-baseline justify-between gap-4 py-2"
                >
                    <dt class="text-muted-foreground">Reading your app</dt>
                    <dd class="tabular-nums">
                        {{ dollars(telemetry.setup_cost_usd) }}
                    </dd>
                </div>
                <div
                    v-if="sourcePath"
                    class="flex items-baseline justify-between gap-4 py-2"
                >
                    <dt class="shrink-0 text-muted-foreground">
                        Imported from
                    </dt>
                    <dd
                        class="min-w-0 truncate font-mono text-xs"
                        :title="sourcePath"
                    >
                        {{ sourcePath }}
                    </dd>
                </div>
            </dl>
        </section>

        <p
            v-else-if="sourcePath"
            class="flex items-baseline justify-between gap-4"
        >
            <span class="shrink-0 text-muted-foreground">Imported from</span>
            <span
                class="min-w-0 truncate font-mono text-xs"
                :title="sourcePath"
            >
                {{ sourcePath }}
            </span>
        </p>

        <section
            v-if="history.length > 0"
            class="space-y-2"
            data-test="project-history"
        >
            <h3 class="text-xs text-muted-foreground">
                Kept changes · one commit each
            </h3>

            <ol class="divide-y border-t">
                <li
                    v-for="commit in history"
                    :key="commit.sha"
                    class="flex items-baseline justify-between gap-4 py-2"
                >
                    <span class="min-w-0 break-words">{{
                        commit.subject
                    }}</span>
                    <span
                        class="shrink-0 text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                        :title="new Date(commit.committed_at).toLocaleString()"
                        >{{ day(commit.committed_at) }} ·
                        <span class="font-mono">{{
                            commit.sha.slice(0, 7)
                        }}</span></span
                    >
                </li>
            </ol>
        </section>
    </div>
</template>
