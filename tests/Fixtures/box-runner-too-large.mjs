// Runs the real box runner against a fake control plane that refuses the
// first result as too large (413), as a web server does past its upload
// limit. The runner must report the command as failed straight away, not
// send the same result again until the control plane gives it up:
//   node box-runner-too-large.mjs runner.mjs
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { mkdtemp, rm } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const root = await mkdtemp(join(tmpdir(), 'builder-runner-too-large-'));
let pending = [
    {
        id: 'large',
        type: 'open',
        box: 'workspace-large',
        payload: {},
        timeout_seconds: 5,
    },
];
const posts = [];
let finish;
const finished = new Promise((resolve) => {
    finish = resolve;
});

const server = createServer(async (request, response) => {
    const chunks = [];
    for await (const chunk of request) chunks.push(chunk);
    let answer = {};

    if (request.url.endsWith('/hello')) {
        answer = {
            runner: 'test',
            poll_seconds: 0.05,
            output_limit: 4096,
            socket: null,
        };
    } else if (request.url.endsWith('/claim')) {
        answer = { commands: pending, cancel: [] };
        pending = [];
    } else if (request.url.endsWith('/large/result')) {
        posts.push({ at: Date.now(), body: JSON.parse(Buffer.concat(chunks)) });

        if (posts.length === 1) {
            response.writeHead(413).end();

            return;
        }

        finish();
    }

    response.writeHead(200, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify(answer));
});
let runner;
let timer;

try {
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    runner = spawn(process.execPath, [process.argv[2]], {
        env: {
            PATH: process.env.PATH,
            RUNNER_URL: `http://127.0.0.1:${server.address().port}`,
            RUNNER_TOKEN: 'test-token',
            RUNNER_ROOT: root,
            RUNNER_FIREWALL: 'off',
        },
        stdio: ['ignore', 'ignore', 'ignore'],
    });
    await Promise.race([
        finished,
        new Promise((_, reject) => {
            timer = setTimeout(
                () => reject(new Error('The runner did not report again.')),
                20_000,
            );
        }),
        once(runner, 'exit').then(() => {
            throw new Error('The runner stopped.');
        }),
    ]);

    assert.equal(posts.length, 2);
    assert.equal(posts[0].body.exit_code, 0);
    assert.notEqual(posts[1].body.exit_code, 0);
    assert.equal(
        posts[1].body.error_output,
        'The result was too large to send back.',
    );
    // Without waiting first, as it would for a control plane that is down.
    assert.ok(posts[1].at - posts[0].at < 500, 'It reports again at once.');
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
