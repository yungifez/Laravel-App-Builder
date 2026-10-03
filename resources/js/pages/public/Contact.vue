<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import ContactController from '@/actions/App/Http/Controllers/ContactController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps<{
    name: string | null;
    email: string | null;
    honeypot: {
        enabled: boolean;
        nameFieldName: string;
        validFromFieldName: string;
        encryptedValidFrom: string;
    };
}>();
</script>

<template>
    <Head title="Contact" />

    <section class="bg-muted/50">
        <div class="mx-auto max-w-6xl px-4 pt-16 pb-16 sm:px-6 sm:pt-24">
            <h1
                class="max-w-2xl font-display text-4xl leading-[1.05] sm:text-5xl"
            >
                Write to us
            </h1>
            <p class="mt-4 max-w-xl text-lg text-muted-foreground">
                A question, a problem or an idea. A person reads every message
                and replies to your email.
            </p>

            <Form
                v-bind="ContactController.store.form()"
                reset-on-success
                class="mt-10 max-w-xl space-y-6"
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
                        :default-value="name ?? ''"
                        required
                        autocomplete="name"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Your email</Label>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        :default-value="email ?? ''"
                        required
                        autocomplete="email"
                    />
                    <InputError :message="errors.email" />
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
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm"
                    />
                    <InputError :message="errors.message" />
                </div>

                <Button :disabled="processing" data-test="contact-send">
                    {{ processing ? 'Sending…' : 'Send' }}
                </Button>
            </Form>
        </div>
    </section>
</template>
