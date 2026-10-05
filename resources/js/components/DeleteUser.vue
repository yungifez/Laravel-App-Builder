<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useTemplateRef } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { contact } from '@/routes';
import { download } from '@/routes/projects';

defineProps<{
    apps: { id: string; name: string; live: boolean }[];
}>();

const passwordInput = useTemplateRef('passwordInput');
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Delete account"
            description="Delete your account and every app you made here"
        />
        <div
            class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10"
        >
            <div class="relative space-y-0.5 text-red-600 dark:text-red-100">
                <p class="font-medium">This cannot be undone</p>
                <p class="text-sm">
                    Your apps go with your account, and I cannot bring them
                    back.
                    <template v-if="apps.length > 0"
                        >Download the code of each app you want to keep
                        first.</template
                    >
                </p>
            </div>
            <ul
                v-if="apps.length > 0"
                class="divide-y divide-red-100 border-y border-red-100 text-sm dark:divide-red-200/10 dark:border-red-200/10"
                data-test="delete-user-apps"
            >
                <li
                    v-for="app in apps"
                    :key="app.id"
                    class="flex items-center justify-between gap-4 py-2"
                >
                    <span class="min-w-0">
                        <span class="block truncate font-medium">{{
                            app.name
                        }}</span>
                        <span
                            v-if="app.live"
                            class="block text-red-600 dark:text-red-100"
                        >
                            It is online. Deleting your account does not take it
                            offline, so
                            <a
                                :href="contact().url"
                                class="underline underline-offset-4"
                                >write to us</a
                            >
                            to move it or take it down.
                        </span>
                    </span>
                    <a
                        :href="download(app.id).url"
                        download
                        class="inline-flex min-h-11 shrink-0 items-center font-medium underline-offset-4 hover:underline sm:min-h-8"
                        >Download</a
                    >
                </li>
            </ul>
            <Dialog>
                <DialogTrigger as-child>
                    <Button variant="destructive" data-test="delete-user-button"
                        >Delete account</Button
                    >
                </DialogTrigger>
                <DialogContent>
                    <Form
                        v-bind="ProfileController.destroy.form()"
                        reset-on-success
                        @error="() => passwordInput?.focus()"
                        :options="{
                            preserveScroll: true,
                        }"
                        class="space-y-6"
                        v-slot="{ errors, processing, reset, clearErrors }"
                    >
                        <DialogHeader class="space-y-3">
                            <DialogTitle
                                >Delete your account for good?</DialogTitle
                            >
                            <DialogDescription>
                                Your account and every app you made here are
                                deleted for good, and a paid plan stops at once.
                                Enter your password to confirm.
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-2">
                            <Label for="password" class="sr-only"
                                >Password</Label
                            >
                            <PasswordInput
                                id="password"
                                name="password"
                                ref="passwordInput"
                                placeholder="Password"
                            />
                            <InputError :message="errors.password" />
                            <InputError :message="errors.account" />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button
                                    variant="secondary"
                                    @click="
                                        () => {
                                            clearErrors();
                                            reset();
                                        }
                                    "
                                >
                                    Cancel
                                </Button>
                            </DialogClose>

                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="processing"
                                data-test="confirm-delete-user-button"
                            >
                                Delete account
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
