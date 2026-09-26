<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useResizeObserver } from '@vueuse/core';
import { computed, ref } from 'vue';
import ProjectPreviewController from '@/actions/App/Http/Controllers/ProjectPreviewController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { AppPreviewState } from '@/composables/useAppPreview';
import type { EditorPreview } from '@/types';

const props = defineProps<{
    projectId: number;
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

function bindFrame(element: unknown): void {
    props.state.frame = element as HTMLIFrameElement | null;
}
</script>

<template>
    <div
        ref="pane"
        class="relative h-full overflow-hidden rounded-lg border bg-muted/40"
        data-test="app-preview"
    >
        <iframe
            v-if="state.running && state.frameSource !== null"
            :ref="bindFrame"
            :key="state.frameKey"
            :src="state.frameSource"
            title="Your app"
            class="absolute top-0 left-1/2 origin-top bg-background"
            :style="{
                width: `${drawnWidth}px`,
                height: `${paneSize.height / scale}px`,
                transform: `translateX(-50%) scale(${scale})`,
            }"
            data-test="preview-frame"
        />

        <div
            v-else
            class="flex h-full flex-col items-center justify-center gap-3 p-6 text-center"
        >
            <template v-if="preview?.status === 'starting'">
                <Spinner class="size-6" />
                <p class="text-sm text-muted-foreground">Starting your app…</p>
            </template>

            <template v-else>
                <p class="text-lg font-medium">See your app here</p>
                <p
                    v-if="preview?.status === 'failed'"
                    class="max-w-xs text-sm text-destructive"
                >
                    {{ preview.error ?? 'Your app could not start.' }}
                </p>
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
