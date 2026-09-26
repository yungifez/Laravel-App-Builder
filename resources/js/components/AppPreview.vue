<script setup lang="ts">
import { Form, Link, usePoll } from '@inertiajs/vue3';
import { ExternalLink, Paintbrush, RotateCw } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ProjectPreviewController from '@/actions/App/Http/Controllers/ProjectPreviewController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { devices } from '@/lib/visualProperties';
import { show as showPreview } from '@/routes/previews';
import { show as showEditor } from '@/routes/projects/editor';
import type { Device, EditorPreview } from '@/types';

const props = defineProps<{
    projectId: number;
    preview: EditorPreview | null;
}>();

const device = ref<Device>('lg');
const frameSource = ref<string | null>(null);
const frameKey = ref(0);

const running = computed(() => props.preview?.status === 'ready');
const busy = computed(
    () =>
        props.preview?.status === 'starting' ||
        props.preview?.updating === true,
);
const frameWidth = computed(
    () => devices.find((option) => option.key === device.value)?.width ?? null,
);

// Open the app once it is running, and reload it after each kept change.
watch(
    () => [props.preview?.status, props.preview?.revision] as const,
    ([status], previous) => {
        if (status !== 'ready' || props.preview === null) {
            frameSource.value = null;

            return;
        }

        if (frameSource.value === null) {
            frameSource.value = showPreview(props.preview.id).url;
        } else if (previous?.[1] !== props.preview.revision) {
            reload();
        }
    },
    { immediate: true },
);

const { start, stop } = usePoll(
    2000,
    { only: ['preview'] },
    { autoStart: false },
);

watch(busy, (value) => (value ? start() : stop()), { immediate: true });

function reload(): void {
    if (props.preview !== null) {
        frameSource.value = showPreview(props.preview.id).url;
        frameKey.value++;
    }
}
</script>

<template>
    <div
        class="flex h-full min-h-[70vh] flex-col lg:min-h-0"
        data-test="app-preview"
    >
        <div
            v-if="running && preview"
            class="flex flex-wrap items-center justify-between gap-2 pb-2"
        >
            <!-- A phone shows the app at its own size, so the choice only
                 appears where there is room to change it. -->
            <div
                class="hidden rounded-md bg-muted p-1 sm:inline-flex"
                role="group"
                aria-label="Screen size"
            >
                <button
                    v-for="option in devices"
                    :key="option.key"
                    type="button"
                    :aria-pressed="device === option.key"
                    :class="[
                        'min-h-11 rounded px-3 text-sm select-none sm:min-h-8',
                        device === option.key
                            ? 'bg-background shadow-sm'
                            : 'text-muted-foreground',
                    ]"
                    @click="device = option.key"
                >
                    {{ option.label }}
                </button>
            </div>

            <div class="flex items-center gap-1">
                <span
                    v-if="preview.updating"
                    class="mr-2 flex items-center gap-2 text-sm text-muted-foreground"
                    data-test="preview-updating"
                >
                    <Spinner class="size-4" /> Putting your change in place
                </span>
                <!-- Like the "Edit" mode of other builders: point at a part
                     of the app and change how it looks. -->
                <Button
                    variant="ghost"
                    class="h-11 select-none sm:h-8"
                    as-child
                >
                    <Link
                        :href="showEditor(projectId)"
                        data-test="edit-looks-link"
                    >
                        <Paintbrush class="size-4" /> Change how it looks
                    </Link>
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-11 sm:size-8"
                    aria-label="Reload"
                    @click="reload"
                >
                    <RotateCw class="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-11 sm:size-8"
                    as-child
                >
                    <a
                        :href="showPreview(preview.id).url"
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label="Open in a new tab"
                    >
                        <ExternalLink class="size-4" />
                    </a>
                </Button>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-auto rounded-lg border bg-muted/40">
            <iframe
                v-if="running && frameSource !== null"
                :key="frameKey"
                :src="frameSource"
                title="Your app"
                class="mx-auto block h-full min-h-[70vh] bg-background lg:min-h-0"
                :style="{
                    width: frameWidth === null ? '100%' : `${frameWidth}px`,
                }"
                data-test="preview-frame"
            />

            <div
                v-else
                class="flex h-full min-h-[70vh] flex-col items-center justify-center gap-3 p-6 text-center lg:min-h-0"
            >
                <template v-if="preview?.status === 'starting'">
                    <Spinner class="size-5" />
                    <p class="text-sm text-muted-foreground">
                        Starting your app. The first time can take a few
                        minutes.
                    </p>
                </template>

                <template v-else>
                    <p class="font-medium">See your app here</p>
                    <p class="max-w-xs text-sm text-muted-foreground">
                        {{
                            preview?.status === 'failed'
                                ? (preview.error ?? 'Your app could not start.')
                                : 'I start a private copy of your app. Nobody else can see it.'
                        }}
                    </p>
                    <Form
                        v-bind="ProjectPreviewController.store.form(projectId)"
                        :options="{ preserveScroll: true }"
                        v-slot="{ errors, processing }"
                        class="space-y-2"
                    >
                        <Button
                            variant="outline"
                            :disabled="processing"
                            class="h-11 select-none sm:h-9"
                            data-test="open-app-button"
                        >
                            {{
                                preview === null
                                    ? 'Open my app'
                                    : 'Open my app again'
                            }}
                        </Button>
                        <InputError :message="errors.preview" />
                    </Form>
                </template>
            </div>
        </div>
    </div>
</template>
