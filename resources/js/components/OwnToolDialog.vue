<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { Check, Copy } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ProjectOwnToolController from '@/actions/App/Http/Controllers/ProjectOwnToolController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

// How the owner lets their own Claude Code or Codex write every change to
// the app (architecture §11, "Workers"). Their tool runs on their computer
// with their own sign-in, which we never see; the connection opens only
// the app's changes that wait for it.
const props = defineProps<{
    projectId: string;
    connected: boolean;
    address: string;
    name: string;
}>();

const open = defineModel<boolean>('open', { required: true });

const page = usePage();

// Shown once, right after it is made: only its hash is kept.
const token = ref<string | null>(null);

watch(
    () => page.flash?.own_tool as string | undefined,
    (made) => {
        if (made) {
            token.value = made;
        }
    },
    { immediate: true },
);

watch(
    () => props.connected,
    (connected) => {
        if (!connected) {
            token.value = null;
        }
    },
);

const tool = ref<'claude' | 'codex'>('claude');

const variable = 'APP_TOOL_TOKEN';

const connect = computed(() =>
    tool.value === 'claude'
        ? `claude mcp remove --scope user ${props.name} 2>/dev/null; claude mcp add --scope user --transport http ${props.name} ${props.address} --header "Authorization: Bearer ${token.value}"`
        : `export ${variable}='${token.value}'; codex mcp remove ${props.name} 2>/dev/null; codex mcp add ${props.name} --url ${props.address} --bearer-token-env-var ${variable}`,
);

const ask = computed(
    () =>
        `Use the ${props.name} tools. Call get_task. If no change waits, stop. Otherwise do what it says: get the code, make the change, and hand it back with submit_change. Then call check_status until it is checked; if the checks send it back, call get_task and fix it the same way.`,
);

// Asks again every minute, so changes are written without the owner there.
// Each try starts in its own new temporary folder, so the tool can only
// write there, never in whatever folder the terminal was opened in.
const keepGoing = computed(() =>
    tool.value === 'claude'
        ? `while true; do (cd "$(mktemp -d)" && claude -p "${ask.value}" --permission-mode acceptEdits --allowedTools "mcp__${props.name},Bash(curl:*),Bash(unzip:*),Bash(git:*),Bash(php:*),Bash(composer:*),Bash(npm:*)"); sleep 60; done`
        : // Codex quietly drops a server whose token variable is missing, so say so.
          `if [ -z "$${variable}" ]; then echo "Run the connect command in this terminal first."; else while true; do codex exec --cd "$(mktemp -d)" --skip-git-repo-check --sandbox workspace-write -c sandbox_workspace_write.network_access=true -c 'mcp_servers.${props.name}.default_tools_approval_mode="approve"' "${ask.value}"; sleep 60; done; fi`,
);

const copied = ref<'connect' | 'ask' | 'keep' | null>(null);

async function copy(what: 'connect' | 'ask' | 'keep'): Promise<void> {
    const text = {
        connect: connect.value,
        ask: ask.value,
        keep: keepGoing.value,
    }[what];

    await navigator.clipboard.writeText(text);
    copied.value = what;
    setTimeout(() => (copied.value = null), 1500);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Use your own Claude Code or Codex</DialogTitle>
                <DialogDescription>
                    Your own tool writes every change, on your computer and on
                    your own plan. I still plan each change, then check and
                    review what it hands back.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4 text-sm" data-test="own-tool">
                <template v-if="connected && token">
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
                            :data-test="`own-tool-${option}`"
                            @click="tool = option"
                        >
                            {{ option === 'claude' ? 'Claude Code' : 'Codex' }}
                        </button>
                    </div>

                    <div
                        v-for="step in [
                            {
                                key: 'connect' as const,
                                label:
                                    tool === 'codex'
                                        ? '1. Run this in a terminal, and start Codex from the same one'
                                        : '1. Run this once in a terminal',
                                text: connect,
                            },
                            {
                                key: 'ask' as const,
                                label: '2. Then ask it, whenever you want it to work',
                                text: ask,
                            },
                            {
                                key: 'keep' as const,
                                label: 'Or let it work by itself. It asks for work every minute and keeps running until you press Ctrl+C.',
                                text: keepGoing,
                            },
                        ]"
                        :key="step.key"
                        class="space-y-1.5"
                    >
                        <p class="text-xs text-muted-foreground">
                            {{ step.label }}
                        </p>
                        <div class="flex items-start gap-1 rounded-md border">
                            <code
                                class="min-w-0 flex-1 p-2 font-mono text-xs break-all"
                                :data-test="`own-tool-${step.key}-text`"
                                >{{ step.text }}</code
                            >
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-9 shrink-0"
                                :aria-label="
                                    copied === step.key ? 'Copied' : 'Copy'
                                "
                                @click="copy(step.key)"
                            >
                                <Check
                                    v-if="copied === step.key"
                                    class="size-4"
                                />
                                <Copy v-else class="size-4" />
                            </Button>
                        </div>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        The connection opens only this app's changes, and shows
                        only now.
                    </p>
                </template>

                <p v-else-if="connected" data-test="own-tool-connected">
                    Your tool writes new changes. The connection showed once;
                    connect again for a new one, which stops the old one.
                </p>

                <div class="flex flex-wrap gap-2">
                    <Form
                        v-bind="ProjectOwnToolController.store.form(projectId)"
                        :options="{ preserveScroll: true, preserveState: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            :variant="connected ? 'outline' : 'default'"
                            :disabled="processing"
                            class="h-11 select-none sm:h-9"
                            data-test="own-tool-connect"
                        >
                            {{
                                connected ? 'Connect again' : 'Connect my tool'
                            }}
                        </Button>
                    </Form>
                    <Form
                        v-if="connected"
                        v-bind="
                            ProjectOwnToolController.destroy.form(projectId)
                        "
                        :options="{ preserveScroll: true, preserveState: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            variant="ghost"
                            :disabled="processing"
                            class="h-11 text-muted-foreground select-none sm:h-9"
                            data-test="own-tool-stop"
                        >
                            Stop, so I write changes again
                        </Button>
                    </Form>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
