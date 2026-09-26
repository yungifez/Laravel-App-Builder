<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import type { ProjectCommit, ProjectTelemetry } from '@/types';

defineProps<{
    sourcePath: string;
    telemetry: ProjectTelemetry;
    history: ProjectCommit[];
}>();

function rate(part: number, whole: number): string {
    return whole === 0 ? '–' : `${Math.round((part / whole) * 100)}%`;
}

function dollars(amount: number | null): string {
    return amount === null ? '–' : `$${amount.toFixed(2)}`;
}
</script>

<template>
    <div class="space-y-6 text-sm" data-test="project-details">
        <p class="text-muted-foreground">
            Imported from
            <span class="font-mono break-all">{{ sourcePath }}</span>
        </p>

        <section
            v-if="telemetry.requests > 0 || telemetry.visual_edits > 0"
            class="space-y-4"
            data-test="project-telemetry"
        >
            <Heading
                variant="small"
                title="How changes went"
                :description="`${telemetry.requests} requests, ${telemetry.accepted} kept, ${telemetry.reverted} undone`"
            />

            <dl class="grid grid-cols-2 gap-x-4 gap-y-3">
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Cost per kept change
                    </dt>
                    <dd class="font-medium tabular-nums">
                        {{ dollars(telemetry.cost_per_accepted_change_usd) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Passed on the first attempt
                    </dt>
                    <dd class="font-medium tabular-nums">
                        {{
                            rate(
                                telemetry.first_attempt_passed,
                                telemetry.runs_verified,
                            )
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Changed parts not asked about
                    </dt>
                    <dd class="font-medium tabular-nums">
                        {{
                            rate(
                                telemetry.with_unexpected_changes,
                                telemetry.reviewed,
                            )
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Repairs before keeping
                    </dt>
                    <dd class="font-medium tabular-nums">
                        {{ telemetry.repairs_before_acceptance ?? '–' }}
                    </dd>
                </div>
                <div data-test="interventions">
                    <dt class="text-xs text-muted-foreground">
                        Times you stepped in, per kept change
                    </dt>
                    <dd class="font-medium tabular-nums">
                        {{ telemetry.interventions_per_accepted_change ?? '–' }}
                    </dd>
                </div>
            </dl>

            <p class="text-xs text-muted-foreground">
                You adjusted a plan
                {{ telemetry.interventions.adjustments }} times, stopped
                {{ telemetry.interventions.stops }}, asked again
                {{ telemetry.interventions.retries }} and undid
                {{ telemetry.interventions.undos }}.
            </p>

            <p class="text-xs text-muted-foreground">
                {{ dollars(telemetry.cost_usd) }} in total over
                {{ telemetry.input_tokens + telemetry.output_tokens }}
                tokens.
                <template v-if="telemetry.unpriced_calls > 0">
                    {{ telemetry.unpriced_calls }} model calls have no price and
                    are not in the cost.
                </template>
                {{ telemetry.visual_edits }} changes to how it looks were made
                without a model.
                <template v-if="telemetry.setup_cost_usd > 0">
                    Reading your app to describe it cost
                    {{ dollars(telemetry.setup_cost_usd) }}.
                </template>
            </p>
        </section>

        <section
            v-if="history.length > 0"
            class="space-y-4"
            data-test="project-history"
        >
            <Heading
                variant="small"
                title="Commits"
                description="Each kept change is one commit in the project's repository"
            />

            <ol class="divide-y">
                <li v-for="commit in history" :key="commit.sha" class="py-2">
                    <span class="block break-words">{{ commit.subject }}</span>
                    <span class="block font-mono text-xs text-muted-foreground"
                        >{{ commit.sha.slice(0, 7) }} ·
                        {{
                            new Date(commit.committed_at).toLocaleString()
                        }}</span
                    >
                </li>
            </ol>
        </section>
    </div>
</template>
