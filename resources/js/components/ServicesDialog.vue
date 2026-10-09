<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CircleCheck,
    CreditCard,
    ExternalLink,
    Mail,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ProjectServiceController from '@/actions/App/Http/Controllers/ProjectServiceController';
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
import { Label } from '@/components/ui/label';
import type { AppService } from '@/types';

const props = defineProps<{ projectId: string; services: AppService[] }>();

const open = defineModel<boolean>('open', { required: true });

const icons = { payments: CreditCard, email: Mail } as const;

// One question at a time: which service, then its keys.
const picked = ref<string | null>(null);
const service = computed(
    () => props.services.find((s) => s.key === picked.value) ?? null,
);

watch(open, (isOpen) => {
    if (!isOpen) {
        picked.value = null;
    }
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <template v-if="service === null">
                <DialogHeader>
                    <DialogTitle>Payments and email</DialogTitle>
                    <DialogDescription>
                        Connect your app to a service, and I will build it in.
                    </DialogDescription>
                </DialogHeader>
                <ul class="-mx-2 flex flex-col" data-test="services">
                    <li v-for="item in services" :key="item.key">
                        <button
                            type="button"
                            class="flex min-h-14 w-full items-center gap-3 rounded-md px-2 py-2 text-left hover:bg-accent"
                            :data-test="`service-${item.key}`"
                            @click="picked = item.key"
                        >
                            <component
                                :is="
                                    icons[item.key as keyof typeof icons] ??
                                    CreditCard
                                "
                                class="size-5 shrink-0 text-muted-foreground"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium">{{
                                    item.name
                                }}</span>
                                <span
                                    class="block text-sm text-muted-foreground"
                                    >{{ item.provider }}</span
                                >
                            </span>
                            <span
                                v-if="item.connected"
                                class="flex items-center gap-1 text-sm text-green-700 dark:text-green-500"
                                :data-test="`service-${item.key}-connected`"
                            >
                                <CircleCheck class="size-4" />
                                Connected
                            </span>
                        </button>
                    </li>
                </ul>
            </template>

            <template v-else>
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <Button
                            variant="ghost"
                            size="icon"
                            class="-ml-2 size-8"
                            aria-label="Back"
                            data-test="service-back"
                            @click="picked = null"
                        >
                            <ArrowLeft class="size-4" />
                        </Button>
                        {{ service.name }} with {{ service.provider }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ service.about }}
                        <template v-if="service.connected">
                            Paste new keys to change them.
                        </template>
                    </DialogDescription>
                </DialogHeader>
                <Form
                    v-bind="
                        ProjectServiceController.store.form({
                            project: projectId,
                            service: service.key,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    class="flex flex-col gap-4"
                    v-slot="{ errors, processing }"
                    @success="open = false"
                >
                    <div
                        v-for="(field, index) in service.fields"
                        :key="field.name"
                        class="grid gap-1.5"
                    >
                        <Label :for="`service-${field.name}`">{{
                            field.label
                        }}</Label>
                        <Input
                            :id="`service-${field.name}`"
                            :name="`keys[${field.name}]`"
                            :type="field.secret ? 'password' : 'text'"
                            :placeholder="field.hint ?? undefined"
                            autocomplete="off"
                            spellcheck="false"
                            required
                            :autofocus="index === 0"
                            class="h-11 font-mono sm:h-9"
                            :data-test="`service-field-${field.name}`"
                        />
                        <InputError :message="errors[`keys.${field.name}`]" />
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <a
                            :href="service.keys_at"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex min-h-11 items-center gap-1 text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline sm:min-h-0"
                        >
                            Get your keys from {{ service.provider }}
                            <ExternalLink class="size-3.5" />
                        </a>
                        <Button
                            :disabled="processing"
                            class="ml-auto h-11 select-none sm:h-9"
                            data-test="service-save"
                        >
                            {{
                                service.connected
                                    ? 'Save new keys'
                                    : 'Add to my app'
                            }}
                        </Button>
                    </div>
                </Form>
            </template>
        </DialogContent>
    </Dialog>
</template>
