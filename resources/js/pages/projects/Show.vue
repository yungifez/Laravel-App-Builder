<script setup lang="ts">
import { Form, Head, Link, usePoll } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowUp,
    CircleCheck,
    CircleDot,
    CircleX,
    LoaderCircle,
    Undo2,
    ChevronDown,
    ExternalLink,
    MessageSquare,
    Monitor,
    MousePointerClick,
    RotateCw,
    Smartphone,
    Tablet,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import FeatureRequestController from '@/actions/App/Http/Controllers/FeatureRequestController';
import AppPreview from '@/components/AppPreview.vue';
import ChangeThread from '@/components/ChangeThread.vue';
import DesignPanel from '@/components/DesignPanel.vue';
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
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { useAppPreview } from '@/composables/useAppPreview';
import { when } from '@/lib/when';
import { show as showPreview } from '@/routes/previews';
import { index, show as showProject } from '@/routes/projects';
import { show as showUnderstanding } from '@/routes/projects/understanding';
import type {
    ChangeDetail,
    ChangeItem,
    ChangeState,
    Device,
    EditorPreview,
    InspectedElement,
    ProjectCommit,
    ProjectSummary,
    ProjectPublishing,
    ProjectTelemetry,
    VisualEditSummary,
} from '@/types';

const props = defineProps<{
    project: ProjectSummary;
    changes: ChangeItem[];
    change: ChangeDetail | null;
    preview: EditorPreview | null;
    design: boolean;
    element?: InspectedElement | null;
    edits: VisualEditSummary[];
    history: ProjectCommit[];
    telemetry: ProjectTelemetry;
    publishing: ProjectPublishing;
}>();

// The left panel talks about changes (Chat) or changes how the app looks
// (Design). Design turns the app into something to point at.
const panel = ref<'chat' | 'design'>(props.design ? 'design' : 'chat');
const designing = computed(() => panel.value === 'design');

// On a phone the panel and the app take turns on the screen.
const pane = ref<'panel' | 'app'>(props.design ? 'app' : 'panel');

const app = useAppPreview({
    projectId: () => props.project.id,
    preview: () => props.preview,
    element: () => props.element,
    designing,
});

// A phone shows one thing at a time: the chat, the app with the design
// panel under it, or just the app.
const phoneViews = [
    { key: 'chat', label: 'Chat', icon: MessageSquare },
    { key: 'design', label: 'Design', icon: MousePointerClick },
    { key: 'app', label: 'App', icon: Smartphone },
] as const;

const phoneView = computed<'chat' | 'design' | 'app'>({
    get: () =>
        pane.value === 'panel' ? 'chat' : designing.value ? 'design' : 'app',
    set: (view) => {
        pane.value = view === 'chat' ? 'panel' : 'app';
        panel.value = view === 'design' ? 'design' : 'chat';
    },
});

const detailsOpen = ref(false);
const publishOpen = ref(false);

const screens: { key: Device; label: string; icon: typeof Monitor }[] = [
    { key: 'base', label: 'Phone', icon: Smartphone },
    { key: 'md', label: 'Tablet', icon: Tablet },
    { key: 'lg', label: 'Desktop', icon: Monitor },
];

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

function toEnd(): void {
    nextTick(() => threadEnd.value?.scrollIntoView({ block: 'end' }));
}

onMounted(toEnd);
watch(() => props.changes.length, toEnd);
watch(panel, (value) => value === 'chat' && toEnd());

const states: Record<
    ChangeState,
    { label: string; icon: typeof Monitor; tone: string }
> = {
    waiting: {
        label: 'Ready for you',
        icon: CircleDot,
        tone: 'text-amber-500',
    },
    working: {
        label: 'Working on it',
        icon: LoaderCircle,
        tone: 'animate-spin text-amber-500',
    },
    kept: { label: 'Kept', icon: CircleCheck, tone: 'text-green-600' },
    stopped: { label: 'Stopped', icon: CircleX, tone: 'text-red-600' },
    undone: { label: 'Undone', icon: Undo2, tone: '' },
};

