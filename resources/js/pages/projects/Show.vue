<script setup lang="ts">
import {
    Form,
    Head,
    Link,
    router,
    usePoll,
    useRemember,
} from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowUp,
    CircleCheck,
    CircleDot,
    CircleX,
    Lightbulb,
    LoaderCircle,
    Maximize2,
    Minimize2,
    Undo2,
    X,
    ChevronDown,
    ExternalLink,
    ImagePlus,
    MessageSquare,
    Monitor,
    MousePointerClick,
    RotateCw,
    Smartphone,
    Tablet,
} from '@lucide/vue';
import { useMediaQuery, useResizeObserver } from '@vueuse/core';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import FeatureRequestController from '@/actions/App/Http/Controllers/FeatureRequestController';
import FeatureRequestDismissalController from '@/actions/App/Http/Controllers/FeatureRequestDismissalController';
import FeatureRequestFollowUpController from '@/actions/App/Http/Controllers/FeatureRequestFollowUpController';
import ProjectExperimentController from '@/actions/App/Http/Controllers/ProjectExperimentController';
import ProjectPreviewController from '@/actions/App/Http/Controllers/ProjectPreviewController';
import AppPreview from '@/components/AppPreview.vue';
import BesidePanel from '@/components/BesidePanel.vue';
import ChangeThread from '@/components/ChangeThread.vue';
import ChatList from '@/components/ChatList.vue';
import DesignPanel from '@/components/DesignPanel.vue';
import IdeaMenu from '@/components/ideas/IdeaMenu.vue';
import StartIdeaDialog from '@/components/ideas/StartIdeaDialog.vue';
import UseIdeaDialog from '@/components/ideas/UseIdeaDialog.vue';
import InputError from '@/components/InputError.vue';
import ProjectDetails from '@/components/ProjectDetails.vue';
import NotificationBell from '@/components/NotificationBell.vue';
import PublishPanel from '@/components/PublishPanel.vue';
import RenameAppDialog from '@/components/RenameAppDialog.vue';
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
import { usePanelWidth } from '@/composables/usePanelWidth';
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
    Idea,
    Ideas,
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
    ideas: Ideas;
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

// On a wide screen the owner drags the panel's edge to read a change in full.
const panelWidth = usePanelWidth();

// Or reads a change's code on the whole screen. Designing needs the app in
// view, so it brings the app back.
const codeFull = ref(false);

// The chat has the whole screen until the app is open, since there is
// nothing to show beside it yet. The owner can switch either way.
const appOpen = computed(
    () =>
        props.preview !== null &&
        ['starting', 'ready'].includes(props.preview.status),
);
// Kept in the browser history, so Back returns to the chat as it was.
const chat = useRemember(
    reactive<{ full: boolean | null }>({ full: null }),
    'chat-full',
) as { full: boolean | null };
const chatFull = computed(() => chat.full ?? !appOpen.value);

// Opening the app is the moment to see it, whatever was chosen before.
watch(appOpen, (open) => open && (chat.full = null));

// Code on the whole screen has its own switch, in the change.
const codeOnScreen = computed(() => codeFull.value && props.change !== null);
const panelFull = computed(
    () => !designing.value && (codeOnScreen.value || chatFull.value),
);

// A chat on the whole screen reads down the middle, not stretched across.
const chatCentred = computed(() => panelFull.value && !codeOnScreen.value);

// On a desktop an open change has its plan and code on its right, and on
// a wide screen the owner's other chats on its left too. The columns stay
// put while the owner moves between chats, so nothing jumps; a chat
// without a plan yet says so on the right. The tabs above line up with the
// chat.
const SIDES = { left: '16rem', right: 'clamp(22rem, 28vw, 30rem)' };
const desktop = useMediaQuery('(min-width: 1024px)');
const wide = useMediaQuery('(min-width: 1280px)');
const threadSides = ref(false);
const panelOn = computed(
    () => chatCentred.value && desktop.value && props.change !== null,
);
const listOn = computed(() => panelOn.value && wide.value);
const besideChat = computed(() =>
    panelOn.value
        ? {
              paddingLeft: listOn.value
                  ? `calc(${SIDES.left} + 0.5rem)`
                  : undefined,
              paddingRight: `calc(${SIDES.right} + 0.5rem)`,
          }
        : undefined,
);

