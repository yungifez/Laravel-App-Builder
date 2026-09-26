<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import { ref } from 'vue';
import ProjectUnderstandingController from '@/actions/App/Http/Controllers/ProjectUnderstandingController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

const props = withDefaults(
    defineProps<{
        projectId: number;
        revision: string;
        part: string;
        text: string;
        label: string;
        rows?: number;
        hint?: string;
        // "icon" puts a pencil in the corner instead of a text button, for
        // places where many parts sit side by side.
        variant?: 'text' | 'icon';
    }>(),
    { rows: 4, hint: undefined, variant: 'text' },
);

const editing = ref(false);
</script>

<template>
    <div class="min-w-0">
        <div
            v-if="!editing && variant === 'icon'"
            class="group/part relative min-w-0"
        >
            <slot />
            <Button
                variant="ghost"
                size="icon"
                class="absolute -top-1.5 right-0 size-11 text-muted-foreground select-none hover:text-foreground sm:size-8 pointer-fine:opacity-0 pointer-fine:group-hover/part:opacity-100 pointer-fine:focus-visible:opacity-100"
                :aria-label="`Change ${label}`"
                :title="`Change ${label}`"
                :data-test="`edit-${part}`"
                @click="editing = true"
            >
                <Pencil class="size-3.5" />
            </Button>
        </div>

        <template v-else-if="!editing">
            <slot />
            <Button
                variant="ghost"
                size="sm"
                class="mt-1 -ml-3 h-11 font-normal text-muted-foreground select-none sm:h-8"
                :data-test="`edit-${part}`"
                @click="editing = true"
            >
                Change {{ label }}
            </Button>
        </template>

        <Form
            v-else
            v-bind="ProjectUnderstandingController.update.form(props.projectId)"
            class="space-y-3"
            :options="{ preserveScroll: true }"
            @success="editing = false"
            v-slot="{ errors, processing }"
        >
            <input type="hidden" name="part" :value="part" />
            <input type="hidden" name="revision" :value="revision" />
            <label :for="`notes-${part}`" class="sr-only">{{ label }}</label>
            <textarea
                :id="`notes-${part}`"
                name="body"
                :rows="rows"
                :value="text"
                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
            />
            <p v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
            <InputError :message="errors.body ?? errors.part" />
            <div class="flex gap-2">
                <Button
                    :disabled="processing"
                    class="h-11 sm:h-9"
                    :data-test="`save-${part}`"
                >
                    Save
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    class="h-11 sm:h-9"
                    @click="editing = false"
                >
                    Cancel
                </Button>
            </div>
        </Form>
    </div>
</template>
