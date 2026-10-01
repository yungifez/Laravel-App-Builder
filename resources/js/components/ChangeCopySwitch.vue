<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import FeatureRequestPreviewController from '@/actions/App/Http/Controllers/FeatureRequestPreviewController';

// Before and after the change the owner decides on, in one switch that
// never moves. "After" starts the change's copy when none is running.
defineProps<{
    featureRequestId: string;
    withChange: boolean;
    copyStarted: boolean;
}>();

const emit = defineEmits<{ show: [withChange: boolean] }>();

const side = (on: boolean) => [
    'min-h-11 rounded px-2.5 select-none sm:min-h-8',
    on
        ? 'bg-background shadow-sm'
        : 'text-muted-foreground hover:text-foreground',
];
</script>

<template>
    <div
        class="flex items-center rounded-md bg-muted p-0.5 text-xs"
        role="group"
        aria-label="Show your app"
        data-test="change-copy-switch"
    >
        <button
            type="button"
            :aria-pressed="!withChange"
            :class="side(!withChange)"
            title="Your app without this change"
            data-test="change-copy-hide"
            @click="emit('show', false)"
        >
            Before
        </button>
        <button
            v-if="copyStarted"
            type="button"
            :aria-pressed="withChange"
            :class="side(withChange)"
            title="Your app with this change, not kept yet"
            data-test="change-copy-show"
            @click="emit('show', true)"
        >
            After
        </button>
        <Form
            v-else
            v-bind="
                FeatureRequestPreviewController.store.form(featureRequestId)
            "
            :options="{ preserveScroll: true, preserveState: true }"
            v-slot="{ processing }"
            class="contents"
            @success="emit('show', true)"
        >
            <button
                :aria-pressed="false"
                :class="[side(false), 'disabled:opacity-50']"
                :disabled="processing"
                title="Your app with this change, not kept yet"
                data-test="change-copy-start"
            >
                After
            </button>
        </Form>
    </div>
</template>
