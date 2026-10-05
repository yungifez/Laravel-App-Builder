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
    ArrowRight,
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
    ListChecks,
    Lock,
    MessageSquare,
    Monitor,
    MousePointerClick,
    RotateCw,
    Share2,
    ShieldCheck,
    Smartphone,
    Tablet,
} from '@lucide/vue';
import { useResizeObserver } from '@vueuse/core';
import { useScreen } from '@/composables/useScreen';
import {
    computed,
    nextTick,
    onMounted,
    onUnmounted,
    reactive,
    ref,
    watch,
    watchEffect,
} from 'vue';
import { toast } from 'vue-sonner';
import FeatureRequestController from '@/actions/App/Http/Controllers/FeatureRequestController';
import FeatureRequestDismissalController from '@/actions/App/Http/Controllers/FeatureRequestDismissalController';
import FeatureRequestFollowUpController from '@/actions/App/Http/Controllers/FeatureRequestFollowUpController';
import FeatureRequestPreviewController from '@/actions/App/Http/Controllers/FeatureRequestPreviewController';
import PreviewController from '@/actions/App/Http/Controllers/PreviewController';
import PreviewProblemFixController from '@/actions/App/Http/Controllers/PreviewProblemFixController';
import ProjectExperimentController from '@/actions/App/Http/Controllers/ProjectExperimentController';
import ProjectPreviewController from '@/actions/App/Http/Controllers/ProjectPreviewController';
import AppData from '@/components/AppData.vue';
import AppEmails from '@/components/AppEmails.vue';
import AppHappenings from '@/components/AppHappenings.vue';
import AppProblems from '@/components/AppProblems.vue';
import AppSchedule from '@/components/AppSchedule.vue';
import AppPreview from '@/components/AppPreview.vue';
import BesidePanel from '@/components/BesidePanel.vue';
import ChangeCopySwitch from '@/components/ChangeCopySwitch.vue';
import ChangeThread from '@/components/ChangeThread.vue';
import ChatList from '@/components/ChatList.vue';
import SignInAs from '@/components/SignInAs.vue';
import DesignPanel from '@/components/DesignPanel.vue';
import IdeaMenu from '@/components/ideas/IdeaMenu.vue';
import StartIdeaDialog from '@/components/ideas/StartIdeaDialog.vue';
import UseIdeaDialog from '@/components/ideas/UseIdeaDialog.vue';
import InputError from '@/components/InputError.vue';
import ProjectDetails from '@/components/ProjectDetails.vue';
import NotificationBell from '@/components/NotificationBell.vue';
import PublishPanel from '@/components/PublishPanel.vue';
import OwnToolDialog from '@/components/OwnToolDialog.vue';
import RenameAppDialog from '@/components/RenameAppDialog.vue';
import ShareAppDialog from '@/components/ShareAppDialog.vue';
import ServicesDialog from '@/components/ServicesDialog.vue';
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
import { useFormFill } from '@/composables/useFormFill';
import { usePanelWidth } from '@/composables/usePanelWidth';
import { morph } from '@/lib/morph';
import { when } from '@/lib/when';
import { show as showPreview } from '@/routes/previews';
import { download, index, show as showProject } from '@/routes/projects';
import { index as developers } from '@/routes/projects/developers';
import { show as showUnderstanding } from '@/routes/projects/understanding';
import type {
    ChangeDetail,
    ChangeItem,
    ChangeState,
    Device,
    EditorPreview,
    FirstVersion,
    Idea,
    Ideas,
    InspectedElement,
    ProjectCommit,
    PreviewPerson,
    ProjectSummary,
    ProjectPublishing,
    AppService,
    ProjectTelemetry,
    SentEmail,
    SentNotice,
    AppColor,
    AppHappenings as AppHappened,
    AppPage,
    AppProblem,
    SavedRows,
    ScheduledTask,
    StoredFile,
    SavedTable,
    VisualEditSummary,
} from '@/types';