const app = useAppPreview({
    projectId: () => props.project.id,
    preview: () => props.preview,
    element: () => props.element,
    edits: () => props.edits,
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

// Online, but not the newest kept version: a dot on "Put it online" says
// so without opening it. An app that was never online gets no dot.
const behind = computed(() => {
    const live = props.publishing.deployments.find(
        (deployment) => deployment.status === 'published',
    );

    return (
        live !== undefined &&
        props.publishing.head !== null &&
        props.publishing.head !== live.commit
    );
});
const startingIdea = ref(false);
const renaming = ref(false);
const usingIdea = ref(false);

function openIdea(idea: Idea): void {
    router.put(ProjectExperimentController.update.url(props.project.id), {
        experiment: idea.id,
    });
}

const screens: { key: Device; label: string; icon: typeof Monitor }[] = [
    { key: 'base', label: 'Phone', icon: Smartphone },
    { key: 'md', label: 'Tablet', icon: Tablet },
    { key: 'lg', label: 'Desktop', icon: Monitor },
];

// A conversation reads oldest first, with the newest ask next to the box.
// Finished changes are grouped by day, so the day is said once instead of on
// every row. Changes still open sit together just above the box, where the
// owner acts, under what they need: the owner, or only time.
// Quick filters over the list. "All" leaves out what the owner set aside;
// that has a filter of its own. What waits for the owner is split by the
// job: a question from me to answer, or a change to try. The owner's own
// questions, which I answered, are "Answered", so the two are not confused.
type Filter =
    | 'all'
    | 'asks'
    | 'waiting'
    | 'kept'
    | 'answered'
    | 'stopped'
    | 'dismissed';
const filterLabels: Record<Filter, string> = {
    all: 'All',
    asks: 'To answer',
    waiting: 'To try',
    kept: 'Kept',
    answered: 'Answered',
    stopped: 'Stopped',
    dismissed: 'Not needed',
};
const shown = useRemember(
    reactive<{ filter: Filter }>({ filter: 'all' }),
    'change-filter',
) as { filter: Filter };
const inFilter = (item: ChangeItem, filter: Filter): boolean => {
    switch (filter) {
        case 'all':
            return item.state !== 'dismissed';
        case 'asks':
            return item.state === 'waiting' && item.asks;
        case 'waiting':
            return item.state === 'waiting' && !item.asks;
        case 'stopped':
            return item.state === 'stopped' || item.state === 'undone';
        default:
            return item.state === filter;
    }
};
const filters = computed(() =>
    (Object.keys(filterLabels) as Filter[])
        .map((filter) => ({
            filter,
            label: filterLabels[filter],
            count: props.changes.filter((item) => inFilter(item, filter))
                .length,
        }))
        .filter(({ filter, count }) => filter === 'all' || count > 0),
);

// Filters that do not fit fade out at the edge that has more, so the
// row reads as one that scrolls rather than one that is cut off.
const filterRow = ref<HTMLElement | null>(null);
const filterEdges = ref({ start: false, end: false });
const filterFade = computed(() => {
    const { start, end } = filterEdges.value;

    if (start && end) {
        return '[mask-image:linear-gradient(to_right,transparent,black_2rem,black_calc(100%-2rem),transparent)]';
    }

    if (end) {
        return '[mask-image:linear-gradient(to_right,black_calc(100%-2rem),transparent)]';
    }

    return start
        ? '[mask-image:linear-gradient(to_right,transparent,black_2rem)]'
        : '';
});

function measureFilters(): void {
    const row = filterRow.value;

    filterEdges.value = row
        ? {
              start: row.scrollLeft > 1,
              end: row.scrollLeft + row.clientWidth < row.scrollWidth - 1,
          }
        : { start: false, end: false };
}

useResizeObserver(filterRow, measureFilters);
watch(filters, () => nextTick(measureFilters));

// A filter emptied by the owner's last action falls back to everything.
watch(filters, (list) => {
    if (!list.some(({ filter }) => filter === shown.filter)) {
        shown.filter = 'all';
    }
});

function dismiss(item: ChangeItem): void {
    const action =
        item.state === 'dismissed'
            ? FeatureRequestDismissalController.destroy
            : FeatureRequestDismissalController.store;

    router.visit(action(item.id), {
        preserveScroll: true,
        preserveState: true,
        only: ['changes'],
    });
}

const thread = computed(() => {
    const oldestFirst = [...props.changes]
        .reverse()
        .filter((item) => inFilter(item, shown.filter));
    const open = (item: ChangeItem) =>
        item.state === 'waiting' || item.state === 'working';

    // A stopped change asked again later is the same ask: show the latest.
    const history = oldestFirst.filter(
        (item, index) =>
            !open(item) &&
            (item.state !== 'stopped' ||
                !oldestFirst
                    .slice(index + 1)
                    .some(
                        (later) => later.prompt.trim() === item.prompt.trim(),
                    )),
    );

    const groups: { label: string; items: ChangeItem[] }[] = [];

    for (const item of history) {
        const day = when(item.updated_at);
        const label = day.charAt(0).toUpperCase() + day.slice(1);

        if (groups.at(-1)?.label === label) {
            groups.at(-1)?.items.push(item);
        } else {
            groups.push({ label, items: [item] });
        }
    }

    const working = oldestFirst.filter((item) => item.state === 'working');
    const waiting = oldestFirst.filter((item) => item.state === 'waiting');

    return [
        ...groups,
        ...(working.length ? [{ label: 'Working on it', items: working }] : []),
        ...(waiting.length
            ? [{ label: waitingLabel(waiting), items: waiting }]
            : []),
    ];
});
const threadEnd = ref<HTMLElement | null>(null);

// Says what is waiting, so the owner knows the job before opening a row.
function waitingLabel(items: ChangeItem[]): string {
    const questions = items.filter((item) => item.asks).length;
    const changes = items.length - questions;

    return [
        questions > 0 &&
            `${questions} ${questions === 1 ? 'question' : 'questions'} to answer`,
        changes > 0 &&
            `${changes} ${changes === 1 ? 'change' : 'changes'} to try`,
    ]
        .filter(Boolean)
        .join(' · ');
}

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
    answered: {
        label: 'Answered',
        icon: MessageSquare,
        tone: 'text-muted-foreground',
    },
    kept: { label: 'Kept', icon: CircleCheck, tone: 'text-green-600' },
    stopped: { label: 'Stopped', icon: CircleX, tone: 'text-red-600' },
    undone: { label: 'Undone', icon: Undo2, tone: '' },
    dismissed: { label: 'Not needed', icon: X, tone: 'text-muted-foreground' },
};

// Starting points for an empty conversation. A tap puts one in the box.
const suggestions = [
    'Add a contact form',
    'Add a page that lists my customers',
    'Let people sign up with Google',
    'Send a welcome email to new users',
];

const composer = ref<HTMLTextAreaElement | null>(null);

// A message continues the open chat: it builds on the change there or follows
// on from an answer. A change that failed or was undone leaves nothing to
// build on, so the message starts a new chat.
const continuing = computed(
    () => props.change?.featureRequest.can_continue === true,
);
const composerForm = computed(() =>
    props.change && continuing.value
        ? FeatureRequestFollowUpController.store.form(
              props.change.featureRequest.id,
          )
        : FeatureRequestController.store.form(props.project.id),
);

// The message box stays one line until the owner writes in it, so a change
// open above it gets the room.
const composerOpen = ref(false);

function settleComposer(): void {
    composerOpen.value =
        composer.value !== null &&
        (document.activeElement === composer.value ||
            composer.value.value.trim() !== '');
}

function suggest(idea: string): void {
    if (composer.value !== null) {
        composer.value.value = idea;
        composer.value.focus();
    }
}

// A request started elsewhere, such as changing a decision from the
// Understanding page, arrives as ?ask= and waits in the box to be finished.
// It leaves the address, so a reload does not bring it back.
onMounted(() => {
    const url = new URL(window.location.href);
    const ask = url.searchParams.get('ask');

    if (ask === null || composer.value === null) {
        return;
    }

    suggest(ask);
    composerOpen.value = true;
    composer.value.setSelectionRange(ask.length, ask.length);
    url.searchParams.delete('ask');
    // After Inertia records this visit in the history, or it puts it back.
    setTimeout(() =>
        window.history.replaceState(window.history.state, '', url),
    );
});

// Pictures that show what the owner means (a screenshot, a sketch). They
// ride along in the form's file input, kept in step with the list shown.
const imageInput = ref<HTMLInputElement | null>(null);
const images = ref<{ file: File; url: string }[]>([]);
const maxImages = 4;

function syncImageInput(): void {
    if (imageInput.value === null) {
        return;
    }

    const files = new DataTransfer();
    images.value.forEach((image) => files.items.add(image.file));
    imageInput.value.files = files.files;
}

function addImages(files: Iterable<File>): void {
    for (const file of files) {
        if (file.type.startsWith('image/') && images.value.length < maxImages) {
            images.value.push({ file, url: URL.createObjectURL(file) });
        }
    }

    syncImageInput();
    composerOpen.value = composerOpen.value || images.value.length > 0;
}

function removeImage(index: number): void {
    URL.revokeObjectURL(images.value[index].url);
    images.value.splice(index, 1);
    syncImageInput();
}

function clearImages(): void {
    images.value.forEach((image) => URL.revokeObjectURL(image.url));
    images.value = [];
    syncImageInput();
}

// A picture dragged onto the box is attached; the box shows where it lands.
const dragging = ref(false);

function dropImages(event: DragEvent): void {
    dragging.value = false;
    addImages(Array.from(event.dataTransfer?.files ?? []));
}

// A screenshot pasted into the box is attached, as in any chat.
function pasteImages(event: ClipboardEvent): void {
    const files = Array.from(event.clipboardData?.files ?? []).filter((file) =>
        file.type.startsWith('image/'),
    );

    if (files.length > 0) {
        event.preventDefault();
        addImages(files);
    }
}

function send(event: KeyboardEvent): void {
    (event.target as HTMLTextAreaElement).form?.requestSubmit();
}

// Enter sends, as in any chat; Shift + Enter starts a new line. On a touch
// screen the keyboard's Enter is the only way to a new line, so it stays one.
// A key that ends an accented or Asian character being typed is not a send.
function sendOnEnter(event: KeyboardEvent): void {
    if (event.isComposing || window.matchMedia('(pointer: coarse)').matches) {
        return;
    }

    event.preventDefault();
    send(event);
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
                <DropdownMenuItem
                    data-test="idea-new"
                    @select="startingIdea = true"
                >
                    <Lightbulb class="size-4" />
                    Try an idea…
                </DropdownMenuItem>
                <template v-if="!ideas.current">
                    <DropdownMenuItem
                        v-for="idea in ideas.open"
                        :key="idea.id"
                        :data-test="`idea-open-${idea.id}`"
                        @select="openIdea(idea)"
                    >
                        <span class="size-4" aria-hidden="true" />
                        <span class="truncate">{{ idea.name }}</span>
                    </DropdownMenuItem>
                </template>
                <DropdownMenuSeparator />
                <DropdownMenuItem as-child>
                    <Link
                        :href="showUnderstanding(project.id)"
                        data-test="understanding-link"
                        >What I know about it</Link
                    >
                </DropdownMenuItem>
                <DropdownMenuItem
                    data-test="app-rename"
                    @select="renaming = true"
                >
                    Rename…
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

        <IdeaMenu
            v-if="ideas.current"
            :project-id="project.id"
            :ideas="{ ...ideas, current: ideas.current }"
        />

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

            <Form
                v-if="panelFull && !appOpen"
                v-bind="ProjectPreviewController.store.form(project.id)"
                :options="{ preserveScroll: true, preserveState: true }"
                v-slot="{ processing }"
                class="hidden lg:block"
            >
                <Button
                    variant="outline"
                    :disabled="processing"
                    class="h-9 select-none"
                    data-test="header-open-app"
                    >Open my app</Button
                >
            </Form>

            <NotificationBell />
            <!-- In an idea, the next step is to use it; only the app itself
                 goes online. -->
            <Button
                v-if="ideas.current"
                class="ml-1 h-11 select-none sm:h-9"
                data-test="idea-use"
                @click="usingIdea = true"
            >
                Use this idea
            </Button>
            <Button
                v-else
                class="relative ml-1 h-11 select-none sm:h-9"
                data-test="publish-open"
                @click="publishOpen = true"
            >
                Put it online
                <span
                    v-if="behind"
                    class="absolute -top-1 -right-1 size-2.5 rounded-full border-2 border-background bg-amber-500"
                    data-test="publish-behind"
                >
                    <span class="sr-only">Newer changes aren't online yet</span>
                </span>
            </Button>
        </div>
    </header>

    <StartIdeaDialog v-model:open="startingIdea" :project-id="project.id" />
    <RenameAppDialog
        v-model:open="renaming"
        :project-id="project.id"
        :name="project.name"
    />
    <UseIdeaDialog
        v-if="ideas.current"
        v-model:open="usingIdea"
        :idea="ideas.current"
    />

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
        :class="[
            'grid min-h-0 flex-1 [&>*]:min-w-0',
            panelFull
                ? 'lg:grid-cols-1'
                : 'lg:grid-cols-[var(--panel-width)_minmax(0,1fr)]',
            // The app is a frame that would swallow the drag.
            panelWidth.resizing.value
                ? 'cursor-col-resize select-none [&_iframe]:pointer-events-none'
                : '',
        ]"
        :style="{ '--panel-width': `${panelWidth.width.value}px` }"
    >
        <aside
            :class="[
                'relative min-h-0 flex-col lg:flex',
                pane === 'panel' ? 'flex' : 'hidden',
                panelFull ? '' : 'lg:border-r',
                chatCentred && !panelOn && 'lg:px-[max(0px,calc(50%-21rem))]',
            ]"
            data-test="panel"
        >
            <div
                class="hidden items-center gap-1 border-b p-2 lg:flex"
                :style="besideChat"
            >
                <div
                    class="grid flex-1 grid-cols-2 rounded-md bg-muted p-0.5 text-sm"
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
                <Button
                    v-if="!designing && !codeOnScreen"
                    variant="ghost"
                    size="icon"
                    class="size-9 shrink-0 text-muted-foreground"
                    :aria-pressed="chatFull"
                    :aria-label="
                        chatFull
                            ? 'Show the app beside the chat'
                            : 'Chat on the whole screen'
                    "
                    :title="
                        chatFull
                            ? 'Show the app beside the chat'
                            : 'Chat on the whole screen'
                    "
                    data-test="chat-full"
                    @click="chat.full = !chatFull"
                >
                    <component
                        :is="chatFull ? Minimize2 : Maximize2"
                        class="size-4"
                    />
                </Button>
            </div>

            <DesignPanel
                v-if="designing"
                class="flex-1"
                :project-id="project.id"
                :preview="preview"
                :edits="edits"
                :state="app"
            />

            <section
                v-else
                :class="
                    panelOn
                        ? 'grid min-h-0 flex-1 grid-rows-[minmax(0,1fr)_auto]'
                        : 'flex min-h-0 flex-1 flex-col'
                "
                :style="
                    panelOn
                        ? {
                              gridTemplateColumns: listOn
                                  ? `${SIDES.left} minmax(0, 1fr) ${SIDES.right}`
                                  : `minmax(0, 1fr) ${SIDES.right}`,
                          }
                        : undefined
                "
                data-test="conversation"
            >
                <Transition
                    enter-active-class="transition duration-panel ease-settle"
                    enter-from-class="opacity-0 -translate-x-2"
                >
                    <ChatList
                        v-if="listOn"
                        class="row-span-2"
                        :project-id="project.id"
                        :chats="changes"
                        :current="change?.featureRequest.id ?? null"
                    />
                </Transition>

                <ChangeThread
                    v-if="change"
                    :change="change"
                    :roomy="chatCentred"
                    @full="codeFull = $event"
                    @sides="threadSides = $event"
                />

                <div v-else class="min-h-0 flex-1 overflow-y-auto p-4">
                    <div
                        v-if="changes.length === 0"
                        class="flex h-full flex-col justify-end gap-3 pb-2"
                        data-test="chat-empty"
                    >
                        <p class="text-lg font-semibold tracking-tight">
                            What should your app do next?
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="suggestion in suggestions"
                                :key="suggestion"
                                type="button"
                                class="min-h-11 rounded-full border px-3 text-sm text-muted-foreground select-none hover:border-foreground/30 hover:text-foreground sm:min-h-8"
                                @click="suggest(suggestion)"
                            >
                                {{ suggestion }}
                            </button>
                        </div>
                    </div>

                    <div
                        v-else
                        class="-mx-2 space-y-4"
                        data-test="project-changes"
                    >
                        <div
                            v-if="filters.length > 2"
                            class="sticky -top-4 z-10 -mt-4 bg-background pt-4 after:pointer-events-none after:absolute after:inset-x-0 after:top-full after:h-4 after:bg-linear-to-b after:from-background after:to-transparent"
                        >
                            <div
                                ref="filterRow"
                                :class="[
                                    'flex [scrollbar-width:none] gap-1.5 overflow-x-auto px-2 pb-2',
                                    filterFade,
                                ]"
                                role="group"
                                aria-label="Show"
                                data-test="change-filters"
                                @scroll.passive="measureFilters"
                            >
                                <button
                                    v-for="pill in filters"
                                    :key="pill.filter"
                                    type="button"
                                    :aria-pressed="shown.filter === pill.filter"
                                    :class="[
                                        'flex min-h-9 shrink-0 items-center gap-1.5 rounded-full border px-3 text-xs select-none sm:min-h-7',
                                        shown.filter === pill.filter
                                            ? 'border-foreground bg-foreground text-background'
                                            : 'text-muted-foreground hover:border-foreground/30 hover:text-foreground',
                                    ]"
                                    :data-test="`change-filter-${pill.filter}`"
                                    @click="shown.filter = pill.filter"
                                >
                                    {{ pill.label }}
                                    <span
                                        v-if="pill.filter !== 'all'"
                                        class="tabular-nums opacity-70"
                                        >{{ pill.count }}</span
                                    >
                                </button>
                            </div>
                        </div>
                        <section
                            v-for="(group, index) in thread"
                            :key="`${index}-${group.label}`"
                            :aria-label="group.label"
                        >
                            <h3
                                class="px-2 pb-1 text-xs font-medium text-muted-foreground"
                            >
                                {{ group.label }}
                            </h3>
                            <ol>
                                <li
                                    v-for="item in group.items"
                                    :key="item.id"
                                    :data-test="`change-${item.state}`"
                                    class="group relative flex items-start"
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
                                        :class="[
                                            'flex min-h-11 min-w-0 flex-1 items-start gap-2.5 rounded-md px-2 select-none hover:bg-muted/60',
                                            chatCentred ? 'py-4' : 'py-2',
                                            item.dismissable && 'pr-11',
                                        ]"
                                    >
                                        <component
                                            :is="states[item.state].icon"
                                            :class="[
                                                'mt-0.5 size-4 shrink-0',
                                                // The heading says these wait;
                                                // one amber word per row is enough.
                                                item.state === 'waiting'
                                                    ? 'text-muted-foreground'
                                                    : states[item.state].tone,
                                            ]"
                                            :aria-label="
                                                states[item.state].label
                                            "
                                        />
                                        <span class="min-w-0 flex-1">
                                            <span
                                                :class="[
                                                    'line-clamp-2 text-sm break-words',
                                                    item.state === 'waiting'
                                                        ? 'font-medium'
                                                        : 'text-muted-foreground',
                                                    item.state === 'undone' &&
                                                        'line-through',
                                                ]"
                                                >{{ item.prompt }}</span
                                            >
                                            <!-- The question I am asking,
                                                 so the owner can answer it
                                                 from here. A change to try
                                                 says so on the right. -->
                                            <span
                                                v-if="
                                                    item.state === 'waiting' &&
                                                    item.asks
                                                "
                                                class="mt-0.5 line-clamp-2 text-xs break-words text-muted-foreground"
                                                data-test="change-next-step"
                                                >{{
                                                    item.question ??
                                                    'I have a question for you.'
                                                }}</span
                                            >
                                        </span>
                                        <span
                                            v-if="item.state === 'waiting'"
                                            class="mt-0.5 shrink-0 text-xs font-medium text-amber-600 dark:text-amber-400"
                                            >{{
                                                item.asks ? 'Answer' : 'Review'
                                            }}</span
                                        >
                                    </Link>
                                    <!-- On a pointer it shows on hover, so
                                         15 rows are not 15 buttons; on touch
                                         it is always there. -->
                                    <button
                                        v-if="item.dismissable"
                                        type="button"
                                        :class="[
                                            'absolute right-0 flex size-11 items-center justify-center rounded-md text-muted-foreground select-none hover:bg-muted hover:text-foreground sm:size-9',
                                            chatCentred ? 'top-1.5' : 'top-0',
                                            item.state === 'dismissed'
                                                ? ''
                                                : 'opacity-0 group-hover:opacity-100 focus-visible:opacity-100 pointer-coarse:opacity-100',
                                        ]"
                                        :aria-label="
                                            item.state === 'dismissed'
                                                ? 'Bring back'
                                                : 'Not needed anymore'
                                        "
                                        :title="
                                            item.state === 'dismissed'
                                                ? 'Bring back'
                                                : 'Not needed anymore'
                                        "
                                        :data-test="`change-dismiss-${item.id}`"
                                        @click="dismiss(item)"
                                    >
                                        <component
                                            :is="
                                                item.state === 'dismissed'
                                                    ? Undo2
                                                    : X
                                            "
                                            class="size-4"
                                        />
                                    </button>
                                </li>
                            </ol>
                        </section>
                    </div>
                    <div ref="threadEnd" />
                </div>

                <Form
                    v-bind="composerForm"
                    :options="{ preserveState: true }"
                    :class="[
                        'p-3',
                        panelOn && 'px-[max(0.75rem,calc(50%-21rem))]',
                        listOn && 'col-start-2',
                    ]"
                    reset-on-success
                    v-slot="{ errors, processing }"
                    @success="
                        clearImages();
                        settleComposer();
                    "
                >
                    <div
                        :class="[
                            'rounded-xl border bg-background shadow-xs focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50',
                            dragging && 'border-ring ring-[3px] ring-ring/50',
                        ]"
                        data-test="composer-box"
                        @dragover.prevent="dragging = true"
                        @dragleave.self="dragging = false"
                        @drop.prevent="dropImages"
                    >
                        <Label for="prompt" class="sr-only"
                            >What should your app do next?</Label
                        >
                        <textarea
                            id="prompt"
                            ref="composer"
                            name="prompt"
                            :rows="composerOpen ? 3 : 1"
                            required
                            :class="[
                                'block w-full resize-none bg-transparent px-3 text-base outline-none placeholder:text-muted-foreground md:text-sm',
                                composerOpen ? 'pt-3' : 'py-2.5',
                            ]"
                            :placeholder="
                                continuing
                                    ? 'Reply or ask for more…'
                                    : change
                                      ? 'Ask for a new change…'
                                      : 'Ask for a change…'
                            "
                            @keydown.enter.exact="sendOnEnter"
                            @keydown.enter.meta.prevent="send"
                            @keydown.enter.ctrl.prevent="send"
                            @focus="composerOpen = true"
                            @blur="settleComposer"
                            @paste="pasteImages"
                        />
                        <ul
                            v-if="images.length > 0"
                            class="flex flex-wrap gap-2 px-3 pt-2"
                            data-test="composer-images"
                        >
                            <li
                                v-for="(image, index) in images"
                                :key="image.url"
                                class="relative"
                            >
                                <img
                                    :src="image.url"
                                    :alt="image.file.name"
                                    class="size-14 rounded-md border object-cover"
                                />
                                <button
                                    type="button"
                                    class="absolute -top-1.5 -right-1.5 flex size-6 items-center justify-center rounded-full border bg-background text-muted-foreground shadow-xs after:absolute after:-inset-2.5 hover:text-foreground"
                                    :aria-label="`Remove ${image.file.name}`"
                                    @click="removeImage(index)"
                                >
                                    <X class="size-3.5" />
                                </button>
                            </li>
                        </ul>
                        <input
                            ref="imageInput"
                            type="file"
                            name="images[]"
                            multiple
                            accept="image/png,image/jpeg,image/webp,image/gif"
                            class="hidden"
                            data-test="composer-image-input"
                            @change="
                                addImages(
                                    Array.from(
                                        ($event.target as HTMLInputElement)
                                            .files ?? [],
                                    ),
                                )
                            "
                        />
                        <div
                            v-show="
                                composerOpen || errors.prompt || images.length
                            "
                            class="flex items-center justify-between gap-1 p-2"
                        >
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-11 shrink-0 text-muted-foreground sm:size-8"
                                aria-label="Attach a picture"
                                title="Attach a picture"
                                :disabled="images.length >= maxImages"
                                data-test="composer-attach"
                                @mousedown.prevent
                                @click="imageInput?.click()"
                            >
                                <ImagePlus class="size-4" />
                            </Button>
                            <span
                                class="pl-1 text-xs text-muted-foreground"
                                data-test="composer-hint"
                                >{{
                                    continuing
                                        ? 'Continues this chat'
                                        : change
                                          ? 'Starts a new change'
                                          : ''
                                }}<span class="hidden sm:inline"
                                    >{{ change ? ' · ' : '' }}Shift + Enter for
                                    a new line</span
                                ></span
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
                    <InputError
                        :message="
                            errors.images ??
                            Object.entries(errors).find(([key]) =>
                                key.startsWith('images.'),
                            )?.[1]
                        "
                        class="mt-1"
                    />
                </Form>

                <!-- Always there with an open change, so its plan and code
                     can move in as soon as the screen is wide enough -->
                <Transition
                    enter-active-class="transition duration-panel ease-settle"
                    enter-from-class="opacity-0 translate-x-2"
                >
                    <BesidePanel
                        v-if="change"
                        v-show="panelOn"
                        :ready="threadSides"
                        :class="[
                            'row-span-2 row-start-1',
                            listOn ? 'col-start-3' : 'col-start-2',
                        ]"
                    />
                </Transition>
            </section>
            <div
                role="separator"
                aria-orientation="vertical"
                aria-label="Panel width"
                :aria-valuenow="panelWidth.width.value"
                :aria-valuemin="panelWidth.min"
                tabindex="0"
                title="Drag to resize. Double-click to reset."
                class="absolute inset-y-0 -right-1.5 z-10 hidden w-3 cursor-col-resize touch-none after:absolute after:inset-y-0 after:left-1/2 after:w-px after:-translate-x-1/2 after:bg-transparent after:transition-colors hover:after:bg-primary focus-visible:outline-none focus-visible:after:bg-primary"
                :class="[
                    panelWidth.resizing.value ? 'after:bg-primary' : '',
                    panelFull ? '' : 'lg:block',
                ]"
                data-test="panel-resize"
                @pointerdown="panelWidth.start"
                @keydown="panelWidth.nudge"
                @dblclick="panelWidth.reset"
            />
        </aside>

        <main
            :class="[
                'min-h-0 flex-col gap-2 p-2 lg:p-3',
                pane === 'app' ? 'flex' : 'hidden',
                // Hidden, not removed, so the app does not reload.
                panelFull ? 'lg:hidden' : 'lg:flex',
            ]"
            data-test="app-pane"
        >
            <!-- The designer shows each change as it is made. -->
            <p
                v-if="preview?.updating && !designing"
                class="text-center text-xs text-muted-foreground"
                data-test="preview-updating"
            >
                Putting your change in place…
            </p>
            <Form
                v-else-if="preview?.status === 'ready' && preview.error"
                v-bind="ProjectPreviewController.store.form(project.id)"
                :options="{ preserveScroll: true, preserveState: true }"
                v-slot="{ processing }"
                class="flex items-center justify-center gap-2 text-xs text-destructive"
                data-test="preview-behind"
            >
                {{ preview.error }}
                <Button
                    variant="outline"
                    size="sm"
                    class="h-11 sm:h-7"
                    :disabled="processing"
                    >Start again</Button
                >
            </Form>
            <div class="min-h-0 flex-1">
                <AppPreview
                    :project-id="project.id"
                    :preview="preview"
                    :state="app"
                />
            </div>
            <DesignPanel
                v-if="designing && pane === 'app'"
                class="h-[45svh] shrink-0 rounded-lg border lg:hidden"
                :project-id="project.id"
                :preview="preview"
                :edits="edits"
                :state="app"
            />
        </main>
    </div>
</template>
