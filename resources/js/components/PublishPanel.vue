<script setup lang="ts">
import { Form, Link, usePoll } from '@inertiajs/vue3';
import {
    CircleAlert,
    CircleCheck,
    CircleDot,
    ExternalLink,
    LoaderCircle,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CheckFixController from '@/actions/App/Http/Controllers/CheckFixController';
import DeploymentCheckController from '@/actions/App/Http/Controllers/DeploymentCheckController';
import DeploymentController from '@/actions/App/Http/Controllers/DeploymentController';
import DeploymentRestorationController from '@/actions/App/Http/Controllers/DeploymentRestorationController';
import LiveErrorFixController from '@/actions/App/Http/Controllers/LiveErrorFixController';
import ProjectPublishingController from '@/actions/App/Http/Controllers/ProjectPublishingController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { when } from '@/lib/when';
import { index as developers } from '@/routes/projects/developers';
import type { ProjectPublishing } from '@/types';

const props = defineProps<{
    projectId: string;
    publishing: ProjectPublishing;
    // How many of the app's own tests guard it, as last run.
    tests: number | null;
}>();

const changing = ref(false);
// Going back is the way out when a newer version went wrong. It asks once,
// in place, and says what stays as it is.
const goingBack = ref(false);
const latest = computed(() => props.publishing.deployments[0] ?? null);
const live = computed(
    () =>
        props.publishing.deployments.find(
            (deployment) => deployment.status === 'published',
        ) ?? null,
);
const active = computed(
    () =>
        latest.value !== null &&
        ['checking', 'pushing', 'confirming'].includes(latest.value.status),
);

// Sent with no address to check: sending it again changes nothing.
const sentCurrent = computed(
    () =>
        latest.value?.status === 'sent' &&
        latest.value.commit === props.publishing.head,
);

// The owner opens this to learn one thing: is what I kept online? No
// count of versions: a design edit and its undo are two versions but no
// difference to the owner.
const upToDate = computed(
    () =>
        live.value !== null &&
        props.publishing.head !== null &&
        props.publishing.head === live.value.commit,
);

// What going online would change, only while something is waiting and
// nothing is on its way: the owner decides with the list in front of them.
const waiting = computed(() => {
    const unpublished = props.publishing.unpublished;

    return (
        unpublished !== null &&
        !active.value &&
        !upToDate.value &&
        unpublished.added.length +
            unpublished.undone.length +
            unpublished.edits >
            0
    );
});

// What going online does to information the app already keeps, in the
// owner's words. Changes that touch it come first, so none is hidden
// behind "And more".
const dataWords = {
    deletes: 'Deletes information your app already keeps',
    renames: 'Renames information your app keeps',
    reshapes: 'Changes how your app keeps some information',
    rewrites: 'Rewrites information your app already keeps',
} as const;

// Deleting or reshaping what the live app keeps cannot be undone by going
// back, so the owner says yes to it for the version they see.
const losesData = computed(() =>
    (props.publishing.unpublished?.added ?? []).some((item) =>
        (item.data ?? []).some((kind) =>
            ['deletes', 'reshapes'].includes(kind),
        ),
    ),
);
const loseData = ref(false);

const goingOnline = computed(() => {
    const added = props.publishing.unpublished?.added ?? [];

    return [
        ...added.filter((item) => (item.data ?? []).length > 0),
        ...added.filter((item) => (item.data ?? []).length === 0),
    ];
});

// Problems online: fixing them comes before anything else here.
const troubled = computed(() => (live.value?.problems ?? 0) > 0);

// A check stopped the newest version going online, or the host could not
// build or start it. Trying again would most likely fail the same way, so
// fixing it comes first.
const checkFailed = computed(
    () =>
        latest.value?.status === 'failed' &&
        (latest.value.checks.some((check) => !check.passed) ||
            latest.value.error_cause === 'release'),
);

// The new version went online but its address or sign-in failed. Sending
// the same version again ends the same way, so fixing it is the step. A
// host still starting it has no failed check: waiting is the step then.
const unhealthy = computed(
    () =>
        latest.value?.status === 'needs_attention' &&
        latest.value.error_cause !== 'ours' &&
        latest.value.health.some((check) => !check.passed),
);

// The host was still starting the new version when the wait ran out. More
// time may be all it needs, so checking again comes first; sending it
// again stays as the second step.
const starting = computed(
    () =>
        latest.value?.status === 'needs_attention' &&
        latest.value.error_cause === 'starting',
);

// Sent before the owner gave the web address, or our own check broke: the
// app may be fine, so checking again is the step, not sending it again.
const recheck = computed(
    () =>
        (latest.value?.status === 'sent' &&
            props.publishing.address !== null) ||
        (latest.value?.status === 'needs_attention' &&
            latest.value.error_cause === 'ours') ||
        starting.value,
);

// Publishing could not reach the repository the owner gave. Sending again
// fails the same way until the address or branch is put right.
const settingsFault = computed(
    () =>
        latest.value?.status === 'failed' &&
        latest.value.error_cause === 'settings',
);

// The branch has work this app does not. Sending the same version again is
// refused the same way, so a developer bringing that work in is the step.
const conflict = computed(
    () =>
        latest.value?.status === 'failed' &&
        latest.value.error_cause === 'conflict',
);

const times = (count: number) => (count === 1 ? 'once' : `${count} times`);

// The app's own tests run before any version goes online. Saying how many
// shows the owner what "checked" means.
const tests = computed(() =>
    (props.tests ?? 0) > 0
        ? `${props.tests} ${props.tests === 1 ? 'test' : 'tests'}`
        : null,
);
const testsPassed = computed(
    () =>
        tests.value !== null &&
        (live.value?.checks ?? []).some(
            (check) => check.name === 'Tests' && check.passed,
        ),
);

const status = computed(() => {
    switch (true) {
        case latest.value?.restores != null && active.value:
            return {
                icon: LoaderCircle,
                tone: 'animate-spin text-muted-foreground',
                title: 'Going back to the earlier version…',
                detail: 'It was checked before, so this is quick.',
            };
        case latest.value?.status === 'checking':
            return {
                icon: LoaderCircle,
                tone: 'animate-spin text-muted-foreground',
                title: 'Checking your app first…',
                detail: latest.value.doing
                    ? `${latest.value.doing}. This takes a few minutes. You can close this.`
                    : 'This takes a few minutes. You can close this.',
            };
        case latest.value?.status === 'pushing':
            return {
                icon: LoaderCircle,
                tone: 'animate-spin text-muted-foreground',
                title: 'Sending it to your hosting…',
                detail: latest.value.doing
                    ? `${latest.value.doing}. You can close this.`
                    : null,
            };
        case latest.value?.status === 'confirming':
            return {
                icon: LoaderCircle,
                tone: 'animate-spin text-muted-foreground',
                title: 'Waiting for it to come online…',
                detail: 'Your hosting is putting it online. I check that it answers.',
            };
        case latest.value?.status === 'failed':
            return {
                icon: CircleAlert,
                tone: 'text-red-600',
                title: "It didn't go online",
                detail: latest.value?.error ?? null,
            };
        case latest.value?.status === 'needs_attention':
            return {
                icon: CircleAlert,
                tone: 'text-amber-500',
                title: 'Sent, but your app isn’t answering',
                detail: latest.value?.error ?? null,
            };
        case latest.value?.status === 'sent':
            return {
                icon: CircleDot,
                tone: 'text-muted-foreground',
                title: 'Sent to your hosting',
                detail: props.publishing.address
                    ? 'Check that it’s online at your web address.'
                    : 'Add your app’s web address so I can check it’s online.',
            };
        case live.value === null:
            return {
                icon: CircleDot,
                tone: 'text-muted-foreground',
                title: 'Not online yet',
                detail: 'Your latest kept version goes online once its checks pass.',
            };
        // Errors outrank "newer changes": a broken app online matters more.
        case (live.value?.problems ?? 0) > 0:
            return {
                icon: CircleAlert,
                tone: 'text-amber-500',
                title: 'Online, but it ran into problems',
                detail: `Something went wrong ${times(live.value?.problems ?? 0)} since it went online ${when(live.value?.finished_at ?? null)}.`,
            };
        case live.value?.restores != null && !upToDate.value:
            return {
                icon: CircleDot,
                tone: 'text-muted-foreground',
                title: 'Back to an earlier version',
                detail: `What's online is the version from ${when(live.value?.restores ?? null)}. Your newer changes are here, ready to go online again.`,
            };
        case upToDate.value:
            return {
                icon: CircleCheck,
                tone: 'text-green-600',
                title: 'Online and up to date',
                detail: testsPassed.value
                    ? `Went online ${when(live.value?.finished_at ?? null)}, after its ${tests.value} passed.`
                    : `Went online ${when(live.value?.finished_at ?? null)}.`,
            };
        default:
            return {
                icon: CircleDot,
                tone: 'text-amber-500',
                title: "Newer changes aren't online yet",
                detail: `What's online is from ${when(live.value?.finished_at ?? null)}.`,
            };
    }
});

const { start, stop } = usePoll(
    3000,
    { only: ['publishing'] },
    { autoStart: false },
);

watch(active, (value) => (value ? start() : stop()), { immediate: true });
</script>

<template>
    <section class="space-y-4" data-test="publishing">
        <Heading variant="small" title="Put it online" />

        <template v-if="publishing.connected && !changing">
            <div class="flex gap-3" data-test="publish-status">
                <component
                    :is="status.icon"
                    :class="['mt-0.5 size-5 shrink-0', status.tone]"
                    aria-hidden="true"
                />
                <div class="min-w-0">
                    <p class="font-medium">{{ status.title }}</p>
                    <p
                        v-if="status.detail"
                        class="text-sm break-words text-muted-foreground"
                    >
                        {{ status.detail }}
                    </p>
                    <div
                        v-if="waiting"
                        class="mt-2 space-y-1 text-sm"
                        data-test="unpublished"
                    >
                        <p class="text-muted-foreground">Going online next:</p>
                        <ul
                            class="list-disc space-y-1 pl-5 marker:text-muted-foreground"
                        >
                            <li
                                v-for="item in goingOnline.slice(0, 5)"
                                :key="`added-${item.id}`"
                                class="break-words"
                            >
                                <span class="line-clamp-2">{{
                                    item.asked
                                }}</span>
                                <span
                                    v-for="kind in item.data ?? []"
                                    :key="kind"
                                    class="flex items-center gap-1.5 text-amber-500"
                                    data-test="unpublished-data"
                                >
                                    <CircleAlert class="size-3.5 shrink-0" />
                                    {{ dataWords[kind] }}
                                </span>
                            </li>
                            <li
                                v-for="item in publishing.unpublished?.undone"
                                :key="`undone-${item.id}`"
                                class="break-words text-muted-foreground"
                            >
                                <span class="line-clamp-2"
                                    >Takes back: {{ item.asked }}</span
                                >
                            </li>
                            <li
                                v-if="
                                    (publishing.unpublished?.added.length ??
                                        0) > 5
                                "
                                class="text-muted-foreground"
                            >
                                And
                                {{
                                    (publishing.unpublished?.added.length ??
                                        0) - 5
                                }}
                                more you asked for
                            </li>
                            <li
                                v-if="publishing.unpublished?.edits"
                                class="text-muted-foreground"
                            >
                                <!-- Every click in the design editor is
                                     saved, so their number means nothing
                                     to the owner -->
                                Design changes you made
                            </li>
                        </ul>
                    </div>
                    <a
                        v-if="live && publishing.address"
                        :href="publishing.address"
                        target="_blank"
                        rel="noopener"
                        class="mt-1 inline-flex min-h-11 items-center gap-1.5 text-sm font-medium break-all underline-offset-4 hover:underline sm:min-h-0"
                        data-test="live-address"
                    >
                        {{ publishing.address.replace(/^https?:\/\//, '') }}
                        <!-- Says it opens the app online, so the address
                             does not read as a stray line of text. -->
                        <ExternalLink
                            class="size-3.5 shrink-0 text-muted-foreground"
                        />
                    </a>
                </div>
            </div>

            <Form
                v-if="troubled && !active"
                v-bind="LiveErrorFixController.store.form(projectId)"
                v-slot="{ errors, processing }"
            >
                <Button
                    :disabled="processing"
                    class="h-11 w-full select-none sm:h-9"
                    data-test="fix-live-errors"
                >
                    Fix it
                </Button>
                <InputError class="mt-2" :message="errors.fix" />
            </Form>

            <Form
                v-else-if="(checkFailed || unhealthy) && !active"
                v-bind="CheckFixController.store.form(projectId)"
                v-slot="{ errors, processing }"
            >
                <Button
                    :disabled="processing"
                    class="h-11 w-full select-none sm:h-9"
                    data-test="fix-failed-checks"
                >
                    Fix it
                </Button>
                <InputError class="mt-2" :message="errors.fix" />
            </Form>

            <Form
                v-if="recheck && !active"
                v-bind="DeploymentCheckController.store.form(projectId)"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
            >
                <Button
                    :disabled="processing"
                    class="h-11 w-full select-none sm:h-9"
                    data-test="publish-check"
                >
                    Check it’s online
                </Button>
                <InputError class="mt-2" :message="errors.check" />
            </Form>

            <Button
                v-if="conflict && !active"
                as-child
                class="h-11 w-full select-none sm:h-9"
            >
                <Link
                    :href="developers(projectId)"
                    data-test="publish-ask-developer"
                >
                    Ask one of our developers
                </Link>
            </Button>

            <Button
                v-if="settingsFault && !active"
                class="h-11 w-full select-none sm:h-9"
                data-test="publish-settings"
                @click="changing = true"
            >
                Change where to publish
            </Button>

            <Form
                v-if="
                    !active &&
                    !upToDate &&
                    !sentCurrent &&
                    !(
                        (unhealthy || conflict || (recheck && !starting)) &&
                        latest?.commit === publishing.head
                    )
                "
                v-bind="DeploymentController.store.form(projectId)"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
            >
                <!-- What the owner sees listed is what goes online: a newer
                     version kept meanwhile is refused, and the list shows
                     it. -->
                <input
                    v-if="publishing.head"
                    type="hidden"
                    name="seen"
                    :value="publishing.head"
                />
                <Label
                    v-if="losesData"
                    class="mb-3 flex items-start gap-2 text-sm leading-snug font-normal"
                    data-test="lose-data"
                >
                    <Checkbox
                        v-model="loseData"
                        name="lose_data"
                        class="mt-0.5"
                    />
                    I understand some information my app keeps online will be
                    deleted or changed.
                </Label>
                <Button
                    :disabled="processing || (losesData && !loseData)"
                    :variant="
                        troubled || checkFailed || settingsFault || starting
                            ? 'outline'
                            : 'default'
                    "
                    class="h-11 w-full select-none sm:h-9"
                    data-test="publish-button"
                >
                    {{
                        latest?.status === 'failed' ||
                        latest?.status === 'needs_attention'
                            ? 'Try again'
                            : live
                              ? 'Put the newest version online'
                              : 'Put it online'
                    }}
                </Button>
                <InputError class="mt-2" :message="errors.publish" />
                <InputError class="mt-2" :message="errors.lose_data" />
                <p
                    class="mt-2 text-xs text-muted-foreground"
                    data-test="publish-checked-first"
                >
                    I check it first{{ tests ? `, with its ${tests}` : '' }}.
                    {{
                        live
                            ? 'If a check fails, what is online stays as it is.'
                            : 'If a check fails, nothing goes online.'
                    }}
                </p>
            </Form>

            <div v-if="publishing.previous && !active" data-test="go-back">
                <button
                    v-if="!goingBack"
                    type="button"
                    class="min-h-11 text-xs text-muted-foreground underline-offset-4 select-none hover:underline sm:min-h-0"
                    data-test="go-back-open"
                    @click="goingBack = true"
                >
                    Go back to the version from
                    {{ when(publishing.previous.at) }}
                </button>
                <Form
                    v-else
                    v-bind="
                        DeploymentRestorationController.store.form({
                            project: projectId,
                            deployment: publishing.previous.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    class="space-y-2 border-t pt-3 text-sm"
                    v-slot="{ errors, processing }"
                    @success="goingBack = false"
                >
                    <p>
                        Your app online goes back to the version from
                        {{ when(publishing.previous.at) }}. It was checked then,
                        so it goes at once. Your newer changes stay here.
                    </p>
                    <p class="text-muted-foreground">
                        Information people saved since then stays.
                    </p>
                    <p
                        v-if="publishing.previous.stored"
                        class="flex gap-1.5 text-amber-500"
                        data-test="go-back-stored"
                    >
                        <CircleAlert class="mt-0.5 size-4 shrink-0" />
                        The newer version changed how your app keeps
                        information. That stays, and the earlier version may not
                        expect it.
                    </p>
                    <p
                        v-if="
                            publishing.previous.stored &&
                            publishing.previous.copy
                        "
                        class="text-muted-foreground"
                        data-test="go-back-copy"
                    >
                        A copy of your information from before it is kept. Ask
                        us if you need it back.
                    </p>
                    <div class="flex gap-2">
                        <Button
                            :disabled="processing"
                            variant="outline"
                            class="h-11 select-none sm:h-9"
                            data-test="go-back-confirm"
                        >
                            Go back
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            class="h-11 sm:h-9"
                            @click="goingBack = false"
                        >
                            Cancel
                        </Button>
                    </div>
                    <InputError :message="errors.restore" />
                </Form>
            </div>

            <Collapsible>
                <CollapsibleTrigger
                    class="min-h-11 text-xs text-muted-foreground underline-offset-4 select-none hover:underline sm:min-h-0"
                >
                    Where it goes
                </CollapsibleTrigger>
                <CollapsibleContent class="mt-2 space-y-3 text-xs">
                    <p
                        v-if="publishing.managed"
                        class="break-all text-muted-foreground"
                    >
                        Goes online at
                        {{ publishing.address ?? 'its own web address' }}
                        after the full checks pass on that exact version.
                    </p>
                    <p v-else class="break-all text-muted-foreground">
                        Pushes to
                        <span class="font-mono">{{ publishing.branch }}</span>
                        of
                        <span class="font-mono">{{ publishing.target }}</span>
                        after the full checks pass on that exact commit.
                    </p>
                    <ul v-if="latest?.checks.length" class="space-y-0.5">
                        <li
                            v-for="check in latest.checks"
                            :key="check.name"
                            :class="!check.passed && 'text-destructive'"
                        >
                            {{ check.passed ? 'Passed' : 'Failed' }}:
                            {{ check.name }}
                        </li>
                    </ul>
                    <ul v-if="latest?.health.length" class="space-y-0.5">
                        <li
                            v-for="check in latest.health"
                            :key="check.path"
                            :class="!check.passed && 'text-destructive'"
                        >
                            <template v-if="check.key === 'auth.sign-in'">
                                {{
                                    check.passed
                                        ? 'Sign-in works'
                                        : 'Sign-in does not work'
                                }}
                            </template>
                            <template v-else>
                                {{ check.passed ? 'Answered' : 'No answer' }}:
                                <span class="font-mono">{{ check.path }}</span>
                            </template>
                        </li>
                    </ul>
                    <p v-if="latest?.backed_up" class="text-muted-foreground">
                        It changes how your app keeps information, so a copy of
                        that information was saved first.
                    </p>
                    <!-- What the host or Git said: for whoever helps the
                         owner, never the first thing they read. -->
                    <p
                        v-if="latest?.error_details"
                        class="font-mono break-words whitespace-pre-wrap text-muted-foreground"
                        data-test="publish-error-details"
                    >
                        {{ latest.error_details }}
                    </p>
                    <p v-if="latest" class="font-mono text-muted-foreground">
                        {{ latest.commit.slice(0, 7) }}
                    </p>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="-ml-3 h-11 font-normal text-muted-foreground sm:h-8"
                        @click="changing = true"
                    >
                        {{
                            publishing.managed
                                ? 'Use my own hosting instead'
                                : 'Change where to publish'
                        }}
                    </Button>
                </CollapsibleContent>
            </Collapsible>
        </template>

        <Form
            v-else
            v-bind="ProjectPublishingController.update.form(projectId)"
            :options="{ preserveScroll: true }"
            class="space-y-4"
            @success="changing = false"
            v-slot="{ errors, processing }"
        >
            <p class="text-sm text-muted-foreground">
                Your hosting (for example Laravel Cloud) puts your app online
                from a Git repository. Tell me where it is, and I will send each
                version you publish there.
            </p>
            <div class="grid gap-2">
                <Label for="deploy_remote">Repository address</Label>
                <Input
                    id="deploy_remote"
                    name="deploy_remote"
                    autocomplete="off"
                    placeholder="https://github.com/you/your-app.git"
                />
                <InputError :message="errors.deploy_remote" />
            </div>
            <div class="grid gap-2">
                <Label for="deploy_branch">Branch your hosting uses</Label>
                <Input
                    id="deploy_branch"
                    name="deploy_branch"
                    :default-value="publishing.branch ?? 'main'"
                />
                <InputError :message="errors.deploy_branch" />
            </div>
            <div class="grid gap-2">
                <Label for="live_url">Your app’s web address (optional)</Label>
                <Input
                    id="live_url"
                    name="live_url"
                    type="url"
                    autocomplete="off"
                    placeholder="https://your-app.example.com"
                    :default-value="publishing.address ?? ''"
                />
                <InputError :message="errors.live_url" />
            </div>
            <div class="flex gap-2">
                <Button
                    :disabled="processing"
                    class="h-11 sm:h-9"
                    data-test="save-publishing"
                >
                    Save
                </Button>
                <Button
                    v-if="changing"
                    type="button"
                    variant="ghost"
                    class="h-11 sm:h-9"
                    @click="changing = false"
                >
                    Cancel
                </Button>
            </div>
        </Form>
    </section>
</template>
