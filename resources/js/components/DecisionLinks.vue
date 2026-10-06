<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import FeatureRequestAssumptionController from '@/actions/App/Http/Controllers/FeatureRequestAssumptionController';
import { show as showProject } from '@/routes/projects';

// What the owner can do about one thing decided for them: agree, so
// later changes follow it, or change it in this chat. Small links, so a
// decision stays one line on a phone.
defineProps<{
    projectId: string;
    changeId: string;
    text: string;
    kept: boolean;
}>();
</script>

<template>
    <span class="ml-1 inline-flex items-center gap-2 whitespace-nowrap">
        <span
            v-if="kept"
            class="inline-flex items-center gap-1 text-foreground"
            data-test="decision-kept"
        >
            <Check class="size-3" /> You chose this
        </span>
        <Form
            v-else
            v-bind="FeatureRequestAssumptionController.store.form(changeId)"
            :options="{ preserveScroll: true, preserveState: true }"
            class="inline"
            v-slot="{ processing }"
        >
            <input type="hidden" name="assumption" :value="text" />
            <button
                :disabled="processing"
                class="underline underline-offset-4 select-none hover:text-foreground"
                data-test="decision-keep"
            >
                Agree
            </button>
        </Form>
        <Link
            :href="
                showProject(projectId, {
                    query: {
                        change: changeId,
                        ask: `Change this: “${text}”\n\nInstead, `,
                    },
                })
            "
            class="underline underline-offset-4 select-none hover:text-foreground"
            data-test="decision-change"
        >
            Change
        </Link>
    </span>
</template>
