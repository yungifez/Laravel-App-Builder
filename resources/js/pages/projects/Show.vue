<script setup lang="ts">
import { Form, Head, Link, setLayoutProps, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FeatureRequestController from '@/actions/App/Http/Controllers/FeatureRequestController';
import AppTabs from '@/components/AppTabs.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PublishPanel from '@/components/PublishPanel.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import { when } from '@/lib/when';
import { show as showFeatureRequest } from '@/routes/feature-requests';
import { index, show } from '@/routes/projects';
import type {
    ChangeItem,
    ChangeState,
    ProjectCommit,
    ProjectSummary,
    ProjectPublishing,
    ProjectTelemetry,
} from '@/types';

const props = defineProps<{
    project: ProjectSummary;
    changes: ChangeItem[];
    history: ProjectCommit[];
    telemetry: ProjectTelemetry;
    publishing: ProjectPublishing;
}>();

// What needs the owner comes first; what is settled comes last.
const groups: { state: ChangeState; title: string }[] = [
    { state: 'waiting', title: 'Waiting for you' },
    { state: 'working', title: 'Working on it' },
    { state: 'kept', title: 'Kept' },
    { state: 'stopped', title: 'Stopped' },
    { state: 'undone', title: 'Undone' },
];

const grouped = computed(() =>
    groups
        .map((group) => ({
            ...group,
            items: props.changes.filter(
                (change) => change.state === group.state,
            ),
        }))
        .filter((group) => group.items.length > 0),
);

const working = computed(() =>
    props.changes.some((change) => change.state === 'working'),
);

const { start, stop } = usePoll(
    4000,
    { only: ['changes'] },
    { autoStart: false },
);

watch(working, (value) => (value ? start() : stop()), { immediate: true });

function label(change: ChangeItem): string {
    return change.state === 'kept' || change.state === 'undone'
        ? (change.summary ?? change.prompt)
        : change.prompt;
}

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
                { title: 'Your apps', href: index() },
                { title: project.name, href: show(project.id) },
            ],
        }),
    { immediate: true },
);
</script>

<template>
    <Head :title="props.project.name" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight break-words">
                {{ project.name }}
            </h1>
            <p class="text-sm text-muted-foreground" data-test="live-status">
                <template v-if="project.published_at"
                    >Live · last put online
                    {{ when(project.published_at) }}</template
                >
                <template v-else>Not live yet</template>
            </p>
        </header>

        <AppTabs :project-id="project.id" current="changes" />

        <div
            class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_20rem] [&>*]:min-w-0"
        >
            <div class="max-w-2xl space-y-8">
                <Form
                    v-bind="FeatureRequestController.store.form(project.id)"
                    class="space-y-3"
                    v-slot="{ errors, processing }"
                >
                    <Label for="prompt" class="text-base font-medium"
                        >What would you like to change?</Label
                    >
                    <textarea
                        id="prompt"
                        name="prompt"
                        rows="3"
                        required
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                        placeholder="Let team owners and admins invite people to their team by email."
                    />
                    <InputError :message="errors.prompt" />

                    <Button
                        :disabled="processing"
                        class="h-11 select-none sm:h-9"
                        data-test="request-feature-button"
                    >
                        Ask for this change
                    </Button>
                </Form>

                <p
                    v-if="changes.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    You have not asked for any changes yet. Describe one the way
                    you would to a colleague.
                </p>

                <div v-else class="space-y-8" data-test="project-changes">
                    <section
                        v-for="group in grouped"
                        :key="group.state"
                        :data-test="`changes-${group.state}`"
                    >
                        <h2
                            class="flex items-baseline justify-between border-b pb-2 text-sm font-medium"
                        >
                            {{ group.title }}
                            <span
                                class="text-xs font-normal text-muted-foreground tabular-nums"
                                >{{ group.items.length }}</span
                            >
                        </h2>
                        <ul class="divide-y">
                            <li v-for="change in group.items" :key="change.id">
                                <Link
                                    :href="showFeatureRequest(change.id)"
                                    class="flex min-h-11 items-baseline justify-between gap-4 py-3 text-sm select-none hover:bg-muted/50"
                                >
                                    <span
                                        :class="[
                                            'min-w-0 break-words',
                                            change.state === 'undone' &&
                                                'text-muted-foreground line-through',
                                        ]"
                                        >{{ label(change) }}</span
                                    >
                                    <span
                                        class="shrink-0 text-xs text-muted-foreground"
                                    >
                                        {{ when(change.updated_at) }}
                                    </span>
                                </Link>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>

            <aside class="space-y-8">
                <PublishPanel
                    :project-id="project.id"
                    :publishing="publishing"
                />

                <Collapsible data-test="project-details">
                    <CollapsibleTrigger
                        class="min-h-11 text-sm text-muted-foreground underline underline-offset-4 select-none sm:min-h-0"
                    >
                        Details for your developer
                    </CollapsibleTrigger>
                    <CollapsibleContent class="mt-4 space-y-6 text-sm">
                        <p class="text-muted-foreground">
                            Imported from
                            <span class="font-mono break-all">{{
                                project.source_path
                            }}</span>
                        </p>

                        <section
                            v-if="
                                telemetry.requests > 0 ||
                                telemetry.visual_edits > 0
                            "
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
                                        {{
                                            dollars(
                                                telemetry.cost_per_accepted_change_usd,
                                            )
                                        }}
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
                                        {{
                                            telemetry.repairs_before_acceptance ??
                                            '–'
                                        }}
                                    </dd>
                                </div>
                            </dl>

                            <p class="text-xs text-muted-foreground">
                                {{ dollars(telemetry.cost_usd) }} in total over
                                {{
                                    telemetry.input_tokens +
                                    telemetry.output_tokens
                                }}
                                tokens.
                                <template v-if="telemetry.unpriced_calls > 0">
                                    {{ telemetry.unpriced_calls }} model calls
                                    have no price and are not in the cost.
                                </template>
                                {{ telemetry.visual_edits }} changes to how it
                                looks were made without a model.
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
                                <li
                                    v-for="commit in history"
                                    :key="commit.sha"
                                    class="py-2"
                                >
                                    <span class="block break-words">{{
                                        commit.subject
                                    }}</span>
                                    <span
                                        class="block font-mono text-xs text-muted-foreground"
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
            </aside>
        </div>
    </div>
</template>
