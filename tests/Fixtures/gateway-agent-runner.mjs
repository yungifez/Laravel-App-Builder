// Stands in for resources/agent-runner/run.mjs in tests: says which key and
// address the agent got, so a test can see that only the gateway's token
// and address reached it.
import { readFileSync } from 'node:fs';

const task = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const key = process.env.ANTHROPIC_API_KEY ?? '';

process.stdout.write(
    `${JSON.stringify({
        type: 'result',
        adapter: task.adapter,
        status: 'completed',
        summary: `key=${key.startsWith('gw_') ? 'gateway' : key} url=${process.env.ANTHROPIC_BASE_URL ?? 'none'}`,
        turns: 1,
        session: 'fake-session',
    })}\n`,
);
