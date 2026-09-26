<script setup lang="ts">
import { Form, usePoll } from '@inertiajs/vue3';
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
import type { ProjectPublishing } from '@/types';

const props = defineProps<{
    projectId: number;
    publishing: ProjectPublishing;
}>();

const changing = ref(false);
const latest = computed(() => props.publishing.deployments[0] ?? null);
const active = computed(
    () =>
        latest.value !== null &&
        ['checking', 'pushing'].includes(latest.value.status),
);

const { start, stop } = usePoll(
    3000,
    { only: ['publishing'] },
    { autoStart: false },
);

watch(active, (value) => (value ? start() : stop()), { immediate: true });

function when(iso: string | null): string {
    return iso === null ? '' : new Date(iso).toLocaleString();
}
</script>

<template>
    <section class="max-w-2xl space-y-4" data-test="publishing">
        <Heading
            variant="small"
            title="Publish"
            description="Put your latest version online"
        />

        <template v-if="publishing.connected && !changing">
            <p class="text-sm" data-test="publish-status">
                <template v-if="latest === null">Not published yet.</template>
                <template v-else-if="latest.status === 'checking'"
                    >Checking your app before publishing. This can take a few
                    minutes.</template
                >
                <template v-else-if="latest.status === 'pushing'"
                    >Publishing…</template
                >
                <template v-else-if="latest.status === 'published'"
                    >Published {{ when(latest.finished_at) }}. Your hosting now
                    puts this version online.</template
                >
                <template v-else>{{ latest.error }}</template>
            </p>

            <Form
                v-bind="DeploymentController.store.form(projectId)"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
            >
                <Button
                    :disabled="processing || active"
                    class="h-11 select-none sm:h-9"
                    data-test="publish-button"
                >
                    Publish
                </Button>
                <InputError class="mt-2" :message="errors.publish" />
            </Form>

            <Collapsible>
                <CollapsibleTrigger
                    class="min-h-11 text-xs text-muted-foreground underline-offset-4 select-none hover:underline sm:min-h-0"
                >
                    Details
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