// Starting points for an empty conversation. A tap puts one in the box.
const ideas = [
    'Add a contact form',
    'Add a page that lists my customers',
    'Let people sign up with Google',
    'Send a welcome email to new users',
];

const composer = ref<HTMLTextAreaElement | null>(null);

function suggest(idea: string): void {
    if (composer.value !== null) {
        composer.value.value = idea;
        composer.value.focus();
    }
}

function send(event: KeyboardEvent): void {
    (event.target as HTMLTextAreaElement).form?.requestSubmit();
}
</script>

<template>
    <Head :title="project.name" />

    <header class="flex h-14 shrink-0 items-center gap-1 border-b px-2 sm:px-3">
        <Button
            variant="ghost"
            size="icon"
            class="size-11 shrink-0 sm:size-9"
            as-child
        >
            <Link :href="index()" aria-label="Your apps" data-test="back">
                <ArrowLeft class="size-4" />
            </Link>
        </Button>

        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    class="h-11 min-w-0 shrink gap-2 px-2 select-none sm:h-9"
                    data-test="app-menu"
                >
                    <span
                        :class="[
                            'size-2 shrink-0 rounded-full',
                            project.published_at
                                ? 'bg-green-600'
                                : 'bg-muted-foreground/40',
                        ]"
                        aria-hidden="true"
                    />
                    <span class="truncate font-semibold">{{
                        project.name
                    }}</span>
                    <ChevronDown class="size-4 shrink-0 opacity-60" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="w-64">
                <p
                    class="px-2 py-1.5 text-xs text-muted-foreground"
                    data-test="live-status"
                >
                    <template v-if="project.published_at"
                        >Live · put online
                        {{ when(project.published_at) }}</template
                    >
                    <template v-else>Not live yet</template>
                </p>
                <DropdownMenuSeparator />
                <DropdownMenuItem as-child>
                    <Link
                        :href="showUnderstanding(project.id)"
                        data-test="understanding-link"
                        >What I know about it</Link
                    >
                </DropdownMenuItem>
                <DropdownMenuItem
                    data-test="details-open"
                    @select="detailsOpen = true"
                >
                    Details for your developer
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem as-child>
                    <Link :href="index()">All your apps</Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <div class="ml-auto flex shrink-0 items-center gap-1">
            <template v-if="app.running && preview">
                <div
                    class="hidden items-center rounded-md bg-muted p-0.5 md:flex"
                    role="group"
                    aria-label="Screen size"
                >
                    <button
                        v-for="screen in screens"
                        :key="screen.key"
                        type="button"
                        :aria-pressed="app.device === screen.key"
                        :aria-label="screen.label"
                        :title="screen.label"
                        :class="[
                            'grid size-8 place-items-center rounded',
                            app.device === screen.key
                                ? 'bg-background shadow-sm'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        :data-test="`screen-${screen.key}`"
                        @click="app.device = screen.key"
                    >
                        <component :is="screen.icon" class="size-4" />
                    </button>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    class="hidden size-9 md:inline-flex"
                    aria-label="Reload"
                    title="Reload"
                    @click="app.reload()"
                >
                    <RotateCw class="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="hidden size-9 md:inline-flex"
                    as-child
                >
                    <a
                        :href="showPreview(preview.id).url"
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label="Open in a new tab"
                        title="Open in a new tab"
                    >
                        <ExternalLink class="size-4" />
                    </a>
                </Button>
            </template>

            <Button
                class="ml-1 h-11 select-none sm:h-9"
                data-test="publish-open"
                @click="publishOpen = true"
            >
                Put it online
            </Button>
        </div>
    </header>

    <Dialog v-model:open="publishOpen">
        <DialogContent>
            <DialogHeader class="sr-only">
                <DialogTitle>Put it online</DialogTitle>
                <DialogDescription>
                    Your latest kept version, for everyone to use
                </DialogDescription>
            </DialogHeader>
            <PublishPanel :project-id="project.id" :publishing="publishing" />
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="detailsOpen">
        <DialogContent class="max-h-[85svh] overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Details for your developer</DialogTitle>
                <DialogDescription>
                    Where the app came from, what changes cost, and its history.
                </DialogDescription>
            </DialogHeader>
            <ProjectDetails
                :source-path="project.source_path"
                :telemetry="telemetry"
                :history="history"
            />
        </DialogContent>
    </Dialog>

    <div
        class="grid grid-cols-3 border-b p-1 lg:hidden"
        role="group"
        aria-label="Show"
    >
        <button
            v-for="option in phoneViews"
            :key="option.key"
            type="button"
            :aria-pressed="phoneView === option.key"
            :class="[
                'flex min-h-11 items-center justify-center gap-1.5 rounded-md text-sm select-none',
                phoneView === option.key
                    ? 'bg-muted font-medium'
                    : 'text-muted-foreground',
            ]"
            :data-test="`view-${option.key}`"
            @click="phoneView = option.key"
        >
            <component :is="option.icon" class="size-4" />
            {{ option.label }}
            <span
                v-if="option.key === 'chat' && waiting > 0"
                class="rounded-full bg-amber-500/15 px-1.5 text-xs text-amber-700 tabular-nums dark:text-amber-400"
                >{{ waiting }}</span
            >
        </button>
    </div>

    <div
        class="grid min-h-0 flex-1 lg:grid-cols-[24rem_minmax(0,1fr)] [&>*]:min-w-0"
    >
        <aside
            :class="[
                'min-h-0 flex-col lg:flex lg:border-r',
                pane === 'panel' ? 'flex' : 'hidden',
            ]"
        >
            <div class="hidden border-b p-2 lg:block">
                <div
                    class="grid grid-cols-2 rounded-md bg-muted p-0.5 text-sm"
                    role="tablist"
                    aria-label="Panel"
                >
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="panel === 'chat'"
                        :class="[
                            'flex min-h-11 items-center justify-center gap-2 rounded select-none sm:min-h-8',
                            panel === 'chat'
                                ? 'bg-background font-medium shadow-sm'
                                : 'text-muted-foreground',
                        ]"
                        data-test="panel-chat"
                        @click="panel = 'chat'"
                    >
                        <MessageSquare class="size-4" /> Chat
                        <span
                            v-if="waiting > 0"
                            class="rounded-full bg-amber-500/15 px-1.5 text-xs text-amber-700 tabular-nums dark:text-amber-400"
                            >{{ waiting }}</span
                        >
                    </button>
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="panel === 'design'"
                        :class="[
                            'flex min-h-11 items-center justify-center gap-2 rounded select-none sm:min-h-8',
                            panel === 'design'
                                ? 'bg-background font-medium shadow-sm'
                                : 'text-muted-foreground',
                        ]"
                        data-test="panel-design"
                        @click="panel = 'design'"
                    >
                        <MousePointerClick class="size-4" /> Design
                    </button>
                </div>
            </div>

            <DesignPanel
                v-if="designing"
                class="flex-1"
                :project-id="project.id"
                :preview="preview"
                :element="element"
                :edits="edits"
                :state="app"
            />

            <section
                v-else
                class="flex min-h-0 flex-1 flex-col"
                data-test="conversation"
            >
                <ChangeThread v-if="change" :change="change" />

                <div v-else class="min-h-0 flex-1 overflow-y-auto p-4">
                    <div
                        v-if="thread.length === 0"
                        class="flex h-full flex-col justify-end gap-3 pb-2"
                        data-test="chat-empty"
                    >
                        <p class="text-lg font-semibold tracking-tight">
                            What should your app do next?
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="idea in ideas"
                                :key="idea"
                                type="button"
                                class="min-h-11 rounded-full border px-3 text-sm text-muted-foreground select-none hover:border-foreground/30 hover:text-foreground sm:min-h-8"
                                @click="suggest(idea)"
                            >
                                {{ idea }}
                            </button>
                        </div>
                    </div>

                    <ol
                        v-else
                        class="-mx-2 divide-y"
                        data-test="project-changes"
                    >
                        <li
                            v-for="item in thread"
                            :key="item.id"
                            :data-test="`change-${item.state}`"
                        >
                            <Link
                                :href="
                                    showProject(project.id, {
                                        query: { change: item.id },
                                    })
                                "
                                :only="['change']"
                                preserve-state
                                preserve-scroll
                                class="flex min-h-11 items-start gap-2.5 rounded-md px-2 py-2.5 select-none hover:bg-muted/60"
                            >
                                <component
                                    :is="states[item.state].icon"
                                    :class="[
                                        'mt-0.5 size-4 shrink-0',
                                        states[item.state].tone,
                                    ]"
                                    :aria-label="states[item.state].label"
                                />
                                <span
                                    :class="[
                                        'line-clamp-2 min-w-0 flex-1 text-sm break-words',
                                        item.state === 'waiting'
                                            ? 'font-medium'
                                            : 'text-muted-foreground',
                                        item.state === 'undone' &&
                                            'line-through',
                                    ]"
                                    >{{ item.prompt }}</span
                                >
                                <span
                                    v-if="item.state === 'waiting'"
                                    class="mt-0.5 shrink-0 text-xs font-medium text-amber-600 dark:text-amber-400"
                                    >Review</span
                                >
                                <span
                                    v-else-if="item.updated_at"
                                    class="mt-0.5 shrink-0 text-xs text-muted-foreground tabular-nums"
                                    >{{ when(item.updated_at) }}</span
                                >
                            </Link>
                        </li>
                    </ol>
                    <div ref="threadEnd" />
                </div>

                <Form
                    v-bind="FeatureRequestController.store.form(project.id)"
                    :options="{ preserveState: true }"
                    class="p-3"
                    reset-on-success
                    v-slot="{ errors, processing }"
                >
                    <div
                        class="rounded-xl border bg-background shadow-xs focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50"
                    >
                        <Label for="prompt" class="sr-only"
                            >What should your app do next?</Label
                        >
                        <textarea
                            id="prompt"
                            ref="composer"
                            name="prompt"
                            rows="3"
                            required
                            class="block w-full resize-none bg-transparent px-3 pt-3 text-base outline-none placeholder:text-muted-foreground md:text-sm"
                            placeholder="Ask for a change…"
                            @keydown.enter.meta.prevent="send"
                            @keydown.enter.ctrl.prevent="send"
                        />
                        <div class="flex items-center justify-between p-2">
                            <span
                                class="hidden pl-1 text-xs text-muted-foreground sm:inline"
                                >Ctrl + Enter</span
                            >
                            <Button
                                size="icon"
                                :disabled="processing"
                                class="ml-auto size-11 rounded-full sm:size-8"
                                aria-label="Ask for this change"
                                data-test="request-feature-button"
                            >
                                <ArrowUp class="size-4" />
                            </Button>
                        </div>
                    </div>
                    <InputError :message="errors.prompt" class="mt-1" />
                </Form>
            </section>
        </aside>

        <main
            :class="[
                'min-h-0 flex-col gap-2 p-2 lg:flex lg:p-3',
                pane === 'app' ? 'flex' : 'hidden',
            ]"
            data-test="app-pane"
        >
            <p
                v-if="preview?.updating"
                class="text-center text-xs text-muted-foreground"
                data-test="preview-updating"
            >
                Putting your change in place…
            </p>
            <div class="min-h-0 flex-1">
                <AppPreview
                    :project-id="project.id"
                    :preview="preview"
                    :state="app"
                />
            </div>
            <DesignPanel
                v-if="designing && pane === 'app'"
                class="max-h-[45svh] shrink-0 rounded-lg border lg:hidden"
                :project-id="project.id"
                :preview="preview"
                :element="element"
                :edits="edits"
                :state="app"
            />
        </main>
    </div>
</template>
