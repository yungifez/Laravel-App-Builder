<script setup lang="ts">
import { Form, usePoll } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, CircleDot, LoaderCircle } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DeploymentController from '@/actions/App/Http/Controllers/DeploymentController';
import ProjectPublishingController from '@/actions/App/Http/Controllers/ProjectPublishingController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { when } from '@/lib/when';
import type { ProjectPublishing } from '@/types';

const props = defineProps<{
    projectId: number;
    publishing: ProjectPublishing;
}>();

const changing = ref(false);
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
        ['checking', 'pushing'].includes(latest.value.status),
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

const status = computed(() => {
    switch (true) {
        case latest.value?.status === 'checking':
            return {
                icon: LoaderCircle,
                tone: 'animate-spin text-muted-foreground',
                title: 'Checking your app first…',
                detail: 'This takes a few minutes. You can close this.',
            };
        case latest.value?.status === 'pushing':
            return {
                icon: LoaderCircle,
                tone: 'animate-spin text-muted-foreground',
                title: 'Sending it to your hosting…',
                detail: null,
            };
        case latest.value?.status === 'failed':
            return {
                icon: CircleAlert,
                tone: 'text-red-600',
                title: "It didn't go online",
                detail: latest.value?.error ?? null,
            };
        case live.value === null:
            return {
                icon: CircleDot,
                tone: 'text-muted-foreground',
                title: 'Not online yet',
                detail: 'Your latest kept version goes online once its checks pass.',
            };
        case upToDate.value:
            return {
                icon: CircleCheck,
                tone: 'text-green-600',
                title: 'Online and up to date',
                detail: `Went online ${when(live.value?.finished_at ?? null)}.`,
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
                </div>
            </div>

            <Form
                v-if="!active && !upToDate"
                v-bind="DeploymentController.store.form(projectId)"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
            >
                <Button
                    :disabled="processing"
                    class="h-11 w-full select-none sm:h-9"
                    data-test="publish-button"
                >
                    {{
                        latest?.status === 'failed'
                            ? 'Try again'
                            : live
                              ? 'Put the newest version online'
                              : 'Put it online'
                    }}
                </Button>
                <InputError class="mt-2" :message="errors.publish" />
            </Form>

            <Collapsible>
                <CollapsibleTrigger
                    class="min-h-11 text-xs text-muted-foreground underline-offset-4 select-none hover:underline sm:min-h-0"
                >
                    Where it goes
                </CollapsibleTrigger>
                <CollapsibleContent class="mt-2 space-y-3 text-xs">
                    <p class="break-all text-muted-foreground">
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
                    <p v-if="latest" class="font-mono text-muted-foreground">
                        {{ latest.commit.slice(0, 7) }}
                    </p>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="-ml-3 h-11 font-normal text-muted-foreground sm:h-8"
                        @click="changing = true"
                    >
                        Change where to publish
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
