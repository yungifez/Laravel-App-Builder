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
// Credentials come from the environment (ANTHROPIC_API_KEY, OPENAI_API_KEY,
// and optionally ANTHROPIC_BASE_URL / OPENAI_BASE_URL for a gateway).

import { readFileSync } from 'node:fs';

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

/** Codex reports failures as text only, so provider trouble is recognised by these patterns. */
const CODEX_PROVIDER_ERROR =
    /\b(401|403|429|500|502|503|504)\b|rate.?limit|quota|unauthori[sz]ed|invalid api key|incorrect api key|overloaded|server error|service unavailable|timed? ?out|ECONNRESET|ENOTFOUND|ECONNREFUSED/i;

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
        const unavailable =
            status !== null &&
            (status === 401 ||
                status === 403 ||
                status === 429 ||
                status >= 500);

        return {
            status: unavailable ? 'provider_unavailable' : 'failed',
            error_kind: final.subtype,
            error:
                (final.errors ?? []).join(' ') || final.result || final.subtype,
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
        sandboxMode: 'workspace-write',
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
            event.item.type === 'agent_message'
        ) {
            summary = event.item.text;
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
        output_tokens:
            (usage?.output_tokens ?? 0) + (usage?.reasoning_output_tokens ?? 0),
        cost_usd: null,
    };

    if (failure !== null) {
        return {
            status: CODEX_PROVIDER_ERROR.test(failure)
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
const adapters = { claude: runClaude, codex: runCodex };

try {
    if (!(task.adapter in adapters)) {
        throw new Error(`Unknown adapter "${task.adapter}".`);
    }

    print({ adapter: task.adapter, ...(await adapters[task.adapter](task)) });
} catch (error) {
    const message = error instanceof Error ? error.message : String(error);

    print({
        adapter: task.adapter,
        status:
            task.adapter === 'codex' && CODEX_PROVIDER_ERROR.test(message)
                ? 'provider_unavailable'
                : 'failed',
        error_kind: 'exception',
        error: message,
        turns: 0,
        input_tokens: 0,
        output_tokens: 0,
        cost_usd: null,
    });
}
