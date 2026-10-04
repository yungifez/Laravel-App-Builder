<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { RotateCcw } from '@lucide/vue';
import { onMounted, ref, watch } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import AppPreview from '@/components/AppPreview.vue';
import DesignPanel from '@/components/DesignPanel.vue';
import { Button } from '@/components/ui/button';
import { useAppPreview } from '@/composables/useAppPreview';
import { home, register } from '@/routes';
import { index } from '@/routes/projects';
import { app as sampleApp, destroy } from '@/routes/sample-design';
import { store as edit } from '@/routes/sample-design/edits';
import {
    destroy as redo,
    store as undo,
} from '@/routes/sample-design/edits/reversion';
import { store as motion } from '@/routes/sample-design/motions';
import { store as text } from '@/routes/sample-design/texts';
import type {
    AppColor,
    EditorPreview,
    InspectedElement,
    VisualEditSummary,
} from '@/types';

// The designer itself, on a sample page anyone can try: the same panel and
// the same point-and-edit overlay as in an app, with edits kept only in the
// visitor's session. The home page shows it in a frame.
const props = defineProps<{
    preview: EditorPreview;
    edits: VisualEditSummary[];
    colors: AppColor[];
    element?: InspectedElement | null;
}>();

const designing = ref(true);

// Opened on its own rather than in the home page's frame, the page needs a
// way back to the site and on to starting an app.
const framed = ref(true);

onMounted(() => {
    framed.value = window.self !== window.top;
});

const state = useAppPreview({
    projectId: () => 'sample',
    preview: () => props.preview,
    element: () => props.element,
    edits: () => props.edits,
    colors: () => props.colors,
    designing,
    sample: {
        entry: sampleApp().url,
        edit: edit().url,
        text: text().url,
        motion: motion().url,
        undo: (id) => undo(id).url,
        redo: (id) => redo(id).url,
    },
});

// Open on the sample's button, so the first thing a visitor sees is a
// picked part and the panel ready to change it.
const stopPicking = watch(
    () => state.parts,
    (parts) => {
        const button = parts.findIndex((part) => part.kind === 'Button');

        if (button >= 0) {
            stopPicking();

            if (state.selected === null) {
                state.pickPart(button);
            }
        }
    },
);

// Tell the page around this one that the sample is open, so it can keep
// its picture up until then rather than show an empty box.
watch(
    () => state.opened,
    (opened) => {
        if (opened && window.parent !== window) {
            window.parent.postMessage(
                { type: 'sample-design-ready' },
                window.location.origin,
            );
        }
    },
);
</script>

<template>
    <Head title="Try the designer">
        <meta head-key="robots" name="robots" content="noindex, nofollow" />
        <link head-key="canonical" rel="canonical" href="/" />
    </Head>

    <div
        class="flex h-svh flex-col bg-background text-foreground"
        data-test="sample-design"
    >
        <h1 class="sr-only">Designer</h1>
        <header
            class="flex shrink-0 items-center gap-3 border-b px-3 py-2 text-sm"
        >
            <Link
                v-if="!framed"
                :href="home()"
                class="flex min-h-11 shrink-0 items-center pointer-fine:min-h-8"
            >
                <AppLogo />
            </Link>
            <p class="min-w-0 flex-1 text-muted-foreground">
                A sample. Nothing is saved.
            </p>
            <Form
                v-bind="destroy.form()"
                :options="{ preserveScroll: true }"
                @success="state.deselect()"
                v-slot="{ processing }"
            >
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-11 sm:h-8"
                    :disabled="processing"
                    data-test="sample-start-again"
                >
                    <RotateCcw class="size-4" />
                    Start again
                </Button>
            </Form>
            <Button v-if="!framed" size="sm" class="h-11 sm:h-8" as-child>
                <Link :href="$page.props.auth?.user ? index() : register()">
                    {{ $page.props.auth?.user ? 'Your apps' : 'Start an app' }}
                </Link>
            </Button>
        </header>

        <main class="flex min-h-0 flex-1 flex-col gap-2 p-2 md:flex-row">
            <div class="min-h-0 flex-1">
                <AppPreview
                    project-id="sample"
                    :preview="preview"
                    :state="state"
                    sample
                />
            </div>
            <DesignPanel
                :class="[
                    'shrink-0 rounded-lg border transition-[height] md:h-auto md:w-80',
                    state.selected ? 'h-[60%]' : 'h-[45%]',
                ]"
                project-id="sample"
                :preview="preview"
                :edits="edits"
                :state="state"
                sample
            />
        </main>
    </div>
</template>
