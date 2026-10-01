<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { Check, Copy } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FeatureRequestWorkerController from '@/actions/App/Http/Controllers/FeatureRequestWorkerController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

// How the owner connects their own Claude Code or Codex to one change
// (architecture §11, "Workers"). The connection is shown once, right after
// it is made; it opens this change and nothing else.
const props = defineProps<{
    requestId: string;
    runId: string;
    address: string;
    name: string;
}>();

const page = usePage();

// The page reloads while the change is open, and a reload drops what was
// flashed, so the connection is kept here once it arrives.
const token = ref<string | null>(null);

watch(
    () => page.flash?.worker as { run: string; token: string } | undefined,
    (worker) => {
        if (worker?.run === props.runId) {
            token.value = worker.token;
        }
    },
    { immediate: true },
);

const tool = ref<'claude' | 'codex'>('claude');

const variable = 'APP_CHANGE_TOKEN';

const command = computed(() =>
    tool.value === 'claude'
        ? `claude mcp remove ${props.name} 2>/dev/null; claude mcp add --transport http ${props.name} ${props.address} --header "Authorization: Bearer ${token.value}"`
        : `export ${variable}=${token.value}; codex mcp remove ${props.name} 2>/dev/null; codex mcp add ${props.name} --url ${props.address} --bearer-token-env-var ${variable}`,
);

const ask = computed(
    () =>
        `Use the ${props.name} tools: call get_task, make the change in this copy of the app, then hand it back with submit_change.`,
);

const copied = ref<'command' | 'ask' | null>(null);

async function copy(what: 'command' | 'ask'): Promise<void> {
    await navigator.clipboard.writeText(
        what === 'command' ? command.value : ask.value,
    );
    copied.value = what;
    setTimeout(() => (copied.value = null), 1500);
}
</script>

<template>
    <section class="space-y-3" data-test="work-yourself">
        <div>
            <p class="font-medium">Your Claude Code or Codex writes this</p>
            <p class="mt-0.5 text-muted-foreground">
                In your copy of the app, connect it, then ask it to do the
                change. I check what it hands back, like any change.
            </p>
        </div>

        <template v-if="token">
            <div
                class="inline-flex rounded-md bg-muted p-0.5 text-xs"
                role="group"
                aria-label="Your tool"
            >
                <button
                    v-for="option in ['claude', 'codex'] as const"
                    :key="option"
                    type="button"
                    :aria-pressed="tool === option"
                    :class="[
                        'min-h-9 rounded px-3 select-none sm:min-h-7',
                        tool === option
                            ? 'bg-background shadow-sm'
                            : 'text-muted-foreground hover:text-foreground',
                    ]"
                    :data-test="`work-yourself-${option}`"
                    @click="tool = option"
                >
                    {{ option === 'claude' ? 'Claude Code' : 'Codex' }}
                </button>
            </div>

            <div class="space-y-1.5">
                <p class="text-xs text-muted-foreground">
                    1. Run this in your copy of the app<template
                        v-if="tool === 'codex'"
                        >, and start Codex from the same terminal</template
                    >
                </p>
                <div class="flex items-start gap-1 rounded-md border">
                    <code
                        class="min-w-0 flex-1 p-2 font-mono text-xs break-all"
                        data-test="work-yourself-command"
                        >{{ command }}</code
                    >
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-9 shrink-0"
                        :aria-label="
                            copied === 'command' ? 'Copied' : 'Copy the command'
                        "
                        @click="copy('command')"
                    >
                        <Check v-if="copied === 'command'" class="size-4" />
                        <Copy v-else class="size-4" />
                    </Button>
                </div>
            </div>

            <div class="space-y-1.5">
                <p class="text-xs text-muted-foreground">2. Then ask it</p>
                <div class="flex items-start gap-1 rounded-md border">
                    <p class="min-w-0 flex-1 p-2 text-xs">{{ ask }}</p>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-9 shrink-0"
                        :aria-label="
                            copied === 'ask' ? 'Copied' : 'Copy what to ask'
                        "
                        @click="copy('ask')"
                    >
                        <Check v-if="copied === 'ask'" class="size-4" />
                        <Copy v-else class="size-4" />
                    </Button>
                </div>
            </div>

            <p class="text-xs text-muted-foreground">
                The connection opens only this change, and shows only now.
            </p>
        </template>

        <!-- The connection showed once; a new one closes the old. -->
        <Form
            v-else
            v-bind="FeatureRequestWorkerController.store.form(requestId)"
            class="space-y-1.5"
            v-slot="{ errors, processing }"
        >
            <p class="text-xs text-muted-foreground">
                The connection showed once. A new one stops the old one.
            </p>
            <Button
                variant="outline"
                size="sm"
                :disabled="processing"
                class="h-11 select-none sm:h-8"
                data-test="work-yourself-reconnect"
            >
                Connect again
            </Button>
            <InputError :message="errors.worker" />
        </Form>
    </section>
</template>
