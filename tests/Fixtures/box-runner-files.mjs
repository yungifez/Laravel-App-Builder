import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const root = await mkdtemp(join(tmpdir(), 'builder-runner-files-'));
const workspace = join(root, 'workspace-test');
const tail = 'recent\n'.repeat(300_000).slice(-2_000_000);
const payloads = [
    { path: 'large.log', tail_bytes: 2_000_000 },
    { path: 'short.log', tail_bytes: 100 },
    { path: 'large.log', tail_bytes: 0 },
    { path: 'short.log' },
    { path: 'missing.log', tail_bytes: 100 },
    { path: 'large.log', tail_bytes: -1 },
    { path: '../outside', tail_bytes: 100 },
];
let pending = payloads.map((payload, index) => ({
    id: String(index),
    box: 'workspace-test',
    type: 'read',
    payload,
    timeout_seconds: 5,
}));
const results = new Map();
let finish;
const finished = new Promise((resolve) => {
    finish = resolve;
});
const server = createServer(async (request, response) => {
    let answer = {};

    if (request.url.endsWith('/hello')) {
        answer = {
            runner: 'test',
            poll_seconds: 0.02,
            output_limit: 32,
            socket: null,
        };
    } else if (request.url.endsWith('/claim')) {
        answer = { commands: pending, cancel: [] };
        pending = [];
    } else if (request.url.endsWith('/result')) {
        const chunks = [];
        for await (const chunk of request) chunks.push(chunk);
        results.set(
            request.url.split('/').at(-2),
            JSON.parse(Buffer.concat(chunks)),
        );
        if (results.size === payloads.length) finish();
    }

    response.writeHead(200, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify(answer));
});
let runner;
let timer;

try {
    await mkdir(workspace);
    await writeFile(
        join(workspace, 'large.log'),
        'old\n'.repeat(1_000_000) + tail,
    );
    await writeFile(join(workspace, 'short.log'), 'short');
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    runner = spawn(process.execPath, [process.argv[2]], {
        env: {
            PATH: process.env.PATH,
            RUNNER_URL: `http://127.0.0.1:${server.address().port}`,
            RUNNER_TOKEN: 'test-token',
            RUNNER_ROOT: root,
            RUNNER_USER: 'root',
        },
        stdio: ['ignore', 'ignore', 'inherit'],
    });
    await Promise.race([
        finished,
        new Promise((_, reject) => {
            timer = setTimeout(
                () =>
                    reject(
                        new Error('Runner did not return the file results.'),
                    ),
                10_000,
            );
        }),
        once(runner, 'exit').then(() => {
            throw new Error('Runner exited before returning the file results.');
        }),
    ]);

    for (const [id, expected] of [
        ['0', tail],
        ['1', 'short'],
        ['2', ''],
        ['3', 'short'],
    ]) {
        const result = results.get(id);
        assert.equal(result.exit_code, 0);
        assert.equal(
            Buffer.from(result.contents, 'base64').toString(),
            expected,
        );
        assert.ok(Buffer.byteLength(result.output) <= 128);
    }
    for (const id of ['4', '5', '6'])
        assert.notEqual(results.get(id).exit_code, 0);
} finally {
    clearTimeout(timer);
    if (runner && runner.exitCode === null) {
        runner.kill('SIGTERM');
        await once(runner, 'exit');
    }
    server.closeAllConnections();
    await new Promise((resolve) => server.close(resolve));
    await rm(root, { recursive: true, force: true });
}
