// Stands in for resources/agent-runner/run.mjs in tests: reads the task file,
// writes a file into the workspace, and prints noise and one result line.
import { readFileSync, writeFileSync } from 'node:fs';

const task = JSON.parse(readFileSync(process.argv[2], 'utf8'));

writeFileSync('agent-output.txt', task.prompt);
process.stdout.write('starting…\n');
process.stdout.write(
    `${JSON.stringify({
        type: 'result',
        adapter: task.adapter,
        status: 'completed',
        summary: `model=${task.model ?? 'default'} turns=${task.max_turns} key=${process.env.ANTHROPIC_API_KEY ? 'present' : 'missing'}`,
        turns: 3,
        input_tokens: 1200,
        output_tokens: 300,
        cost_usd: 0.42,
    })}\n`,
);
