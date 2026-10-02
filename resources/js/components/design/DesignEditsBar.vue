<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { Check, LoaderCircle } from '@lucide/vue';
import { onUnmounted, ref, watch } from 'vue';
import DesignEditsController from '@/actions/App/Http/Controllers/DesignEditsController';
import { Button } from '@/components/ui/button';
import type { DesignEdits } from '@/types';

// Design edits on the app wait here until the owner keeps them. Keeping
// runs the app's checks first; only edits that pass join the app.
const props = defineProps<{
    projectId: string;
    waiting: DesignEdits | null;
}>();

// Shown for a moment once the checks let the edits in, so the bar does
// not just vanish.
const kept = ref(false);
let poll: ReturnType<typeof setInterval> | undefined;
let fade: ReturnType<typeof setTimeout> | undefined;

watch(
    () => props.waiting?.checking ?? false,
    (checking) => {
        clearInterval(poll);

        if (checking) {
            poll = setInterval(
                () =>
                    router.reload({
                        only: ['designEdits', 'edits', 'preview'],
                    }),
                3000,
            );
        }
    },
    { immediate: true },
);

watch(
    () => props.waiting,
    (now, before) => {
        if (before?.checking && now === null) {
            kept.value = true;
            clearTimeout(fade);
            fade = setTimeout(() => (kept.value = false), 4000);
        }
    },
);

onUnmounted(() => {
    clearInterval(poll);
    clearTimeout(fade);
});
</script>

<template>
    <div
        v-if="waiting || kept"
        class="border-t px-3 py-2 text-xs"
        aria-live="polite"
        data-test="design-edits"
    >
        <p
            v-if="kept"
            class="flex h-8 items-center gap-1.5 text-muted-foreground"
        >
            <Check class="size-3.5" />
            Kept in your app
        </p>
        <template v-else-if="waiting">
            <p
                v-if="waiting.problem"
                class="pb-2 text-destructive"
                role="alert"
                data-test="design-edits-problem"
            >
                {{ waiting.problem }}
            </p>
            <div class="flex items-center gap-2">
                <p
                    v-if="waiting.checking"
                    class="flex min-w-0 flex-1 items-center gap-1.5 text-muted-foreground"
                >
                    <LoaderCircle class="size-3.5 shrink-0 animate-spin" />
                    Checking your edits
                </p>
                <p v-else class="min-w-0 flex-1 text-muted-foreground">
                    {{
                        waiting.edits === 1
                            ? '1 edit not kept yet'
                            : `${waiting.edits} edits not kept yet`
                    }}
                </p>
                <Form
                    v-if="!waiting.checking"
                    v-bind="DesignEditsController.destroy.form(projectId)"
                    v-slot="{ processing }"
                    :options="{ preserveScroll: true }"
                >
                    <Button
                        type="submit"
                        variant="ghost"
                        size="sm"
                        class="h-11 sm:h-8"
                        :disabled="processing"
                        data-test="discard-design-edits"
                    >
                        Undo all
                    </Button>
                </Form>
                <Form
                    v-if="!waiting.checking"
                    v-bind="DesignEditsController.store.form(projectId)"
                    v-slot="{ processing, errors }"
                    :options="{ preserveScroll: true }"
                    class="flex items-center gap-2"
                >
                    <span
                        v-if="errors.keep"
                        class="text-destructive"
                        role="alert"
                        >{{ errors.keep }}</span
                    >
                    <Button
                        type="submit"
                        size="sm"
                        class="h-11 sm:h-8"
                        :disabled="processing"
                        data-test="keep-design-edits"
                    >
                        Keep
                    </Button>
                </Form>
            </div>
        </template>
    </div>
</template>
