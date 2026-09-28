// Runs one coding task with the Claude Agent SDK ("claude") or the Codex SDK
// ("codex") in the current directory, then prints one JSON line:
//
// {"type":"result","status":"completed|provider_unavailable|failed", ...}
//
// "provider_unavailable" means the provider could not serve the task (down,
// overloaded, rate-limited, out of quota or refusing the credentials); the
// control plane may then retry the task with the other adapter. Everything
// else that goes wrong is "failed".
//
// Usage: node run.mjs <task.json>
// The task file: {adapter, prompt, model?, max_turns?, max_budget_usd?}.
//
// While the agent works, progress.json next to the task file says what it
// is doing, so the owner can follow along:
// {"doing":"reading|changing|testing","last":"path","read":[...],"changed":[...],
//  "story":[{"kind":"said","text":"..."}|{"kind":"read|changed","file":"path"}|{"kind":"testing"}]}
// The story is what the agent did and said, in order; the result line
// carries it too, so it outlives the task files.
// Credentials come from the environment (ANTHROPIC_API_KEY, OPENAI_API_KEY,
// and optionally ANTHROPIC_BASE_URL / OPENAI_BASE_URL for a gateway).

import { readFileSync, renameSync, writeFileSync } from 'node:fs';
import { dirname, isAbsolute, join, relative } from 'node:path';

/** Assistant message errors from the Claude Agent SDK that mean the provider could not serve the task. */
const CLAUDE_PROVIDER_ERRORS = new Set([
    'authentication_failed',
    'oauth_org_not_allowed',
    'account_on_hold',
    'billing_error',
    'rate_limit',
    'overloaded',
    'server_error',
    'cloud_credential_error',
]);

/**
 * Provider trouble reported as text only: Codex failures, and errors either
 * SDK throws (the Claude SDK throws when the account is out of credit).
 */
const PROVIDER_ERROR =
    /\b(401|403|429|500|502|503|504)\b|rate.?limit|quota|credit balance|billing|unauthori[sz]ed|invalid api key|incorrect api key|overloaded|server error|service unavailable|timed? ?out|ECONNRESET|ENOTFOUND|ECONNREFUSED/i;

/** What the agent has done so far, written after each step. */
const progress = {
    doing: 'reading',
    last: null,
    read: [],
    changed: [],
    story: [],
};
let progressFile = null;

/** The story keeps the latest steps only, so the file stays small. */
const STORY_LIMIT = 200;

function tell(entry) {
    progress.story.push(entry);

    if (progress.story.length > STORY_LIMIT) {
        progress.story.shift();
    }
}

/** Keep what the agent says between steps, as its own words. */
function say(text) {
    const said = (text ?? '').trim().slice(0, 1000);

    if (said !== '') {
        tell({ kind: 'said', text: said });
        write();
    }
}

function track(doing, path = null) {
    progress.doing = doing;

    if (path) {
        const file = isAbsolute(path) ? relative(process.cwd(), path) : path;
        const list = doing === 'changing' ? progress.changed : progress.read;

        if (!file.startsWith('..') && !list.includes(file)) {
            list.push(file);
        }

        if (!file.startsWith('..')) {
            tell({ kind: doing === 'changing' ? 'changed' : 'read', file });
        }

        progress.last = file;
    } else if (doing === 'testing') {
        tell({ kind: 'testing' });
    }

    write();
}

function write() {
    if (progressFile === null) {
        return;
    }

    try {
        // Written whole and then moved, so a reader never sees half a file.
        writeFileSync(`${progressFile}.tmp`, JSON.stringify(progress));
        renameSync(`${progressFile}.tmp`, progressFile);
    } catch {
        // Progress is a courtesy; the task goes on without it.
    }
}

const TEST_COMMAND =
    /\b(phpunit|pest|artisan test|npm (run )?test|vitest|jest)\b/;

function print(result) {
    process.stdout.write(`${JSON.stringify({ type: 'result', ...result })}\n`);
}

