<script setup lang="ts">
import { Form, Head, Link, setLayoutProps, usePoll } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import FeatureRequestController from '@/actions/App/Http/Controllers/FeatureRequestController';
import AppPreview from '@/components/AppPreview.vue';
import InputError from '@/components/InputError.vue';
import ProjectDetails from '@/components/ProjectDetails.vue';
import PublishPanel from '@/components/PublishPanel.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { when } from '@/lib/when';
import { show as showFeatureRequest } from '@/routes/feature-requests';
import { index, show } from '@/routes/projects';
import { show as showEditor } from '@/routes/projects/editor';
import { show as showUnderstanding } from '@/routes/projects/understanding';
import type {
    ChangeItem,
    ChangeState,
    EditorPreview,
    ProjectCommit,
    ProjectSummary,
    ProjectPublishing,
    ProjectTelemetry,
} from '@/types';

const props = defineProps<{
    project: ProjectSummary;
    changes: ChangeItem[];
    preview: EditorPreview | null;
    history: ProjectCommit[];
    telemetry: ProjectTelemetry;
    publishing: ProjectPublishing;
}>();

// Like other app builders: the conversation on the left, the app itself on
// the right. On a phone the two share the screen, one at a time.
const pane = ref<'chat' | 'app'>('chat');

// A conversation reads oldest first, with the newest ask next to the box.
const thread = computed(() => [...props.changes].reverse());
const threadEnd = ref<HTMLElement | null>(null);

