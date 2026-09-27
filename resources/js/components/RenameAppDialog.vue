<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import ProjectNameController from '@/actions/App/Http/Controllers/ProjectNameController';
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

defineProps<{ projectId: number; name: string }>();

const open = defineModel<boolean>('open', { required: true });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Rename your app</DialogTitle>
                <DialogDescription>
                    Its web address stays the same.
                </DialogDescription>
            </DialogHeader>
            <Form
                v-bind="ProjectNameController.update.form(projectId)"
                :options="{ preserveScroll: true }"
                class="flex flex-col gap-3"
                v-slot="{ errors, processing }"
                @success="open = false"
            >
                <Input
                    name="name"
                    :default-value="name"
                    required
                    maxlength="255"
                    autofocus
                    aria-label="Name of your app"
                    class="h-11 sm:h-9"
                    data-test="app-name"
                />
                <InputError :message="errors.name" />
                <Button
                    :disabled="processing"
                    class="h-11 self-end select-none sm:h-9"
                    data-test="app-rename-save"
                >
                    Save
                </Button>
            </Form>
        </DialogContent>
    </Dialog>
</template>
