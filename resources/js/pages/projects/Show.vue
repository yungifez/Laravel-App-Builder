<script setup lang="ts">
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { watch } from 'vue';
import FeatureRequestController from '@/actions/App/Http/Controllers/FeatureRequestController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import { show as showFeatureRequest } from '@/routes/feature-requests';
import { index, show } from '@/routes/projects';
import { show as showEditor } from '@/routes/projects/editor';
import { Badge } from '@/components/ui/badge';
import type {
    FeatureRequestSummary,
    KeptChange,
    ProjectCommit,
    ProjectSummary,
    ProjectTelemetry,
} from '@/types';

const props = defineProps<{
    project: ProjectSummary;
    featureRequests: FeatureRequestSummary[];
    changes: KeptChange[];
    history: ProjectCommit[];
    telemetry: ProjectTelemetry;
}>();

function rate(part: number, whole: number): string {
    return whole === 0 ? '–' : `${Math.round((part / whole) * 100)}%`;
}

function day(iso: string | null): string {
    return iso === null ? '' : new Date(iso).toLocaleDateString();
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
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading :title="project.name" />
            <Button as-child variant="outline">
                <Link :href="showEditor(project.id)" data-test="editor-link">
                    Change how it looks
                </Link>
            </Button>
        </div>

        <section class="max-w-2xl space-y-6">
            <Heading
                variant="small"
                title="What would you like to change?"
                description="Describe it the way you would to a colleague"
            />

            <Form
                v-bind="FeatureRequestController.store.form(project.id)"
                class="space-y-4"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="prompt" class="sr-only">Your change</Label>
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
                    Ask for this change
                </Button>
            </Form>
        </section>

        <section
            v-if="changes.length > 0"
            class="max-w-2xl space-y-4"
            data-test="project-changes"
        >
            <Heading
                variant="small"
                title="What changed"
                description="The changes you kept, newest first"
            />

            <ol class="divide-y rounded-lg border">
                <li v-for="change in changes" :key="change.id">
                    <Link
                        :href="showFeatureRequest(change.id)"
                        class="flex items-baseline justify-between gap-4 p-3 text-sm hover:bg-muted/50"
                    >
                        <span
                            :class="[
                                'min-w-0',
                                change.reverted_at &&
                                    'text-muted-foreground line-through',
                            ]"
                            >{{ change.summary }}</span
                        >
                        <span class="shrink-0 text-xs text-muted-foreground">
                            <template v-if="change.reverted_at"
                                >Undone {{ day(change.reverted_at) }}</template
                            >
                            <template v-else>{{
                                day(change.accepted_at)
                            }}</template>
                        </span>
                    </Link>
                </li>
            </ol>
        </section>

        <section class="max-w-2xl space-y-4">
            <Heading variant="small" title="Your requests" />

            <p
                v-if="featureRequests.length === 0"
                class="text-sm text-muted-foreground"
            >
                You have not asked for any changes yet.
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
                                >Kept</Badge
                            >
                            <StatusBadge v-else :status="request.status" />
                        </span>
                    </Link>
                </li>
            </ul>
        </section>

        <Collapsible class="max-w-2xl" data-test="project-details">
            <CollapsibleTrigger
                class="text-sm text-muted-foreground underline underline-offset-4"
            >
                Details
            </CollapsibleTrigger>
            <CollapsibleContent class="mt-4 space-y-6 text-sm">
                <p class="text-muted-foreground">
                    Imported from
                    <span class="font-mono">{{ project.source_path }}</span>
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

                    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="rounded-lg border p-3">
                            <dt class="text-xs text-muted-foreground">
                                Cost per kept change
                            </dt>
                            <dd class="text-lg font-medium">
                                {{
                                    dollars(
                                        telemetry.cost_per_accepted_change_usd,
                                    )
                                }}
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
                                Repairs before keeping
                            </dt>
                            <dd class="text-lg font-medium">
                                {{ telemetry.repairs_before_acceptance ?? '–' }}
                            </dd>
                        </div>
                    </dl>

                    <p class="text-xs text-muted-foreground">
                        {{ dollars(telemetry.cost_usd) }} in total over
                        {{ telemetry.input_tokens + telemetry.output_tokens }}
                        tokens.
                        <template v-if="telemetry.unpriced_calls > 0">
                            {{ telemetry.unpriced_calls }} model calls have no
                            price and are not in the cost.
                        </template>
                        {{ telemetry.visual_edits }} changes to how it looks
                        were made without a model.
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

                    <ol class="divide-y rounded-lg border">
                        <li
                            v-for="commit in history"
                            :key="commit.sha"
                            class="flex items-baseline justify-between gap-4 p-3"
                        >
                            <span class="min-w-0 truncate">{{
                                commit.subject
                            }}</span>
                            <span
                                class="shrink-0 font-mono text-xs text-muted-foreground"
                                >{{ commit.sha.slice(0, 7) }} ·
                                {{
                                    new Date(
                                        commit.committed_at,
                                    ).toLocaleString()
                                }}</span
                            >
                        </li>
                    </ol>
                </section>
            </CollapsibleContent>
        </Collapsible>
    </div>
</template>
