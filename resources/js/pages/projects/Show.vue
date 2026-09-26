<script setup lang="ts">
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { watch } from 'vue';
import FeatureRequestController from '@/actions/App/Http/Controllers/FeatureRequestController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { show as showFeatureRequest } from '@/routes/feature-requests';
import { index, show } from '@/routes/projects';
import { Badge } from '@/components/ui/badge';
import type {
    FeatureRequestSummary,
    ProjectCommit,
    ProjectSummary,
    ProjectTelemetry,
} from '@/types';

const props = defineProps<{
    project: ProjectSummary;
    featureRequests: FeatureRequestSummary[];
    history: ProjectCommit[];
    telemetry: ProjectTelemetry;
}>();

function rate(part: number, whole: number): string {
    return whole === 0 ? '–' : `${Math.round((part / whole) * 100)}%`;
}

function dollars(amount: number | null): string {
    return amount === null ? '–' : `$${amount.toFixed(2)}`;
}

watch(
    () => props.project,
    (project) =>
        setLayoutProps({
            breadcrumbs: [
                { title: 'Projects', href: index() },
                { title: project.name, href: show(project.id) },
            ],
        }),
    { immediate: true },
);
</script>

<template>
    <Head :title="props.project.name" />

    <div class="flex h-full flex-1 flex-col gap-8 p-4">
        <Heading
            :title="project.name"
            :description="`Source: ${project.source_path}`"
        />

        <section class="max-w-2xl space-y-6">
            <Heading
                variant="small"
                title="Request a feature"
                description="Describe what the application should do"
            />

            <Form
                v-bind="FeatureRequestController.store.form(project.id)"
                class="space-y-4"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="prompt">Request</Label>
                    <textarea
                        id="prompt"
                        name="prompt"
                        rows="3"
                        required
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                        placeholder="Let team owners and admins invite people to their team by email."
                    />
                    <InputError :message="errors.prompt" />
                </div>

                <Button
                    :disabled="processing"
                    data-test="request-feature-button"
                >
                    Request feature
                </Button>
            </Form>
        </section>

        <section class="max-w-2xl space-y-4">
            <Heading variant="small" title="Requests" />

            <p
                v-if="featureRequests.length === 0"
                class="text-sm text-muted-foreground"
            >
                No features have been requested yet.
            </p>

            <ul v-else class="divide-y rounded-lg border">
                <li v-for="request in featureRequests" :key="request.id">
                    <Link
                        :href="showFeatureRequest(request.id)"
                        class="flex items-center justify-between gap-4 p-4 hover:bg-muted/50"
                    >
                        <span class="text-sm">{{ request.prompt }}</span>
                        <span class="flex shrink-0 items-center gap-2">
                            <Badge v-if="request.accepted" variant="outline"
                                >Accepted</Badge
                            >
                            <StatusBadge :status="request.status" />
                        </span>
                    </Link>
                </li>
            </ul>
        </section>

        <section
            v-if="telemetry.requests > 0"
            class="max-w-2xl space-y-4"
            data-test="project-telemetry"
        >
            <Heading
                variant="small"
                title="How changes went"
                :description="`${telemetry.requests} requests, ${telemetry.accepted} accepted, ${telemetry.reverted} undone`"
            />

            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-lg border p-3">
                    <dt class="text-xs text-muted-foreground">
                        Cost per accepted change
                    </dt>
                    <dd class="text-lg font-medium">
                        {{ dollars(telemetry.cost_per_accepted_change_usd) }}
                    </dd>
                </div>
                <div class="rounded-lg border p-3">
                    <dt class="text-xs text-muted-foreground">
                        Passed on the first attempt
                    </dt>
                    <dd class="text-lg font-medium">
                        {{
                            rate(
                                telemetry.first_attempt_passed,
                                telemetry.runs_verified,
                            )
                        }}
                    </dd>
                </div>
                <div class="rounded-lg border p-3">
                    <dt class="text-xs text-muted-foreground">
                        Changed parts not asked about
                    </dt>
                    <dd class="text-lg font-medium">
                        {{
                            rate(
                                telemetry.with_unexpected_changes,
                                telemetry.reviewed,
                            )
                        }}
                    </dd>
                </div>
                <div class="rounded-lg border p-3">
                    <dt class="text-xs text-muted-foreground">
                        Repairs before acceptance
                    </dt>
                    <dd class="text-lg font-medium">
                        {{ telemetry.repairs_before_acceptance ?? '–' }}
                    </dd>
                </div>
            </dl>

            <p class="text-xs text-muted-foreground">
                {{ dollars(telemetry.cost_usd) }} in total over
                {{ telemetry.input_tokens + telemetry.output_tokens }} tokens.
                <template v-if="telemetry.unpriced_calls > 0">
                    {{ telemetry.unpriced_calls }} model calls have no price and
                    are not in the cost.
                </template>
            </p>
        </section>

        <section
            v-if="history.length > 0"
            class="max-w-2xl space-y-4"
            data-test="project-history"
        >
            <Heading
                variant="small"
                title="History"
                description="Each accepted change is one commit"
            />

            <ol class="divide-y rounded-lg border">
                <li
                    v-for="commit in history"
                    :key="commit.sha"
                    class="flex items-baseline justify-between gap-4 p-3 text-sm"
                >
                    <span class="min-w-0 truncate">{{ commit.subject }}</span>
                    <span
                        class="shrink-0 font-mono text-xs text-muted-foreground"
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
