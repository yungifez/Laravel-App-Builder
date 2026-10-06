<script setup lang="ts">
import { Form, Link, router } from '@inertiajs/vue3';
import { useResizeObserver } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import FeatureRequestPreviewController from '@/actions/App/Http/Controllers/FeatureRequestPreviewController';
import FeatureRequestRetryController from '@/actions/App/Http/Controllers/FeatureRequestRetryController';
import ProjectPreviewController from '@/actions/App/Http/Controllers/ProjectPreviewController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { AppPreviewState } from '@/composables/useAppPreview';
import { edit as billing } from '@/routes/billing';
import { show as showProject } from '@/routes/projects';
import type { EditorPreview, FirstVersion } from '@/types';

const props = defineProps<{
    projectId: string;
    preview: EditorPreview | null;
    state: AppPreviewState;
    /** A sample page to try the designer on, rather than an app. */
    sample?: boolean;
    /**
     * Until a first version is kept the app is only the template, so the
     * pane says how the first version is going instead of opening it.
     */
    firstVersion?: FirstVersion | null;
}>();

// A desktop layout starts at 1024px. When the pane is narrower, the app is
// drawn at that width and scaled down, so it shows as it really looks.
const DESKTOP = 1024;

const pane = ref<HTMLElement | null>(null);
const paneSize = ref({ width: 0, height: 0 });

useResizeObserver(pane, ([entry]) => {
    paneSize.value = {
        width: entry.contentRect.width,
        height: entry.contentRect.height,
    };
});

const drawnWidth = computed(
    () => props.state.frameWidth ?? Math.max(paneSize.value.width, DESKTOP),
);
const scale = computed(() =>
    paneSize.value.width === 0
        ? 1
        : Math.min(1, paneSize.value.width / drawnWidth.value),
);

