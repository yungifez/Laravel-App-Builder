<script setup lang="ts">
import { Form, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ProjectNotesDraftController from '@/actions/App/Http/Controllers/ProjectNotesDraftController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import type { NotesDraft } from '@/types';

const props = defineProps<{
    projectId: string;
    draft: NotesDraft;
}>();

const drafting = computed(() => props.draft.status === 'drafting');

const { start, stop } = usePoll(
    3000,
    { only: ['draft', 'about', 'areas', 'revision'] },
    { autoStart: false },
);

watch(drafting, (value) => (value ? start() : stop()), { immediate: true });

// Nothing starts ticked: the owner reads each part and ticks what is right,
// and only that is kept.
const purposeRight = ref(false);
const areasRight = ref<string[]>([]);
const ticked = computed(
    () => purposeRight.value || areasRight.value.length > 0,
);

function tick(key: string, right: boolean): void {
    areasRight.value = right
        ? [...areasRight.value, key]
        : areasRight.value.filter((item) => item !== key);
}

// What backs an area without a model: the app's own tests and pages.
function evidence(area: NotesDraft['areas'][number]): string {
    const tests =
        area.tests === null
            ? 'Your tests could not run, so this is from reading the code'
            : area.tests === 0
              ? 'No test of yours runs this'
              : `${area.tests} of your tests run this`;

    return area.pages.length === 0
        ? tests
        : `${tests} · used on ${area.pages.slice(0, 4).join(', ')}`;
}
</script>

<template>
    <section
        class="max-w-2xl space-y-4 rounded-lg border p-4"
        data-test="notes-draft"
    >
        <template v-if="draft.status === 'drafting'">
            <Heading
                variant="small"
                title="Exploring your app"
                description="I am running your app's tests and reading its code. This takes a few minutes, and you can leave this page."
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
                title="I explored your app and wrote this"
                description="Read each part and tick the ones that are right. I use only what you tick, and you can change any of it later."
            />

            <label
                v-if="draft.purpose"
                class="flex items-start gap-3 text-sm"
                data-test="draft-purpose"
            >
                <Checkbox
                    class="mt-0.5"
                    :model-value="purposeRight"
                    @update:model-value="purposeRight = $event === true"
                />
                <span>{{ draft.purpose }}</span>
            </label>

            <ul class="divide-y border-y">
                <li
                    v-for="area in draft.areas"
                    :key="area.key"
                    class="flex items-start gap-3 py-3 text-sm"
                    :data-test="`draft-area-${area.key}`"
                >
                    <Checkbox
                        :id="`draft-area-${area.key}`"
                        class="mt-0.5"
                        :model-value="areasRight.includes(area.key)"
                        @update:model-value="tick(area.key, $event === true)"
                    />
                    <div class="min-w-0 flex-1 space-y-1">
                        <label
                            :for="`draft-area-${area.key}`"
                            class="font-medium"
                            >{{ area.name }}</label
                        >
                        <p
                            class="text-xs text-muted-foreground"
                            data-test="draft-evidence"
                        >
                            {{ evidence(area) }}
                        </p>
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
                    </div>
                </li>
            </ul>

            <div class="flex flex-wrap gap-3">
                <Form
                    v-bind="ProjectNotesDraftController.store.form(projectId)"
                    :transform="
                        () => ({ purpose: purposeRight, areas: areasRight })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                >
                    <Button
                        :disabled="processing || !ticked"
                        class="h-11 select-none sm:h-9"
                        data-test="keep-draft"
                    >
                        Keep what I ticked
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
