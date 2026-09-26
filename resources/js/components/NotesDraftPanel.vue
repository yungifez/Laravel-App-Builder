<script setup lang="ts">
import { Form, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import ProjectNotesDraftController from '@/actions/App/Http/Controllers/ProjectNotesDraftController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { NotesDraft } from '@/types';

const props = defineProps<{
    projectId: number;
    draft: NotesDraft;
}>();

const drafting = computed(() => props.draft.status === 'drafting');

const { start, stop } = usePoll(
    3000,
    { only: ['draft', 'about', 'areas', 'revision'] },
    { autoStart: false },
);

watch(drafting, (value) => (value ? start() : stop()), { immediate: true });
</script>

<template>
    <section
        class="max-w-2xl space-y-4 rounded-lg border p-4"
        data-test="notes-draft"
    >
        <template v-if="draft.status === 'drafting'">
            <Heading
                variant="small"
                title="Reading your app"
                description="I am reading your app so I can describe it. This takes a minute or two."
            />
        </template>

        <template v-else-if="draft.status === 'failed'">
            <Heading variant="small" title="I could not describe your app" />
            <p class="text-sm text-muted-foreground">{{ draft.error }}</p>
            <Form
                v-bind="ProjectNotesDraftController.destroy.form(projectId)"
                :options="{ preserveScroll: true }"
                v-slot="{ processing }"
            >
                <Button
                    variant="outline"
                    :disabled="processing"
                    class="h-11 select-none sm:h-9"
                >
                    OK
                </Button>
            </Form>
        </template>

        <template v-else>
            <Heading
                variant="small"
                title="I read your app and wrote this"
                description="Check it. I only use it after you keep it, and you can change any part later."
            />

            <p v-if="draft.purpose" class="text-sm">{{ draft.purpose }}</p>

            <ul class="divide-y border-y">
                <li
                    v-for="area in draft.areas"
                    :key="area.key"
                    class="space-y-1 py-3 text-sm"
                >
                    <h3 class="font-medium">{{ area.name }}</h3>
                    <p v-if="area.summary" class="text-muted-foreground">
                        {{ area.summary }}
                    </p>
                    <p v-if="area.behaviors.length">
                        People can: {{ area.behaviors.join(', ') }}.
                    </p>
                    <ul
                        v-if="area.rules.length"
                        class="list-disc space-y-0.5 pl-5"
                    >
                        <li v-for="rule in area.rules" :key="rule">
                            {{ rule }}
                        </li>
                    </ul>
                </li>
            </ul>

            <div class="flex flex-wrap gap-3">
                <Form
                    v-bind="ProjectNotesDraftController.store.form(projectId)"
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                >
                    <Button
                        :disabled="processing"
                        class="h-11 select-none sm:h-9"
                        data-test="keep-draft"
                    >
                        Keep this
                    </Button>
                    <InputError class="mt-2" :message="errors.draft" />
                </Form>
                <Form
                    v-bind="ProjectNotesDraftController.destroy.form(projectId)"
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                >
                    <Button
                        variant="ghost"
                        :disabled="processing"
                        class="h-11 select-none sm:h-9"
                        data-test="discard-draft"
                    >
                        Discard
                    </Button>
                </Form>
            </div>
        </template>
    </section>
</template>
