<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Compass } from '@lucide/vue';
import { computed } from 'vue';
import ProjectExplorationController from '@/actions/App/Http/Controllers/ProjectExplorationController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { Exploration } from '@/types';

const props = defineProps<{
    projectId: string;
    exploration: Exploration;
}>();

// The owner reads the cost before they choose: tokens always, money when
// the model has a price.
const cost = computed(() => {
    const tokens = `about ${props.exploration.tokens.toLocaleString()} AI tokens`;

    return props.exploration.cost_usd === null
        ? tokens
        : `${tokens} (about $${Math.max(props.exploration.cost_usd, 0.01).toFixed(2)})`;
});
</script>

<template>
    <section
        class="max-w-2xl space-y-4 rounded-lg border p-4"
        data-test="explore-app"
    >
        <div class="flex items-start gap-3">
            <Compass class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
            <Heading
                variant="small"
                title="Let me get to know your app"
                description="I run your app's tests and read its code, then write down how it works. I make better changes when I know this."
            />
        </div>

        <ul class="space-y-1 pl-8 text-sm">
            <li data-test="explore-cost">
                This uses {{ cost }}, once. It takes a few minutes and changes
                nothing in your app.
            </li>
            <li>
                You check what I write, part by part. I use only the parts you
                keep.
            </li>
        </ul>

        <Form
            v-bind="ProjectExplorationController.store.form(projectId)"
            :options="{ preserveScroll: true }"
            v-slot="{ errors, processing }"
            class="pl-8"
        >
            <Button
                :disabled="processing"
                class="h-11 select-none sm:h-9"
                data-test="explore-start"
            >
                Explore my app
            </Button>
            <InputError class="mt-2" :message="errors.explore" />
        </Form>
    </section>
</template>
