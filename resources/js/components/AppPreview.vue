<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useResizeObserver } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import ProjectPreviewController from '@/actions/App/Http/Controllers/ProjectPreviewController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { AppPreviewState } from '@/composables/useAppPreview';
import type { EditorPreview } from '@/types';

const props = defineProps<{
    projectId: string;
    preview: EditorPreview | null;
    state: AppPreviewState;
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
            v-if="state.running && !state.lost && state.frames.length > 0"
        >
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
            <template v-if="preview?.status === 'starting'">
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
                        Your work is safe.
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