const props = defineProps<{
    project: ProjectSummary;
    changes: ChangeItem[];
    change: ChangeDetail | null;
    preview: EditorPreview | null;
    first_version: FirstVersion | null;
    design: boolean;
    element?: InspectedElement | null;
    edits: VisualEditSummary[];
    ideas: Ideas;
    history: ProjectCommit[];
    telemetry: ProjectTelemetry;
    publishing: ProjectPublishing;
    services: AppService[];
    emails?: SentEmail[];
    notices?: SentNotice[];
    people?: PreviewPerson[] | null;
    problems?: AppProblem[];
    data?: SavedTable[] | null;
    rows?: SavedRows | null;
    schedule?: ScheduledTask[] | null;
    happenings?: AppHappened;
    files?: StoredFile[] | null;
    pages?: AppPage[] | null;
    colors?: AppColor[];
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
// Until a first version is kept, the pane says how it is going, so it is
// open from the start.
// An app that could not start stays open too, so the pane says why.
const appOpen = computed(
    () =>
        props.first_version !== null ||
        (props.preview !== null &&
            ['starting', 'ready', 'failed'].includes(props.preview.status)),
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
const desktop = useScreen('(min-width: 1024px)');
const wide = useScreen('(min-width: 1280px)');
const threadSides = ref(false);
const threadStopped = ref(false);
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

// While the owner designs a change they are trying, the designer works on
// its copy instead of the app; set below, once the change is known.
const designedCopy = ref<EditorPreview | null>(null);

const app = useAppPreview({
    projectId: () => props.project.id,
    preview: () => designedCopy.value ?? props.preview,
    element: () => props.element,
    edits: () => props.edits,
    colors: () => props.colors ?? [],
    designing,
});

// Beside the app, what it does behind the page: the emails it sent, the
// problems it ran into, the data it saved and what it runs on its own.
type Behind = 'app' | 'emails' | 'problems' | 'data' | 'schedule' | 'happened';
const showing = ref<Behind>('app');

// Looked for every few seconds while the app runs, so what is new is
// counted as soon as it happens.
const behindPoll = usePoll(
    5000,
    { only: ['emails', 'problems'] },
    { autoStart: false },
);

watch(
    () => app.running && !app.lost,
    (running) => (running ? behindPoll.start() : behindPoll.stop()),
    { immediate: true },
);
watch(
    showing,
    (value) =>
        value !== 'app' &&
        router.reload({
            only:
                value === 'data'
                    ? ['data', 'files']
                    : value === 'emails'
                      ? ['emails', 'notices']
                      : value === 'happened'
                        ? ['happenings']
                        : [value],
        }),
);

// Notices are read by running the app, so only while the owner looks.
const noticesPoll = usePoll(5000, { only: ['notices'] }, { autoStart: false });

watch(
    () => showing.value === 'emails' && app.running && !app.lost,
    (looking) => (looking ? noticesPoll.start() : noticesPoll.stop()),
);

// What the app did follows along while the owner looks at it.
const happenedPoll = usePoll(
    4000,
    { only: ['happenings'] },
    { autoStart: false },
);

watch(
    () => showing.value === 'happened' && app.running && !app.lost,
    (looking) => (looking ? happenedPoll.start() : happenedPoll.stop()),
);

// Saved data is read by running the app, so only while the owner looks.
const dataPoll = usePoll(
    5000,
    { only: ['data', 'files'] },
    { autoStart: false },
);

watch(
    () => showing.value === 'data' && app.running && !app.lost,
    (looking) => (looking ? dataPoll.start() : dataPoll.stop()),
);

// What the owner has seen of each, kept in this browser, so a tab counts
// only what is new.
function seen(name: string) {
    const key = `builder:seen-${name}:${props.project.id}`;
    const value = ref<string | null>(null);

    try {
        value.value = localStorage.getItem(key);
    } catch {
        // Only a convenience.
    }

    watch(value, (now) => {
        try {
            if (now !== null) {
                localStorage.setItem(key, now);
            }
        } catch {
            // Only a convenience.
        }
    });

    return value;
}

// The newest email opened, and the time of the newest problem looked at.
const seenEmail = seen('email');
const seenProblem = seen('problem');

const unseenEmails = computed(() => {
    const emails = props.emails ?? [];
    const at = emails.findIndex((email) => email.id === seenEmail.value);

    return at === -1 ? emails.length : at;
});

const unseenProblems = computed(
    () =>
        (props.problems ?? []).filter(
            (problem) =>
                ['new', 'back'].includes(problem.state) &&
                (problem.last_at ?? '') > (seenProblem.value ?? ''),
        ).length,
);

watch(
    () => [showing.value, props.emails?.[0]?.id, props.problems?.[0]] as const,
    ([value, newestEmail, newestProblem]) => {
        if (value === 'emails' && newestEmail !== undefined) {
            seenEmail.value = newestEmail;
        }

        if (value === 'problems' && newestProblem?.last_at) {
            seenProblem.value = newestProblem.last_at;
        }
    },
);

// What the app does behind the page is told as it happens, so the owner
// sees an email go out or a problem come up while they try the app, not
// only when they think to open a tab. What was there when the page opened
// is not news.
watch(
    () => props.emails,
    (emails, before) => {
        if (
            !emails?.length ||
            before === undefined ||
            showing.value === 'emails'
        ) {
            return;
        }

        const known = emails.findIndex((email) => email.id === before[0]?.id);
        const fresh =
            known === -1 ? (before.length ? emails.length : 0) : known;

        if (fresh > 0) {
            toast(
                fresh === 1
                    ? 'Your app sent an email'
                    : `Your app sent ${fresh} emails`,
                {
                    description: emails[0].subject || undefined,
                    duration: 10000,
                    action: {
                        label: 'Read',
                        onClick: () => (showing.value = 'emails'),
                    },
                },
            );
        }
    },
);

watch(
    () => props.problems,
    (problems, before) => {
        if (before === undefined || showing.value === 'problems') {
            return;
        }

        const latest = before.reduce(
            (at, problem) =>
                (problem.last_at ?? '') > at ? (problem.last_at ?? '') : at,
            '',
        );
        const fresh = (problems ?? []).find(
            (problem) =>
                ['new', 'back'].includes(problem.state) &&
                (problem.last_at ?? '') > latest,
        );

        if (fresh) {
            // Fixing it is one click from the moment it happened.
            toast.error('Your app ran into a problem', {
                description: fresh.words,
                duration: 10000,
                action: {
                    label: 'Fix it',
                    onClick: () =>
                        router.post(
                            PreviewProblemFixController.store.url(
                                props.project.id,
                            ),
                            { problem: fresh.id },
                            {
                                onError: (errors) =>
                                    toast.error(Object.values(errors)[0]),
                            },
                        ),
                },
                cancel: {
                    label: 'See it',
                    onClick: () => (showing.value = 'problems'),
                },
            });
        }
    },
);

const showingTabs = computed(() => [
    { key: 'app' as const, label: 'App', count: 0 },
    { key: 'emails' as const, label: 'Messages', count: unseenEmails.value },
    {
        key: 'problems' as const,
        label: 'Problems',
        count: unseenProblems.value,
    },
    { key: 'data' as const, label: 'Saved data', count: 0 },
    { key: 'schedule' as const, label: 'Schedule', count: 0 },
    { key: 'happened' as const, label: 'What happened', count: 0 },
]);

// Any page of the app is one pick away, as in a browser's address bar,
// read from the app when the owner looks.
function openPage(path: string): void {
    if (copyFrameOnShow.value) {
        openInChangeCopy(changeCopyOrigin.value + path);

        return;
    }

    showing.value = 'app';
    app.follow(path);
}

// A link from beside the app, in an email or a sign-in, opens its page in
// the app.
function openInApp(href: string): void {
    if (copyFrameOnShow.value) {
        openInChangeCopy(href);

        return;
    }

    showing.value = 'app';
    app.visit(href);
}

// A change waiting for the owner shows in the app pane, so they see what
// they are deciding on without opening anything. They can look at their
// app without it, and its copy can be started again once it has stopped.
const decidingOn = computed(() =>
    props.change?.featureRequest.can_accept &&
    // A copy started before copies could be designed shows the app instead.
    (!designing.value || props.change.preview?.editable !== false)
        ? props.change
        : null,
);
const withoutChange = ref(false);
watch(
    () => props.change?.featureRequest.id,
    () => (withoutChange.value = false),
);
const changeCopy = computed(() => {
    const copy = decidingOn.value?.preview;

    return copy &&
        !withoutChange.value &&
        (copy.status === 'ready' || copy.status === 'starting')
        ? copy
        : null;
});

// The copy stopped under a preview still marked as running, as when the
// machine restarts; it says so, and the owner opens it again. The page can
// be drawn before it listens, so it asks the copy once it does.
const changeCopyFrame = ref<HTMLIFrameElement | null>(null);
const changeCopyLost = ref(false);

// The pages of the copy the owner went through, so back, forward and the
// page list move the copy as they move the app.
const copyVisited = ref<string[]>(['/']);
const copyVisitedAt = ref(0);
watch(
    () => changeCopy.value?.id,
    () => {
        changeCopyLost.value = false;
        copyVisited.value = ['/'];
        copyVisitedAt.value = 0;
    },
);

function onChangeCopyMessage(event: MessageEvent): void {
    const copy = changeCopy.value;

    if (
        copy === null ||
        event.source !== changeCopyFrame.value?.contentWindow ||
        event.origin !== new URL(copy.url).origin ||
        event.data?.builder !== true
    ) {
        return;
    }

    if (event.data.type === 'lost') {
        changeCopyLost.value = true;
    }

    // A page the owner went to, not one back or forward moved to.
    const path = event.data.path;

    if (
        (event.data.type === 'ready' || event.data.type === 'page') &&
        typeof path === 'string' &&
        path !== copyVisited.value[copyVisitedAt.value]
    ) {
        copyVisited.value = [
            ...copyVisited.value.slice(0, copyVisitedAt.value + 1),
            path,
        ];
        copyVisitedAt.value = copyVisited.value.length - 1;
    }
}

function browseCopy(by: -1 | 1): void {
    const to = copyVisited.value[copyVisitedAt.value + by];

    if (to !== undefined) {
        copyVisitedAt.value += by;
        openInChangeCopy(changeCopyOrigin.value + to);
    }
}

onMounted(() => {
    window.addEventListener('message', onChangeCopyMessage);

    if (changeCopy.value !== null) {
        changeCopyFrame.value?.contentWindow?.postMessage(
            { builder: true, type: 'hello' },
            new URL(changeCopy.value.url).origin,
        );
    }
});
onUnmounted(() => window.removeEventListener('message', onChangeCopyMessage));

// While the owner tries a change, the tools beside the app work on its
// copy: its emails, problems, saved data and schedule. The page address
// names the copy, so every reload of them reads it.
const changeCopyOrigin = computed(() =>
    changeCopy.value === null ? null : new URL(changeCopy.value.url).origin,
);
const changeCopyRuns = computed(
    () => changeCopy.value?.status === 'ready' && !changeCopyLost.value,
);
const copy = computed(() =>
    changeCopyRuns.value ? decidingOn.value!.featureRequest.id : null,
);
watchEffect(() => {
    const preview = changeCopy.value;

    designedCopy.value =
        designing.value && preview !== null && preview.editable
            ? {
                  id: preview.id,
                  status: preview.status,
                  error: preview.error,
                  origin: preview.origin,
                  revision: preview.revision,
                  updating: preview.updating,
              }
            : null;
});
// The copy's own frame shows it unless the designer has it.
const copyFrameOnShow = computed(
    () => copy.value !== null && designedCopy.value === null,
);
watch(
    copy,
    (value) => {
        if (typeof window === 'undefined') {
            return;
        }

        const url = new URL(window.location.href);

        if (value === null) {
            url.searchParams.delete('copy');
        } else {
            url.searchParams.set('copy', value);
        }

        if (url.href !== window.location.href) {
            window.history.replaceState(window.history.state, '', url);
            router.reload({
                only: [
                    'emails',
                    'problems',
                    'people',
                    'pages',
                    ...(showing.value === 'data'
                        ? ['data', 'files']
                        : showing.value === 'schedule'
                          ? ['schedule']
                          : showing.value === 'emails'
                            ? ['notices']
                            : showing.value === 'happened'
                              ? ['happenings']
                              : []),
                ],
            });
        }
    },
    { immediate: true },
);

function openInChangeCopy(href: string): void {
    showing.value = 'app';

    if (changeCopyFrame.value !== null) {
        changeCopyFrame.value.src = href;
    }
}

// Back, forward, reload and the page on show, for the app or for the copy
// of the change the owner tries.
const browsing = computed(() =>
    !copyFrameOnShow.value
        ? {
              path: app.path,
              canGoBack: app.canGoBack,
              canGoForward: app.canGoForward,
              back: app.back,
              forward: app.forward,
              reload: app.reload,
          }
        : {
              path: copyVisited.value[copyVisitedAt.value] ?? '/',
              canGoBack: copyVisitedAt.value > 0,
              canGoForward: copyVisitedAt.value < copyVisited.value.length - 1,
              back: () => browseCopy(-1),
              forward: () => browseCopy(1),
              reload: () =>
                  openInChangeCopy(
                      changeCopyOrigin.value +
                          (copyVisited.value[copyVisitedAt.value] ?? '/'),
                  ),
          },
);

// One tap fills the form on the page on show with example details, in
// the app or in the copy of the change the owner tries.
const formFill = useFormFill(() =>
    copyFrameOnShow.value
        ? { frame: changeCopyFrame.value, origin: changeCopyOrigin.value }
        : { frame: app.frame, origin: props.preview?.origin ?? null },
);
// While the owner designs, a click in the app picks a part instead.
const canFill = computed(() => formFill.empty.value > 0 && !designing.value);

function fillForm(): void {
    showing.value = 'app';
    formFill.fill();
}

// The bar over the app shows while the app runs.
// The template is not the owner's app: nothing to browse before a first
// version, unless a change shows its own copy.
const toolbarShown = computed(
    () =>
        app.running &&
        !app.lost &&
        props.preview !== null &&
        (props.first_version === null || changeCopy.value !== null),
);

function changeCopySwitch(change: NonNullable<typeof decidingOn.value>) {
    return {
        featureRequestId: change.featureRequest.id,
        withChange: changeCopy.value !== null,
        copyStarted:
            change.preview?.status === 'ready' ||
            change.preview?.status === 'starting',
    };
}

// A new tab opens the page on show, of the copy while the owner tries
// one, so it carries on from where they are.
const openInTab = computed(() => {
    const target = changeCopyRuns.value ? changeCopy.value : props.preview;

    return target
        ? showPreview(target.id, { query: { to: browsing.value.path } }).url
        : null;
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

// Picking a screen size is asking to see the app at that size, so the app
// comes into view when the chat or the code had the screen.
function showAt(device: typeof app.device): void {
    showing.value = 'app';
    app.device = device;
    pane.value = 'app';
    chat.full = false;
    codeFull.value = false;
}

// The address says when the design side is open, so reloading the page
// opens it again instead of dropping the owner back in the chat.
function keepPanelInAddress(): void {
    const url = new URL(window.location.href);

    if ((url.searchParams.get('design') === '1') === designing.value) {
        return;
    }

    if (designing.value) {
        url.searchParams.set('design', '1');
    } else {
        url.searchParams.delete('design');
    }

    window.history.replaceState(window.history.state, '', url);
}

watch(panel, keepPanelInAddress);

// A request sent before the switch, such as opening the app again, comes
// back with the address it was sent from.
onUnmounted(router.on('navigate', keepPanelInAddress));

// Switching what the screen shows morphs from one layout to the next.
function show(to: 'chat' | 'design'): void {
    morph(() => (panel.value = to));
}

function showPhone(view: 'chat' | 'design' | 'app'): void {
    morph(() => (phoneView.value = view));
}

function toggleChatFull(): void {
    morph(() => (chat.full = !chatFull.value));
}

function codeOnWholeScreen(full: boolean): void {
    if (codeFull.value !== full) {
        morph(() => (codeFull.value = full));
    }
}

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
const sharing = ref(false);
const usingOwnTool = ref(false);
const connecting = ref(false);
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
// A stopped change asked again later is the same ask: only the latest
// counts, so no filter says a change stopped while its ask goes on.
const askedAgain = computed(() => {
    const newestFirst = props.changes;

    return new Set(
        newestFirst
            .filter(
                (item, index) =>
                    item.state === 'stopped' &&
                    newestFirst
                        .slice(0, index)
                        .some(
                            (later) =>
                                later.prompt.trim() === item.prompt.trim(),
                        ),
            )
            .map((item) => item.id),
    );
});
const inFilter = (item: ChangeItem, filter: Filter): boolean => {
    if (askedAgain.value.has(item.id)) {
        return false;
    }

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

    // Finished changes go in the order they finished, the time their day
    // is named by, so a day is never said twice. The sort keeps the order
    // of the asks for changes finished at the same time.
    const finished = (item: ChangeItem) =>
        item.updated_at === null ? 0 : Date.parse(item.updated_at);
    const history = oldestFirst
        .filter((item) => !open(item))
        .sort((a, b) => finished(a) - finished(b));

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
    const toTry = oldestFirst.filter(
        (item) => item.state === 'waiting' && !item.asks,
    );
    // A question holds its change up, so questions sit last, next to the
    // box the owner answers in.
    const toAnswer = oldestFirst.filter(
        (item) => item.state === 'waiting' && item.asks,
    );

    return [
        ...groups,
        ...(working.length ? [{ label: 'Working on it', items: working }] : []),
        ...(toTry.length
            ? [
                  {
                      label: count(toTry.length, 'change') + ' to try',
                      items: toTry,
                  },
              ]
            : []),
        ...(toAnswer.length
            ? [
                  {
                      label: count(toAnswer.length, 'question') + ' to answer',
                      items: toAnswer,
                  },
              ]
            : []),
    ];
});
const threadEnd = ref<HTMLElement | null>(null);

function count(amount: number, noun: string): string {
    return `${amount} ${noun}${amount === 1 ? '' : 's'}`;
}

const waiting = computed(
    () => props.changes.filter((change) => change.state === 'waiting').length,
);

const working = computed(() =>
    props.changes.some((change) => change.state === 'working'),
);

// A first version still being checked is no longer working in the chat, yet
// the pane waits on it, so it keeps asking too.
const { start, stop } = usePoll(
    4000,
    { only: ['changes', 'first_version'] },
    { autoStart: false },
);

watch(
    () => working.value || props.first_version?.state === 'making',
    (value) => (value ? start() : stop()),
    { immediate: true },
);

function toEnd(): void {
    nextTick(() => threadEnd.value?.scrollIntoView({ block: 'end' }));
}

onMounted(toEnd);
watch(() => props.changes.length, toEnd);
watch(panel, (value) => value === 'chat' && toEnd());

// Back from a change, the list opens where the owner left it: at the row
// they opened, not at the top.
watch(
    () => props.change?.featureRequest.id,
    (open, closed) => {
        if (open || !closed) {
            return;
        }

        nextTick(() => {
            const row = document.querySelector(`[data-change="${closed}"]`);

            if (row) {
                row.scrollIntoView({ block: 'center' });
            } else {
                toEnd();
            }
        });
    },
);

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
// A new ask builds on the newest change still waiting to be tried, so it
// starts from the app the owner last saw. Keeping it keeps both.
const notKept = computed(
    () =>
        props.changes.filter((item) => item.state === 'waiting' && !item.asks)
            .length,
);
const composerHint = computed(() => {
    if (continuing.value) {
        return 'Continues this chat';
    }

    if (notKept.value > 0) {
        return notKept.value === 1
            ? 'Builds on your change to try'
            : 'Builds on your newest change to try';
    }

    return props.change ? 'Starts a new change' : '';
});
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
                    <!-- On a phone the room goes to the name. -->
                    <ChevronDown
                        class="hidden size-4 shrink-0 opacity-60 sm:block"
                    />
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
                        >Your business</Link
                    >
                </DropdownMenuItem>
                <DropdownMenuItem
                    data-test="services-open"
                    @select="connecting = true"
                >
                    Payments and email…
                </DropdownMenuItem>
                <DropdownMenuItem
                    data-test="app-rename"
                    @select="renaming = true"
                >
                    Rename…
                </DropdownMenuItem>
                <DropdownMenuItem as-child>
                    <Link
                        :href="developers(project.id).url"
                        data-test="ask-developer-open"
                    >
                        Ask a developer
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem
                    data-test="details-open"
                    @select="detailsOpen = true"
                >
                    Details for your developer
                </DropdownMenuItem>
                <DropdownMenuItem
                    data-test="own-tool-open"
                    @select="usingOwnTool = true"
                >
                    Use my own Claude Code or Codex…
                </DropdownMenuItem>
                <!-- The code is the owner's to take to any developer. -->
                <DropdownMenuItem as-child>
                    <a
                        :href="download(project.id).url"
                        download
                        title="All its code, for you or any developer"
                        data-test="app-download"
                        >Download your app</a
                    >
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem as-child>
                    <Link :href="index()">All your apps</Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <!-- What guards the app, in sight while the owner works: each
             kept change adds tests that run on every later change. A
             phone has no room for it beside the app's name; the app menu
             leads there. -->
        <Link
            v-if="project.tests"
            :href="showUnderstanding(project.id)"
            :title="`${project.tests} ${project.tests === 1 ? 'test runs' : 'tests run'} on every change, so what works keeps working. See what they check.`"
            class="hidden h-9 shrink-0 items-center gap-1 rounded-md px-2 text-xs text-muted-foreground tabular-nums select-none hover:bg-muted hover:text-foreground sm:flex"
            data-test="app-guard"
        >
            <ShieldCheck class="size-3.5 text-green-600" />
            {{ project.tests }} {{ project.tests === 1 ? 'test' : 'tests' }}
        </Link>

        <IdeaMenu
            v-if="ideas.current"
            :project-id="project.id"
            :ideas="{ ...ideas, current: ideas.current }"
        />

        <div class="ml-auto flex shrink-0 items-center gap-1">
            <template v-if="toolbarShown">
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
                        @click="showAt(screen.key)"
                    >
                        <component :is="screen.icon" class="size-4" />
                    </button>
                </div>
                <!-- On a phone it sits beside the app's pages instead, so
                     the app's name keeps its room here. -->
                <div class="hidden sm:contents">
                    <SignInAs
                        :project-id="project.id"
                        :people="people"
                        :path="browsing.path"
                        :copy="copy"
                        @open="openInApp"
                    />
                </div>
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
                >
                    <!-- Starting takes a moment; say so at once -->
                    <LoaderCircle
                        v-if="processing"
                        class="size-4 animate-spin"
                    />
                    {{ processing ? 'Opening…' : 'Open my app' }}
                </Button>
            </Form>

            <NotificationBell />
            <!-- Others try the app before it goes online, as Lovable's
                 share link does. An idea is the owner's own, so not here,
                 and before a first version is kept there is no app yet. -->
            <Button
                v-if="!ideas.current && first_version === null"
                variant="outline"
                class="ml-1 h-11 select-none sm:h-9"
                aria-label="Share"
                data-test="share-open"
                @click="sharing = true"
            >
                <Share2 class="size-4" />
                <span class="hidden sm:inline">Share</span>
            </Button>
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
                v-else-if="first_version === null"
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
    <OwnToolDialog
        v-model:open="usingOwnTool"
        :project-id="project.id"
        :connected="project.own_tool.connected"
        :address="project.own_tool.address"
        :name="project.own_tool.name"
    />
    <ShareAppDialog
        v-model:open="sharing"
        :project-id="project.id"
        :share="project.share"
        :days="project.share_days"
    />
    <ServicesDialog
        v-model:open="connecting"
        :project-id="project.id"
        :services="services"
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
            <PublishPanel
                :project-id="project.id"
                :publishing="publishing"
                :tests="project.tests"
            />
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="detailsOpen">
        <DialogContent class="max-h-[85svh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>Details for your developer</DialogTitle>
                <DialogDescription>
                    What building this app has cost, and each change you kept.
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
            @click="showPhone(option.key)"
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
                    class="relative grid flex-1 grid-cols-2 rounded-md bg-muted p-0.5 text-sm"
                    role="tablist"
                    aria-label="Panel"
                >
                    <!-- One pill slides to the chosen tab. -->
                    <span
                        aria-hidden="true"
                        class="absolute inset-y-0.5 left-0.5 w-[calc(50%-0.125rem)] rounded bg-background shadow-sm transition-transform duration-base ease-snap"
                        :class="panel === 'design' && 'translate-x-full'"
                    />
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="panel === 'chat'"
                        :class="[
                            'relative flex min-h-11 items-center justify-center gap-2 rounded transition-colors duration-quick select-none sm:min-h-8',
                            panel === 'chat'
                                ? 'font-medium'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        data-test="panel-chat"
                        @click="show('chat')"
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
                            'relative flex min-h-11 items-center justify-center gap-2 rounded transition-colors duration-quick select-none sm:min-h-8',
                            panel === 'design'
                                ? 'font-medium'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        data-test="panel-design"
                        @click="show('design')"
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
                    @click="toggleChatFull"
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
                :preview="designedCopy ?? preview"
                :on-change="designedCopy !== null"
                :edits="edits"
                :state="app"
                :waiting="first_version !== null"
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

                <!-- A change and the list of changes arrive rather than cut in;
                     the one leaving goes at once. -->
                <Transition
                    enter-active-class="transition duration-base ease-settle"
                    enter-from-class="opacity-0 translate-y-1"
                >
                    <ChangeThread
                        v-if="change"
                        :change="change"
                        :roomy="chatCentred"
                        @full="codeOnWholeScreen"
                        @sides="threadSides = $event"
                        @stopped="threadStopped = $event"
                    />

                    <div v-else class="min-h-0 flex-1 overflow-y-auto p-4">
                        <div
                            v-if="changes.length === 0"
                            class="flex h-full flex-col justify-end gap-3 pb-2"
                            data-test="chat-empty"
                        >
                            <!-- In an idea, the owner is told where the
                                 changes go, so trying one feels safe. -->
                            <template v-if="ideas.current">
                                <p class="text-lg font-semibold tracking-tight">
                                    What should this idea try?
                                </p>
                                <p
                                    class="-mt-2 text-sm text-muted-foreground"
                                    data-test="chat-idea-promise"
                                >
                                    Changes you ask for here stay in
                                    {{ ideas.current.name }}. Your app stays as
                                    it is until you use the idea.
                                </p>
                            </template>
                            <template v-else>
                                <p class="text-lg font-semibold tracking-tight">
                                    What should your app do next?
                                </p>
                                <!-- What sets this apart, said once, before
                                     the first change: nothing reaches the app
                                     unchecked, and nothing stays unless kept. -->
                                <p
                                    class="-mt-2 text-sm text-muted-foreground"
                                    data-test="chat-promise"
                                >
                                    I check each change in your app before you
                                    see it. You keep it, or undo it any time.
                                </p>
                            </template>
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
                                        :aria-pressed="
                                            shown.filter === pill.filter
                                        "
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
                                        :data-change="item.id"
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
                                                    // So is a change the owner
                                                    // stopped: nothing went wrong.
                                                    item.state === 'waiting' ||
                                                    item.stopped_by_owner
                                                        ? 'text-muted-foreground'
                                                        : states[item.state]
                                                              .tone,
                                                ]"
                                                :aria-label="
                                                    item.stopped_by_owner
                                                        ? 'You stopped it'
                                                        : states[item.state]
                                                              .label
                                                "
                                            />
                                            <span class="min-w-0 flex-1">
                                                <span
                                                    :class="[
                                                        'line-clamp-2 text-sm break-words',
                                                        item.state === 'waiting'
                                                            ? 'font-medium'
                                                            : 'text-muted-foreground',
                                                        item.state ===
                                                            'undone' &&
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
                                                        item.state ===
                                                            'waiting' &&
                                                        item.asks
                                                    "
                                                    class="mt-0.5 line-clamp-2 text-xs break-words text-muted-foreground"
                                                    data-test="change-next-step"
                                                    >{{
                                                        item.question ??
                                                        'I have a question for you.'
                                                    }}</span
                                                >
                                                <!-- What sets a change apart
                                                 before it is opened: tests
                                                 that fail without it. -->
                                                <span
                                                    v-else-if="item.proved > 0"
                                                    class="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground"
                                                    data-test="change-proved"
                                                    ><ShieldCheck
                                                        class="size-3 text-green-600"
                                                    />{{
                                                        item.proved === 1
                                                            ? '1 test proves it works'
                                                            : `${item.proved} tests prove it works`
                                                    }}</span
                                                >
                                                <span
                                                    v-else-if="item.passing > 0"
                                                    class="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground"
                                                    data-test="change-passing"
                                                    ><ShieldCheck
                                                        class="size-3"
                                                    />{{
                                                        item.passing === 1
                                                            ? 'Its new test passes'
                                                            : `Its ${item.passing} new tests pass`
                                                    }}</span
                                                >
                                            </span>
                                            <!-- Amber only where the owner
                                                 holds a change up. -->
                                            <span
                                                v-if="item.state === 'waiting'"
                                                :class="[
                                                    'mt-0.5 shrink-0 text-xs font-medium',
                                                    item.asks
                                                        ? 'text-amber-600 dark:text-amber-400'
                                                        : 'text-muted-foreground group-hover:text-foreground',
                                                ]"
                                                >{{
                                                    item.asks ? 'Answer' : 'Try'
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
                                                chatCentred
                                                    ? 'top-1.5'
                                                    : 'top-0',
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
                </Transition>

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
                            'rounded-md border border-input bg-background focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50',
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
                                    class="absolute -top-1.5 -right-1.5 flex size-6 items-center justify-center rounded-full border bg-background text-muted-foreground after:absolute after:-inset-2.5 hover:text-foreground"
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
                                >{{ composerHint
                                }}<span class="hidden sm:inline"
                                    >{{ composerHint ? ' · ' : '' }}Shift +
                                    Enter for a new line</span
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
                        :stopped="threadStopped"
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
            data-morph="app"
        >
            <!-- The app, and what it does behind the page. -->
            <nav
                v-if="toolbarShown"
                class="-mb-1 flex shrink-0 flex-wrap items-center gap-1 sm:flex-nowrap"
                aria-label="What to show"
            >
                <!-- Moving between the app's pages, as a browser does. It
                     stays in place and only greys out, so nothing jumps. -->
                <div
                    class="flex shrink-0 items-center"
                    data-test="preview-browse"
                >
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-9"
                        :disabled="!browsing.canGoBack"
                        aria-label="Back"
                        title="Back"
                        data-test="preview-back"
                        @click="
                            showing = 'app';
                            browsing.back();
                        "
                    >
                        <ArrowLeft class="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-9"
                        :disabled="!browsing.canGoForward"
                        aria-label="Forward"
                        title="Forward"
                        data-test="preview-forward"
                        @click="
                            showing = 'app';
                            browsing.forward();
                        "
                    >
                        <ArrowRight class="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-9"
                        aria-label="Reload"
                        title="Reload"
                        data-test="preview-reload"
                        @click="
                            showing = 'app';
                            browsing.reload();
                        "
                    >
                        <RotateCw class="size-4" />
                    </Button>
                    <DropdownMenu
                        @update:open="
                            (open) => open && router.reload({ only: ['pages'] })
                        "
                    >
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                class="ml-1 flex h-9 w-24 shrink-0 items-center justify-between gap-1 rounded-md px-2 text-xs text-muted-foreground hover:bg-muted hover:text-foreground sm:w-auto sm:max-w-48 sm:justify-start"
                                :title="browsing.path"
                                data-test="preview-path"
                            >
                                <span class="truncate">{{
                                    browsing.path === '/'
                                        ? 'Home'
                                        : browsing.path
                                }}</span>
                                <ChevronDown class="size-3.5 shrink-0" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="start"
                            class="max-h-80 w-64 overflow-y-auto"
                            data-test="preview-pages"
                        >
                            <p
                                v-if="pages === undefined"
                                class="px-2 py-1.5 text-xs text-muted-foreground"
                            >
                                Looking for pages…
                            </p>
                            <p
                                v-else-if="!pages?.length"
                                class="px-2 py-1.5 text-xs text-muted-foreground"
                            >
                                No pages to open by their address.
                            </p>
                            <DropdownMenuItem
                                v-for="page in pages ?? []"
                                :key="page.path"
                                :data-test="`preview-page-${page.path}`"
                                @select="openPage(page.path)"
                            >
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate">{{
                                        page.words
                                    }}</span>
                                    <span
                                        class="block truncate text-xs text-muted-foreground"
                                        >{{ page.path }}</span
                                    >
                                </span>
                                <Lock
                                    v-if="page.signed_in"
                                    class="size-3.5 shrink-0 text-muted-foreground"
                                    aria-label="Only when signed in"
                                />
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <Button
                        v-if="openInTab"
                        variant="ghost"
                        size="icon"
                        class="size-9"
                        as-child
                    >
                        <a
                            :href="openInTab"
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="Open in a new tab"
                            title="Open in a new tab"
                            data-test="preview-open-tab"
                        >
                            <ExternalLink class="size-4" />
                        </a>
                    </Button>
                    <!-- It stays in place and only greys out on a page
                         with nothing to fill, so nothing jumps. -->
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-9"
                        :disabled="!canFill"
                        aria-label="Fill the form with example details"
                        :title="
                            canFill
                                ? 'Fill the form with example details'
                                : 'Nothing to fill on this page'
                        "
                        data-test="preview-fill"
                        @click="fillForm"
                    >
                        <ListChecks class="size-4" />
                    </Button>
                </div>
                <!-- Before and after sit in the bar, between moving around
                     the app and what it does behind the page. -->
                <ChangeCopySwitch
                    v-if="decidingOn"
                    class="order-last mx-auto sm:order-none sm:mx-0 sm:ml-2"
                    v-bind="changeCopySwitch(decidingOn)"
                    @show="withoutChange = !$event"
                />
                <div class="ml-auto sm:hidden">
                    <SignInAs
                        :project-id="project.id"
                        :people="people"
                        :path="browsing.path"
                        :copy="copy"
                        @open="openInApp"
                    />
                </div>
                <!-- On a phone the tabs take a line of their own, so none
                     hides past the edge. -->
                <div
                    v-if="!changeCopy || changeCopyRuns"
                    class="flex min-w-0 basis-full [scrollbar-width:none] items-center gap-1 overflow-x-auto sm:ml-auto sm:basis-auto"
                >
                    <button
                        v-for="tab in showingTabs"
                        :key="tab.key"
                        type="button"
                        :aria-pressed="showing === tab.key"
                        :class="[
                            'flex min-h-9 shrink-0 items-center gap-1.5 border-b-2 px-2 text-xs select-none',
                            showing === tab.key
                                ? 'border-foreground text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        ]"
                        :data-test="`showing-${tab.key}`"
                        @click="showing = tab.key"
                    >
                        {{ tab.label }}
                        <span
                            v-if="tab.count > 0"
                            :class="[
                                'min-w-4 rounded-full px-1 text-center text-[10px] leading-4 tabular-nums',
                                tab.key === 'problems'
                                    ? 'bg-destructive text-white'
                                    : 'bg-primary text-primary-foreground',
                            ]"
                            :aria-label="`${tab.count} new`"
                            :data-test="`${tab.key}-new`"
                            >{{ tab.count }}</span
                        >
                    </button>
                </div>
            </nav>
            <ChangeCopySwitch
                v-if="decidingOn && !toolbarShown"
                class="self-center"
                v-bind="changeCopySwitch(decidingOn)"
                @show="withoutChange = !$event"
            />
            <div
                v-if="changeCopy"
                v-show="(showing === 'app' || !changeCopyRuns) && !designedCopy"
                class="relative min-h-0 flex-1 overflow-hidden rounded-lg border bg-muted/40"
                data-test="change-copy"
            >
                <iframe
                    v-if="changeCopy.status === 'ready' && !changeCopyLost"
                    ref="changeCopyFrame"
                    :key="changeCopy.id"
                    :src="PreviewController.show.url(changeCopy.id)"
                    title="Your app with this change"
                    class="size-full bg-background"
                    data-test="change-copy-frame"
                />
                <div
                    v-if="changeCopyLost"
                    class="flex h-full flex-col items-center justify-center gap-3 p-6 text-center"
                    data-test="change-copy-lost"
                >
                    <p class="text-lg font-medium">
                        Your app with this change stopped
                    </p>
                    <p class="max-w-xs text-sm text-muted-foreground">
                        Your work is safe.
                    </p>
                    <Form
                        v-bind="
                            FeatureRequestPreviewController.store.form(
                                decidingOn!.featureRequest.id,
                            )
                        "
                        :options="{ preserveScroll: true, preserveState: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            :disabled="processing"
                            class="h-11 select-none"
                            data-test="change-copy-again"
                        >
                            Open it again
                        </Button>
                    </Form>
                </div>
                <div
                    v-else
                    class="flex h-full flex-col items-center justify-center gap-3 p-6 text-center"
                >
                    <LoaderCircle
                        class="size-6 animate-spin text-muted-foreground"
                    />
                    <p class="text-sm text-muted-foreground">
                        Getting your app ready with this change…
                    </p>
                </div>
            </div>
            <!-- The designer shows each change as it is made. -->
            <p
                v-if="preview?.updating && !designing && !changeCopy"
                class="text-center text-xs text-muted-foreground"
                data-test="preview-updating"
            >
                Putting your change in place…
            </p>
            <Form
                v-else-if="
                    preview?.status === 'ready' && preview.error && !changeCopy
                "
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
            <!-- Hidden, not removed, while a change shows, so the app does
                 not reload. -->
            <AppProblems
                v-if="showing === 'problems' && (!changeCopy || changeCopyRuns)"
                class="min-h-0 flex-1"
                :project-id="project.id"
                :problems="problems"
                :copy="copy"
            />
            <AppHappenings
                v-if="showing === 'happened' && (!changeCopy || changeCopyRuns)"
                class="min-h-0 flex-1"
                :project-id="project.id"
                :happenings="happenings"
                :copy="copy"
            />
            <AppSchedule
                v-if="showing === 'schedule' && (!changeCopy || changeCopyRuns)"
                class="min-h-0 flex-1"
                :project-id="project.id"
                :schedule="schedule"
                :copy="copy"
                @ran="router.reload({ only: ['emails', 'problems'] })"
            />
            <AppData
                v-if="showing === 'data' && (!changeCopy || changeCopyRuns)"
                class="min-h-0 flex-1"
                :project-id="project.id"
                :data="data"
                :rows="rows"
                :files="files"
                :copy="copy"
                @restarted="
                    copy === null
                        ? app.reload()
                        : openInChangeCopy(changeCopy!.url)
                "
            />
            <AppEmails
                v-if="showing === 'emails' && (!changeCopy || changeCopyRuns)"
                class="min-h-0 flex-1"
                :project-id="project.id"
                :emails="emails"
                :notices="notices"
                :origin="changeCopyOrigin ?? preview?.origin ?? null"
                :copy="copy"
                @open="openInApp"
            />
            <div
                v-show="(!changeCopy || designedCopy) && showing === 'app'"
                class="min-h-0 flex-1"
            >
                <AppPreview
                    :project-id="project.id"
                    :preview="designedCopy ?? preview"
                    :state="app"
                    :first-version="first_version"
                />
            </div>
            <DesignPanel
                v-if="designing && pane === 'app'"
                class="h-[45svh] shrink-0 rounded-lg border lg:hidden"
                :project-id="project.id"
                :preview="designedCopy ?? preview"
                :on-change="designedCopy !== null"
                :edits="edits"
                :state="app"
                :waiting="first_version !== null"
            />
        </main>
    </div>
</template>
