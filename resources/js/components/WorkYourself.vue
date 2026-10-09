<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { Check, ChevronRight, Copy } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FeatureRequestWorkerController from '@/actions/App/Http/Controllers/FeatureRequestWorkerController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

// How the owner connects their own Claude Code or Codex to one change
// (architecture §11, "Workers"). The connection is shown once, right after
// it is made; it opens this change and nothing else. The Claude app, VS
// Code and Cursor take the app's own address instead, which shows at any
// time: they sign in, and get the app's oldest change that waits.
const props = defineProps<{
    requestId: string;
    runId: string;
    address: string;
    appAddress: string;
    name: string;
}>();

const page = usePage();

// The page reloads while the change is open, and the server gives the
// connection once, so it is kept here once it arrives. It comes as a page
// prop, not a flash: a poll that ends during the hand-over would write the
// session back without the flash, and the first connection was lost.
const token = ref<string | null>(null);

watch(
    () =>
        page.props.worker as { run: string; token: string } | null | undefined,
    (worker) => {
        if (worker?.run === props.runId) {
            token.value = worker.token;
        }
    },
    { immediate: true },
);

const tool = ref<'claude' | 'codex' | 'app'>('claude');

const variable = 'APP_CHANGE_TOKEN';

const command = computed(() =>
    tool.value === 'claude'
        ? // Scoped to this folder: the same name may be connected to the
          // whole app too, and then an unscoped remove fails and the add
          // keeps the old token.
          `claude mcp remove --scope local ${props.name} 2>/dev/null; claude mcp add --scope local --transport http ${props.name} ${props.address} --header "Authorization: Bearer ${token.value}"`
        : // Quoted: the token holds a "|", which the shell reads as a pipe.
          `export ${variable}='${token.value}'; codex mcp remove ${props.name} 2>/dev/null; codex mcp add ${props.name} --url ${props.address} --bearer-token-env-var ${variable}`,
);

const ask = computed(
    () =>
        `Use the ${props.name} tools: call get_task, make the change in this copy of the app, then hand it back with submit_change.`,
);

// Or the whole change in one go, with no session to keep open: a new
// temporary folder, where get_task gives it the code, and the connection
// given on the command line, so nothing is saved in the tool's settings.
// Only these servers, so one connected to the whole app under the same
// name never answers in its place.
const alone = computed(
    () =>
        `Use the ${props.name} tools: call get_task and do what it says, in this empty folder. Hand the change back with submit_change, then call check_status until it is checked. If the checks send it back, call get_task and fix it the same way.`,
);

const headless = computed(() =>
    tool.value === 'claude'
        ? `(cd "$(mktemp -d)" && claude -p "${alone.value}" --permission-mode acceptEdits --allowedTools "mcp__${props.name},Bash(curl:*),Bash(unzip:*),Bash(git:*),Bash(php:*),Bash(composer:*),Bash(npm:*)" --strict-mcp-config --mcp-config '{"mcpServers":{"${props.name}":{"type":"http","url":"${props.address}","headers":{"Authorization":"Bearer ${token.value}"}}}}')`
        : `${variable}='${token.value}' codex exec --cd "$(mktemp -d)" --skip-git-repo-check --sandbox workspace-write -c sandbox_workspace_write.network_access=true -c 'mcp_servers.${props.name}.url="${props.address}"' -c 'mcp_servers.${props.name}.bearer_token_env_var="${variable}"' -c 'mcp_servers.${props.name}.default_tools_approval_mode="approve"' "${alone.value}"`,
);

// A chat has no folder: get_task tells it to work through the file tools.
const askApp = computed(
    () =>
        `Use the ${props.name} tools: call get_task and do what it says. Hand the change back with submit_change, then call check_status until it is checked.`,
);

type Copyable = 'command' | 'ask' | 'headless' | 'address' | 'ask-app';

const copied = ref<Copyable | null>(null);

async function copy(what: Copyable): Promise<void> {
    await navigator.clipboard.writeText(
        {
            command: command.value,
            ask: ask.value,
            headless: headless.value,
            address: props.appAddress,
            'ask-app': askApp.value,
        }[what],
    );
    copied.value = what;
    setTimeout(() => (copied.value = null), 1500);
}
</script>

