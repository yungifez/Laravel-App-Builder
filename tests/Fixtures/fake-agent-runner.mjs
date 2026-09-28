// Stands in for resources/agent-runner/run.mjs in tests: reads the task file,
// writes a file into the workspace, and prints noise and one result line
// with the story of what it did.
import { readFileSync, writeFileSync } from 'node:fs';

const task = JSON.parse(readFileSync(process.argv[2], 'utf8'));

// What the agent was told: the follow-up alone when it continues a session.
writeFileSync(
    'agent-output.txt',
    task.session ? `${task.follow_up}` : task.prompt,
);
process.stdout.write('starting…\n');
process.stdout.write(
    `${JSON.stringify({
        type: 'result',
        adapter: task.adapter,
        status: 'completed',
        summary: `model=${task.model ?? 'default'} turns=${task.max_turns} key=${process.env.ANTHROPIC_API_KEY || process.env.OPENAI_API_KEY ? 'present' : 'missing'} sandbox=${task.sandbox ?? 'none'}${task.effort ? ` effort=${task.effort}` : ''}`,
        turns: 3,
        input_tokens: 1200,
        output_tokens: 300,
        cost_usd: 0.42,
        session: task.session ?? 'fake-session',
        resumed: Boolean(task.session),
        story: [
            { kind: 'said', text: 'I will write down the task first.' },
            { kind: 'changed', file: 'agent-output.txt' },
            { kind: 'unknown' },
        ],
    })}\n`,
);
