<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import ContactController from '@/actions/App/Http/Controllers/ContactController';
import InputError from '@/components/InputError.vue';
import PageMeta from '@/components/PageMeta.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fieldError, focusFirstError } from '@/lib/forms';

defineProps<{
    senderName: string | null;
    senderEmail: string | null;
    honeypot: {
        enabled: boolean;
        nameFieldName: string;
        validFromFieldName: string;
        encryptedValidFrom: string;
    };
}>();
</script>

<template>
    <PageMeta
        title="Contact"
        description="Write to us. A person reads every message and replies by email."
    />

    <section
        class="mx-auto grid max-w-7xl items-start gap-12 px-4 pt-20 pb-24 sm:px-8 sm:pt-32 lg:grid-cols-2 lg:gap-16"
    >
        <h1
            class="max-w-xl font-display text-4xl leading-[1.05] font-medium tracking-[-0.035em] text-balance sm:text-6xl"
        >
            Write to us.
            <span class="text-muted-foreground"
                >A&nbsp;person reads every message and replies to your
                email.</span
            >
        </h1>

        <Form
            v-bind="ContactController.store.form()"
            reset-on-success
            @error="focusFirstError"
            class="space-y-6 rounded-md bg-muted px-5 py-6 sm:px-8 sm:py-8"
            v-slot="{ errors, processing }"
        >
            <!-- People never see these. A program that fills in every
                     field, or sends the form at once, gives itself away. -->
            <div v-if="honeypot.enabled" class="hidden" aria-hidden="true">
                <input
                    :name="honeypot.nameFieldName"
                    type="text"
                    value=""
                    tabindex="-1"
                    autocomplete="off"
                />
                <input
                    :name="honeypot.validFromFieldName"
                    type="hidden"
                    :value="honeypot.encryptedValidFrom"
                />
            </div>

            <div class="grid gap-2">
                <Label for="name">Your name</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="senderName ?? ''"
                    v-bind="fieldError(errors, 'name')"
                    class="h-11 bg-background sm:h-9"
                    required
                    autocomplete="name"
                />
                <InputError id="name-error" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Your email</Label>
                <Input
                    id="email"
                    name="email"
                    type="email"
                    :default-value="senderEmail ?? ''"
                    v-bind="fieldError(errors, 'email')"
                    class="h-11 bg-background sm:h-9"
                    required
                    autocomplete="email"
                />
                <InputError id="email-error" :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="message">Message</Label>
                <textarea
                    id="message"
                    name="message"
                    rows="6"
                    required
                    minlength="10"
                    maxlength="5000"
                    v-bind="fieldError(errors, 'message')"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
                />
                <InputError id="message-error" :message="errors.message" />
            </div>

            <Button
                :disabled="processing"
                class="h-11 sm:h-9"
                data-test="contact-send"
            >
                {{ processing ? 'Sending…' : 'Send' }}
            </Button>
        </Form>
    </section>
</template>