<template>
    <section class="space-y-3" data-test="work-yourself">
        <div>
            <p class="font-medium">Your own Claude or Codex writes this</p>
            <p class="mt-0.5 text-muted-foreground">
                Connect it, then ask it to do the change. I check what it hands
                back, like any change.
            </p>
        </div>

        <div
            class="inline-flex rounded-md bg-muted p-0.5 text-xs"
            role="group"
            aria-label="Your tool"
        >
            <button
                v-for="option in ['claude', 'codex', 'app'] as const"
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
                {{
                    {
                        claude: 'Claude Code',
                        codex: 'Codex',
                        app: 'Claude app',
                    }[option]
                }}
            </button>
        </div>

        <!-- The app's address needs no token, so it shows at any time. -->
        <template v-if="tool === 'app'">
            <div class="space-y-1.5">
                <p class="text-xs text-muted-foreground">
                    1. In the Claude app, open Settings, then Connectors, and
                    add a custom connector with this address. VS Code and Cursor
                    take it too.
                </p>
                <div class="flex items-start gap-1 rounded-md border">
                    <code
                        class="min-w-0 flex-1 p-2 font-mono text-xs break-all"
                        data-test="work-yourself-address"
                        >{{ appAddress }}</code
                    >
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-9 shrink-0"
                        :aria-label="
                            copied === 'address' ? 'Copied' : 'Copy the address'
                        "
                        @click="copy('address')"
                    >
                        <Check v-if="copied === 'address'" class="size-4" />
                        <Copy v-else class="size-4" />
                    </Button>
                </div>
            </div>

            <div class="space-y-1.5">
                <p class="text-xs text-muted-foreground">
                    2. Press Allow when it asks. Then ask it
                </p>
                <div class="flex items-start gap-1 rounded-md border">
                    <p
                        class="min-w-0 flex-1 p-2 text-xs"
                        data-test="work-yourself-ask-app"
                    >
                        {{ askApp }}
                    </p>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-9 shrink-0"
                        :aria-label="
                            copied === 'ask-app' ? 'Copied' : 'Copy what to ask'
                        "
                        @click="copy('ask-app')"
                    >
                        <Check v-if="copied === 'ask-app'" class="size-4" />
                        <Copy v-else class="size-4" />
                    </Button>
                </div>
            </div>

            <p class="text-xs text-muted-foreground">
                It can take the change once I have planned it. When more than
                one change waits for your tool, it takes the oldest first.
            </p>
        </template>

        <template v-else-if="token">
            <!-- One line and one button first: a new app has no copy on
                 the owner's computer, so the way that needs none leads. The
                 label stays put when it is copied; only the icon turns. -->
            <div class="space-y-1.5">
                <p class="text-xs text-muted-foreground">
                    Paste this in a terminal. It makes the change on its own, in
                    a new temporary folder.
                </p>
                <Button
                    size="sm"
                    class="h-11 select-none sm:h-8"
                    data-test="work-yourself-headless-copy"
                    @click="copy('headless')"
                >
                    <Check v-if="copied === 'headless'" class="size-4" />
                    <Copy v-else class="size-4" />
                    Copy the command
                </Button>
            </div>

            <details class="group" data-test="work-yourself-other-ways">
                <summary
                    class="flex min-h-11 w-fit cursor-pointer list-none items-center gap-1 text-xs text-muted-foreground select-none hover:text-foreground sm:min-h-7"
                >
                    <ChevronRight
                        class="size-3.5 shrink-0 transition-transform group-open:rotate-90"
                    />
                    Other ways
                </summary>

                <div class="mt-2 space-y-3">
                    <div class="space-y-1.5">
                        <p class="text-xs text-muted-foreground">
                            The command itself
                        </p>
                        <code
                            class="block rounded-md border p-2 font-mono text-xs break-all"
                            data-test="work-yourself-headless"
                            >{{ headless }}</code
                        >
                    </div>

                    <div class="space-y-1.5">
                        <p class="text-xs text-muted-foreground">
                            Or, when you have a copy of the app, run this in
                            it<template v-if="tool === 'codex'"
                                >, and start Codex from the same
                                terminal</template
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
                                    copied === 'command'
                                        ? 'Copied'
                                        : 'Copy the command'
                                "
                                @click="copy('command')"
                            >
                                <Check
                                    v-if="copied === 'command'"
                                    class="size-4"
                                />
                                <Copy v-else class="size-4" />
                            </Button>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <p class="text-xs text-muted-foreground">Then ask it</p>
                        <div class="flex items-start gap-1 rounded-md border">
                            <p class="min-w-0 flex-1 p-2 text-xs">{{ ask }}</p>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-9 shrink-0"
                                :aria-label="
                                    copied === 'ask'
                                        ? 'Copied'
                                        : 'Copy what to ask'
                                "
                                @click="copy('ask')"
                            >
                                <Check v-if="copied === 'ask'" class="size-4" />
                                <Copy v-else class="size-4" />
                            </Button>
                        </div>
                    </div>
                </div>
            </details>

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
                <Spinner v-if="processing" />
                {{ processing ? 'Connecting…' : 'Connect again' }}
            </Button>
            <InputError :message="errors.worker" />
        </Form>
    </section>
</template>
