<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import ExperimentMergeController from '@/actions/App/Http/Controllers/ExperimentMergeController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Idea } from '@/types';

defineProps<{ idea: Idea }>();

const open = defineModel<boolean>('open', { required: true });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Use “{{ idea.name }}” in your app?</DialogTitle>
                <DialogDescription>
                    Its kept changes join your app. Put your app online
                    afterwards to share them.
                </DialogDescription>
            </DialogHeader>
            <Form
                v-bind="ExperimentMergeController.store.form(idea.id)"
                class="flex flex-col gap-3"
                v-slot="{ errors, processing }"
                @success="open = false"
            >
                <InputError :message="errors.experiment" />
                <Button
                    :disabled="processing"
                    class="h-11 self-end select-none sm:h-9"
                    data-test="idea-use-confirm"
                >
                    Use it
                </Button>
            </Form>
        </DialogContent>
    </Dialog>
</template>
