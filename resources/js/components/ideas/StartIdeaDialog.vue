<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import ExperimentController from '@/actions/App/Http/Controllers/ExperimentController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

defineProps<{ projectId: string }>();

const open = defineModel<boolean>('open', { required: true });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Try an idea</DialogTitle>
                <DialogDescription>
                    Your app stays as it is until you use the idea.
                </DialogDescription>
            </DialogHeader>
            <Form
                v-bind="ExperimentController.store.form(projectId)"
                class="flex flex-col gap-3"
                v-slot="{ errors, processing }"
                @success="open = false"
            >
                <Input
                    name="name"
                    required
                    maxlength="80"
                    autofocus
                    aria-label="Name of the idea"
                    placeholder="A bigger booking form"
                    class="h-11 sm:h-9"
                    data-test="idea-name"
                />
                <InputError :message="errors.name" />
                <Button
                    :disabled="processing"
                    class="h-11 self-end select-none sm:h-9"
                    data-test="idea-start"
                >
                    Start
                </Button>
            </Form>
        </DialogContent>
    </Dialog>
</template>