// An app can take a few seconds to draw itself. Past a moment, the pane
// says so, rather than stay empty; a quick one shows no message at all.
const slow = ref(false);
let slowTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    () =>
        props.state.running &&
        props.state.frames.length > 0 &&
        !props.state.drawn,
    (waiting) => {
        clearTimeout(slowTimer);
        slow.value = false;

        if (waiting) {
            slowTimer = setTimeout(() => (slow.value = true), 800);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(slowTimer));

// Once the app with the first version is starting, open that change, so
// the pane shows it.
function openFirstVersion() {
    if (props.firstVersion) {
        router.visit(
            showProject(props.projectId, {
                query: { change: props.firstVersion.change },
            }),
            { preserveState: true, preserveScroll: true },
        );
    }
}

// The overlay in the app draws its handles bigger by as much as the app is
// drawn smaller, so they stay easy to grab.
watch(scale, (value) => (props.state.zoom = value), { immediate: true });
</script>

<template>
    <div
        ref="pane"
        :class="[
            'relative h-full overflow-hidden rounded-lg border bg-muted/40',
            // Breathes softly while the app draws itself.
            state.frames.length > 0 && !state.drawn && 'animate-pulse',
        ]"
        data-test="app-preview"
    >
        <!-- The app on show, and after a rebuild the new app loading
             behind it until it takes its place. It stays drawn there
             rather than hidden, because a browser stops drawing a hidden
             page. -->
        <template
            v-if="
                !firstVersion &&
                state.running &&
                !state.lost &&
                state.frames.length > 0
            "
        >
            <!-- Behind the app, which covers it once drawn. -->
            <div
                v-if="slow"
                class="absolute inset-0 flex flex-col items-center justify-center gap-3 text-center"
                data-test="preview-opening"
            >
                <Spinner class="size-6" />
                <p class="text-sm text-muted-foreground">
                    {{ sample ? 'Opening the sample…' : 'Opening your app…' }}
                </p>
            </div>
            <iframe
                v-for="(appFrame, index) in state.frames"
                :key="appFrame.key"
                :ref="
                    (element) =>
                        state.bind(
                            appFrame.key,
                            element as HTMLIFrameElement | null,
                        )
                "
                :src="appFrame.src"
                title="Your app"
                :aria-hidden="index > 0"
                :tabindex="index > 0 ? -1 : undefined"
                :class="[
                    'absolute top-0 left-1/2 origin-top bg-background transition-opacity duration-base',
                    index > 0 ? 'pointer-events-none z-0' : 'z-10',
                    index === 0 && !state.drawn && 'opacity-0',
                ]"
                :style="{
                    width: `${drawnWidth}px`,
                    height: `${paneSize.height / scale}px`,
                    transform: `translateX(-50%) scale(${scale})`,
                }"
                :data-test="index === 0 ? 'preview-frame' : 'preview-next'"
            />
        </template>

        <div
            v-else
            class="flex h-full flex-col items-center justify-center gap-3 p-6 text-center"
        >
            <template v-if="firstVersion?.state === 'making'">
                <Spinner class="size-6" />
                <p
                    class="text-sm text-muted-foreground"
                    data-test="first-version-making"
                >
                    Making the first version of your app…
                </p>
            </template>

            <template v-else-if="firstVersion?.state === 'waiting'">
                <p
                    class="text-lg font-medium"
                    data-test="first-version-waiting"
                >
                    Waiting for your Claude Code or Codex
                </p>
                <p class="max-w-sm text-sm text-muted-foreground">
                    It starts when your tool asks for work.
                </p>
                <Button as-child variant="outline" class="h-11 select-none">
                    <Link
                        :href="
                            showProject(projectId, {
                                query: { change: firstVersion.change },
                            })
                        "
                        :only="['change']"
                        preserve-state
                        preserve-scroll
                        >See how to connect</Link
                    >
                </Button>
            </template>

            <template v-else-if="firstVersion?.state === 'asking'">
                <p class="text-lg font-medium" data-test="first-version-asking">
                    Your first version needs an answer from you
                </p>
                <Button as-child class="h-11 select-none">
                    <Link
                        :href="
                            showProject(projectId, {
                                query: { change: firstVersion.change },
                            })
                        "
                        :only="['change']"
                        preserve-state
                        preserve-scroll
                        >Answer</Link
                    >
                </Button>
            </template>

            <template v-else-if="firstVersion?.state === 'ready'">
                <p class="text-lg font-medium" data-test="first-version-ready">
                    Your first version is ready
                </p>
                <p
                    v-if="firstVersion.checking"
                    class="text-sm text-muted-foreground"
                    data-test="first-version-checking"
                >
                    Checks are still running.
                </p>
                <!-- Opens the change and starts the app with it, in one step. -->
                <Form
                    v-bind="
                        FeatureRequestPreviewController.store.form(
                            firstVersion.change,
                        )
                    "
                    :options="{ preserveScroll: true, preserveState: true }"
                    @success="openFirstVersion"
                    v-slot="{ processing }"
                >
                    <Button
                        :disabled="processing"
                        class="h-11 select-none"
                        data-test="first-version-try"
                    >
                        Try it
                    </Button>
                </Form>
            </template>

            <template v-else-if="firstVersion?.state === 'stopped'">
                <p
                    class="text-lg font-medium"
                    data-test="first-version-stopped"
                >
                    Your first version could not be made
                </p>
                <p
                    v-if="firstVersion.error"
                    class="max-w-sm text-sm text-muted-foreground"
                >
                    {{ firstVersion.error }}
                </p>
                <Link
                    v-if="firstVersion.plan_ran_out"
                    :href="billing()"
                    class="text-sm font-medium underline underline-offset-4"
                    data-test="first-version-see-plan"
                    >See your plan</Link
                >
                <Form
                    v-else-if="firstVersion.can_retry"
                    v-bind="
                        FeatureRequestRetryController.store.form(
                            firstVersion.change,
                        )
                    "
                    v-slot="{ errors, processing }"
                    class="space-y-2"
                >
                    <Button
                        :disabled="processing"
                        class="h-11 select-none"
                        data-test="first-version-retry"
                    >
                        Try again
                    </Button>
                    <InputError :message="errors.retry" />
                </Form>
            </template>

            <template v-else-if="preview?.status === 'starting'">
                <Spinner class="size-6" />
                <p class="text-sm text-muted-foreground">Starting your app…</p>
            </template>

            <template v-else>
                <template v-if="preview?.status === 'failed'">
                    <p class="text-lg font-medium">Your app could not start</p>
                    <p
                        v-if="preview.error"
                        class="max-w-xs text-sm text-destructive"
                    >
                        {{ preview.error }}
                    </p>
                </template>
                <template v-else-if="state.lost">
                    <p class="text-lg font-medium" data-test="preview-lost">
                        Your app stopped
                    </p>
                    <p class="max-w-xs text-sm text-muted-foreground">
                        This is our fault: it stopped on our side. Your work is
                        safe.
                    </p>
                </template>
                <p v-else class="text-lg font-medium">See your app here</p>
                <Form
                    v-bind="ProjectPreviewController.store.form(projectId)"
                    :options="{ preserveScroll: true, preserveState: true }"
                    v-slot="{ errors, processing }"
                    class="space-y-2"
                >
                    <Button
                        :disabled="processing"
                        class="h-11 select-none"
                        data-test="open-app-button"
                    >
                        {{ preview === null ? 'Open my app' : 'Open it again' }}
                    </Button>
                    <InputError :message="errors.preview" />
                </Form>
            </template>
        </div>
    </div>
</template>
