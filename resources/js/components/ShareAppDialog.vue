<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Check, Copy } from '@lucide/vue';
import { computed, ref } from 'vue';
import ProjectShareController from '@/actions/App/Http/Controllers/ProjectShareController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    projectId: string;
    share: { url: string; expires_at: string } | null;
}>();

const open = defineModel<boolean>('open', { required: true });

// The dialog only opens in the browser, so the owner's own date settings
// apply.
const until = computed(() =>
    props.share === null
        ? ''
        : new Date(props.share.expires_at).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'long',
          }),
);

const copied = ref(false);

async function copy(): Promise<void> {
    if (props.share === null) {
        return;
    }

    await navigator.clipboard.writeText(props.share.url);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Let others try your app</DialogTitle>
                <DialogDescription>
                    Anyone with the link can use your app as it is here, with no
                    account. It is not put online.
                </DialogDescription>
            </DialogHeader>

            <div v-if="share" class="flex flex-col gap-3" data-test="share">
                <div class="flex gap-2">
                    <Input
                        :model-value="share.url"
                        readonly
                        aria-label="Link to your app"
                        class="h-11 sm:h-9"
                        data-test="share-url"
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                    <Button
                        class="h-11 shrink-0 select-none sm:h-9"
                        data-test="share-copy"
                        @click="copy"
                    >
                        <component :is="copied ? Check : Copy" class="size-4" />
                        {{ copied ? 'Copied' : 'Copy' }}
                    </Button>
                </div>
                <p class="text-sm text-muted-foreground">
                    Works until {{ until }}. What people add, such as their
                    sign-ups, is saved in your app's test data, which you see
                    under Saved data.
                </p>
                <Form
                    v-bind="ProjectShareController.destroy.form(projectId)"
                    :options="{ preserveScroll: true, preserveState: true }"
                    v-slot="{ processing }"
                    class="self-start"
                >
                    <Button
                        variant="ghost"
                        :disabled="processing"
                        class="-ml-3 h-11 text-muted-foreground select-none sm:h-9"
                        data-test="share-stop"
                    >
                        Stop sharing
                    </Button>
                </Form>
            </div>

            <Form
                v-else
                v-bind="ProjectShareController.store.form(projectId)"
                :options="{ preserveScroll: true, preserveState: true }"
                v-slot="{ processing }"
                class="self-end"
            >
                <Button
                    :disabled="processing"
                    class="h-11 select-none sm:h-9"
                    data-test="share-make"
                >
                    Make a link
                </Button>
            </Form>
        </DialogContent>
    </Dialog>
</template>