const waiting = computed(
    () => props.changes.filter((change) => change.state === 'waiting').length,
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

onMounted(() => threadEnd.value?.scrollIntoView({ block: 'end' }));
watch(
    () => props.changes.length,
    () => nextTick(() => threadEnd.value?.scrollIntoView({ block: 'end' })),
);

const states: Record<ChangeState, { label: string; dot: string }> = {
    waiting: { label: 'Waiting for you', dot: 'bg-amber-500' },
    working: { label: 'Working on it', dot: 'bg-amber-500 animate-pulse' },
    kept: { label: 'Kept', dot: 'bg-green-600' },
    stopped: { label: 'Stopped', dot: 'bg-red-600' },
    undone: { label: 'Undone', dot: 'bg-muted-foreground' },
};

// What the builder says back, in one line.
function reply(change: ChangeItem): string {
    switch (change.state) {
        case 'working':
            return 'I am working on this.';
        case 'stopped':
            return 'I stopped before finishing. Open it to try again.';
        case 'waiting':
            return change.summary ?? 'I have a question before I start.';
        default:
            return change.summary ?? change.prompt;
    }
}

function send(event: KeyboardEvent): void {
    (event.target as HTMLTextAreaElement).form?.requestSubmit();
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
    <Head :title="project.name" />

    <!-- The workspace fills the screen below the header on a wide screen,
         so the conversation and the app scroll on their own. -->
    <div class="flex flex-1 flex-col lg:h-[calc(100svh-5rem)] lg:min-h-0">
        <header
            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b px-4 py-3"
        >
            <div class="min-w-0">
                <h1 class="text-lg font-semibold tracking-tight break-words">
                    {{ project.name }}
                </h1>
                <p
                    class="text-sm text-muted-foreground"
                    data-test="live-status"
                >
                    <template v-if="project.published_at"
                        >Live · last put online
                        {{ when(project.published_at) }}</template
                    >
                    <template v-else>Not live yet</template>
                </p>
            </div>

            <nav
                class="flex flex-wrap items-center gap-1"
                aria-label="Your app"
            >
                <Button variant="ghost" class="h-11 sm:h-9" as-child>
                    <Link :href="showEditor(project.id)" data-test="editor-link"
                        >How it looks</Link
                    >
                </Button>
                <Button variant="ghost" class="h-11 sm:h-9" as-child>
                    <Link
                        :href="showUnderstanding(project.id)"
                        data-test="understanding-link"
                        >What I know</Link
                    >
                </Button>
                <Dialog>
                    <DialogTrigger as-child>
                        <Button
                            variant="ghost"
                            class="h-11 text-muted-foreground sm:h-9"
                            data-test="details-open"
                        >
                            Details
                        </Button>
                    </DialogTrigger>
                    <DialogContent class="max-h-[85svh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle
                                >Details for your developer</DialogTitle
                            >
                            <DialogDescription>
                                Where the app came from, what changes cost, and
                                its history.
                            </DialogDescription>
                        </DialogHeader>
                        <ProjectDetails
                            :source-path="project.source_path"
                            :telemetry="telemetry"
                            :history="history"
                        />
                    </DialogContent>
                </Dialog>
                <Dialog>
                    <DialogTrigger as-child>
                        <Button
                            variant="outline"
                            class="h-11 select-none sm:h-9"
                            data-test="publish-open"
                        >
                            Put it online
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader class="sr-only">
                            <DialogTitle>Put it online</DialogTitle>
                            <DialogDescription>
                                Your latest kept version, for everyone to use
                            </DialogDescription>
                        </DialogHeader>
                        <PublishPanel
                            :project-id="project.id"
                            :publishing="publishing"
                        />
                    </DialogContent>
                </Dialog>
            </nav>
        </header>

        <div
            class="grid grid-cols-2 border-b p-2 lg:hidden"
            role="group"
            aria-label="Show"
        >
            <button
                v-for="option in [
                    { key: 'chat', label: 'Changes' },
                    { key: 'app', label: 'Your app' },
                ] as const"
                :key="option.key"
                type="button"
                :aria-pressed="pane === option.key"
                :class="[
                    'min-h-11 rounded-md text-sm select-none',
                    pane === option.key
                        ? 'bg-muted font-medium'
                        : 'text-muted-foreground',
                ]"
                :data-test="`pane-${option.key}`"
                @click="pane = option.key"
            >
                {{ option.label }}
                <span
                    v-if="option.key === 'chat' && waiting > 0"
                    class="ml-1 tabular-nums"
                    >· {{ waiting }} waiting</span
                >
            </button>
        </div>

        <div
            class="grid min-h-0 flex-1 lg:grid-cols-[26rem_minmax(0,1fr)] [&>*]:min-w-0"
        >
            <section
                :class="[
                    'min-h-0 flex-col lg:flex lg:border-r',
                    pane === 'chat' ? 'flex' : 'hidden',
                ]"
                data-test="conversation"
            >
                <div class="min-h-0 flex-1 overflow-y-auto p-4">
                    <div
                        v-if="thread.length === 0"
                        class="flex h-full flex-col justify-end gap-2 pb-4 text-sm text-muted-foreground"
                    >
                        <p class="font-medium text-foreground">
                            What would you like to change?
                        </p>
                        <p>
                            Describe it the way you would to a colleague. I show
                            you what I will do before anything in your app
                            changes.
                        </p>
                    </div>

                    <ol v-else class="space-y-6" data-test="project-changes">
                        <li
                            v-for="change in thread"
                            :key="change.id"
                            class="space-y-2"
                            :data-test="`change-${change.state}`"
                        >
                            <p
                                class="ml-8 rounded-lg bg-muted px-3 py-2 text-sm break-words"
                            >
                                {{ change.prompt }}
                            </p>
                            <Link
                                :href="showFeatureRequest(change.id)"
                                class="-mx-2 block min-h-11 rounded-md px-2 py-2 select-none hover:bg-muted/50"
                            >
                                <span
                                    class="flex items-center gap-2 text-xs text-muted-foreground"
                                >
                                    <span
                                        :class="[
                                            'size-2 shrink-0 rounded-full',
                                            states[change.state].dot,
                                        ]"
                                        aria-hidden="true"
                                    />
                                    {{ states[change.state].label }}
                                    <template v-if="change.updated_at"
                                        >·
                                        {{ when(change.updated_at) }}</template
                                    >
                                </span>
                                <span
                                    :class="[
                                        'mt-1 block text-sm break-words',
                                        change.state === 'undone' &&
                                            'text-muted-foreground line-through',
                                    ]"
                                    >{{ reply(change) }}</span
                                >
                                <span
                                    v-if="change.state === 'waiting'"
                                    class="mt-1 block text-sm font-medium underline underline-offset-4"
                                    >Look at it</span
                                >
                            </Link>
                        </li>
                    </ol>
                    <div ref="threadEnd" />
                </div>

                <Form
                    v-bind="FeatureRequestController.store.form(project.id)"
                    class="space-y-2 border-t p-4"
                    reset-on-success
                    v-slot="{ errors, processing }"
                >
                    <Label for="prompt" class="sr-only"
                        >What would you like to change?</Label
                    >
                    <textarea
                        id="prompt"
                        name="prompt"
                        rows="3"
                        required
                        class="w-full resize-none rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                        placeholder="Let team owners and admins invite people to their team by email."
                        @keydown.enter.meta.prevent="send"
                        @keydown.enter.ctrl.prevent="send"
                    />
                    <InputError :message="errors.prompt" />
                    <div class="flex items-center justify-between gap-2">
                        <span
                            class="hidden text-xs text-muted-foreground sm:inline"
                            >Ctrl + Enter to send</span
                        >
                        <Button
                            :disabled="processing"
                            class="ml-auto h-11 select-none sm:h-9"
                            data-test="request-feature-button"
                        >
                            Ask for this change
                        </Button>
                    </div>
                </Form>
            </section>

            <section
                :class="[
                    'min-h-0 p-2 lg:block lg:p-3',
                    pane === 'app' ? 'block' : 'hidden',
                ]"
                data-test="app-pane"
            >
                <AppPreview :project-id="project.id" :preview="preview" />
            </section>
        </div>
    </div>
</template>
