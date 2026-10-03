<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: 'Check your email',
        description: 'Open the link we sent you to confirm your address.',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="Check your email" />

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-6 text-sm font-medium text-emerald-600 dark:text-emerald-400"
    >
        A new verification link has been sent to the email address you provided
        during registration.
    </div>

    <Form v-bind="send.form()" class="space-y-6" v-slot="{ processing }">
        <Button :disabled="processing" variant="secondary">
            <Spinner v-if="processing" />
            Resend verification email
        </Button>

        <TextLink :href="logout()" as="button" class="block text-sm">
            Log out
        </TextLink>
    </Form>
</template>
