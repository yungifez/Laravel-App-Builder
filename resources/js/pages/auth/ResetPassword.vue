<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { fieldError, focusFirstError } from '@/lib/forms';
import { update } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Set a new password',
        description: 'Then log in with it.',
    },
});

const props = defineProps<{
    token: string;
    email: string;
    passwordRules: string;
}>();

const inputEmail = ref(props.email);
</script>

<template>
    <Head title="Set a new password" />

    <Form
        @error="focusFirstError"
        v-bind="update.form()"
        :transform="(data) => ({ ...data, token, email })"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    v-bind="fieldError(errors, 'email')"
                    autocomplete="email"
                    v-model="inputEmail"
                    class="mt-1 block h-11 w-full sm:h-9"
                    readonly
                />
                <InputError
                    id="email-error"
                    :message="errors.email"
                    class="mt-2"
                />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    v-bind="fieldError(errors, 'password')"
                    autocomplete="new-password"
                    class="mt-1 block h-11 w-full sm:h-9"
                    autofocus
                    placeholder="Password"
                    :passwordrules="passwordRules"
                />
                <InputError id="password-error" :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation"> Confirm password </Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    v-bind="fieldError(errors, 'password_confirmation')"
                    autocomplete="new-password"
                    class="mt-1 block h-11 w-full sm:h-9"
                    placeholder="Confirm password"
                    :passwordrules="passwordRules"
                />
                <InputError
                    id="password_confirmation-error"
                    :message="errors.password_confirmation"
                />
            </div>

            <Button
                type="submit"
                class="mt-4 h-11 w-full sm:h-9"
                :disabled="processing"
                data-test="reset-password-button"
            >
                <Spinner v-if="processing" />
                Save the new password
            </Button>
        </div>
    </Form>
</template>