async function runClaude(task) {
    const { query } = await import('@anthropic-ai/claude-agent-sdk');
    let providerError = null;
    let final = null;

    for await (const message of query({
        prompt: task.prompt,
        options: {
            cwd: process.cwd(),
            model: task.model ?? undefined,
            maxTurns: task.max_turns ?? undefined,
            maxBudgetUsd: task.max_budget_usd ?? undefined,
            permissionMode: 'acceptEdits',
            allowedTools: ['Read', 'Write', 'Edit', 'Glob', 'Grep', 'Bash'],
            disallowedTools: ['WebFetch', 'WebSearch'],
            settingSources: ['project'],
            systemPrompt: { type: 'preset', preset: 'claude_code' },
        },
    })) {
        if (message.type === 'assistant' && message.error) {
            providerError = message.error;
        }

        for (const block of message.type === 'assistant'
            ? (message.message?.content ?? [])
            : []) {
            if (block.type === 'text') {
                say(block.text);
            }

            if (block.type !== 'tool_use') {
                continue;
            }

            if (['Write', 'Edit', 'MultiEdit'].includes(block.name)) {
                track('changing', block.input?.file_path);
            } else if (block.name === 'Read') {
                track('reading', block.input?.file_path);
            } else if (
                block.name === 'Bash' &&
                TEST_COMMAND.test(block.input?.command ?? '')
            ) {
                track('testing');
            }
        }

        if (message.type === 'result') {
            final = message;
        }
    }

    const usage = {
        turns: final?.num_turns ?? 0,
        input_tokens: final?.usage?.input_tokens ?? 0,
        output_tokens: final?.usage?.output_tokens ?? 0,
        cost_usd: final?.total_cost_usd ?? null,
    };

    if (providerError !== null && CLAUDE_PROVIDER_ERRORS.has(providerError)) {
        return {
            status: 'provider_unavailable',
            error_kind: providerError,
            error: `The provider reported ${providerError}.`,
            ...usage,
        };
    }

    if (final === null) {
        return {
            status: 'failed',
            error_kind: 'no_result',
            error: 'The agent ended without a result.',
            ...usage,
        };
    }

    if (final.subtype !== 'success' || final.is_error) {
        const status = final.api_error_status ?? null;
        const error =
            (final.errors ?? []).join(' ') || final.result || final.subtype;
        const unavailable =
            (status !== null &&
                (status === 401 ||
                    status === 403 ||
                    status === 429 ||
                    status >= 500)) ||
            PROVIDER_ERROR.test(error);

        return {
            status: unavailable ? 'provider_unavailable' : 'failed',
            error_kind: final.subtype,
            error,
            ...usage,
        };
    }

    return { status: 'completed', summary: final.result, ...usage };
}

async function runCodex(task) {
    const { Codex } = await import('@openai/codex-sdk');
    const codex = new Codex({
        baseUrl: process.env.OPENAI_BASE_URL || undefined,
        apiKey: process.env.OPENAI_API_KEY || undefined,
    });
    const thread = codex.startThread({
        workingDirectory: process.cwd(),
        model: task.model ?? undefined,
        sandboxMode: task.sandbox ?? 'workspace-write',
        approvalPolicy: 'never',
        networkAccessEnabled: false,
        webSearchMode: 'disabled',
    });

    const { events } = await thread.runStreamed(task.prompt);
    let summary = '';
    let usage = null;
    let failure = null;

    for await (const event of events) {
        if (
            event.type === 'item.completed' &&
            event.item.type === 'file_change'
        ) {
            for (const change of event.item.changes ?? []) {
                track('changing', change.path);
            }
        } else if (
            event.type === 'item.started' &&
            event.item.type === 'command_execution' &&
            TEST_COMMAND.test(event.item.command ?? '')
        ) {
            track('testing');
        } else if (
            event.type === 'item.completed' &&
            event.item.type === 'agent_message'
        ) {
            summary = event.item.text;
            say(event.item.text);
        } else if (event.type === 'turn.completed') {
            usage = event.usage;
        } else if (event.type === 'turn.failed') {
            failure = event.error.message;
        } else if (event.type === 'error') {
            failure = event.message;
        }
    }

    const counts = {
        turns: 1,
        input_tokens: usage?.input_tokens ?? 0,
        // Part of input_tokens, read back from OpenAI's cache at a lower price.
        cached_input_tokens: usage?.cached_input_tokens ?? 0,
        output_tokens:
            (usage?.output_tokens ?? 0) + (usage?.reasoning_output_tokens ?? 0),
        cost_usd: null,
    };

    if (failure !== null) {
        return {
            status: PROVIDER_ERROR.test(failure)
                ? 'provider_unavailable'
                : 'failed',
            error_kind: 'turn_failed',
            error: failure,
            ...counts,
        };
    }

    return { status: 'completed', summary, ...counts };
}

const task = JSON.parse(readFileSync(process.argv[2], 'utf8'));
progressFile = join(dirname(process.argv[2]), 'progress.json');
track('reading');
const adapters = { claude: runClaude, codex: runCodex };

try {
    if (!(task.adapter in adapters)) {
        throw new Error(`Unknown adapter "${task.adapter}".`);
    }

    print({
        adapter: task.adapter,
        ...(await adapters[task.adapter](task)),
        story: progress.story,
    });
} catch (error) {
    const message = error instanceof Error ? error.message : String(error);

    print({
        adapter: task.adapter,
        status: PROVIDER_ERROR.test(message)
            ? 'provider_unavailable'
            : 'failed',
        error_kind: 'exception',
        error: message,
        turns: 0,
        input_tokens: 0,
        output_tokens: 0,
        cost_usd: null,
        story: progress.story,
    });
}
