<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
</script>

<template>
    <!-- Bring in an app the owner already has. The page gives the button
         that opens it. -->
    <Dialog>
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Bring in an app you have</DialogTitle>
                <DialogDescription>
                    I make my own copy. Your app stays as it is until you keep a
                    change.
                </DialogDescription>
            </DialogHeader>

            <Form
                v-bind="ProjectController.store.form()"
                class="space-y-6"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input id="name" name="name" required placeholder="Acme" />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="source_path">Where it is</Label>
                    <Input
                        id="source_path"
                        name="source_path"
                        required
                        placeholder="/srv/acme"
                        class="font-mono"
                    />
                    <InputError :message="errors.source_path" />
                </div>

                <Button
                    :disabled="processing"
                    class="h-11 w-full select-none sm:h-9 sm:w-auto"
                    data-test="create-project-button"
                >
                    Bring it in
                </Button>
            </Form>
        </DialogContent>
    </Dialog>
</template>
