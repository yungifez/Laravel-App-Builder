// Runs the real box runner with a list of places workspaces may reach,
// against a fake control plane, and checks that a command goes out only
// through the runner's proxy: the control plane answers, and a host that
// is not on the list, or a listed host on another port, does not.
//   node box-runner-egress.mjs runner.mjs
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { mkdtemp, rm } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const root = await mkdtemp(join(tmpdir(), 'builder-runner-egress-'));
const proxyPort = 30000 + Math.floor(Math.random() * 10000);
const elsewhere = createServer((request, response) => response.end('away'));
const exec = (id, script) => ({
    id,
    box: 'workspace-test',
    type: 'exec',
    payload: { command: ['sh', '-c', script], env: {} },
    timeout_seconds: 10,
});
const status = (address) =>
    `curl -s -m 5 -o /dev/null -w '%{http_code}' ${address}`;
let pending;
const results = new Map();
let finish;
const finished = new Promise((resolve) => {
    finish = resolve;
});
const server = createServer(async (request, response) => {
    let answer = {};

    if (request.url === '/reached') {
        response.end('control plane');

        return;
    }

    if (request.url.endsWith('/hello')) {
        answer = {
            runner: 'test',
            poll_seconds: 0.02,
            output_limit: 4096,
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
        if (results.size === 6) finish();
    }

    response.writeHead(200, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify(answer));
});
let runner;
let timer;

try {
    server.listen(0, '127.0.0.1');
    elsewhere.listen(0, '127.0.0.2');
    await Promise.all([
        once(server, 'listening'),
        once(elsewhere, 'listening'),
    ]);
    const controlPlane = `http://127.0.0.1:${server.address().port}`;
    const away = elsewhere.address().port;
    pending = [
        { id: 'open', box: 'workspace-test', type: 'open' },
        exec('proxy', 'echo "$HTTPS_PROXY $http_proxy"'),
        exec('control-plane', status(`${controlPlane}/reached`)),
        exec('unlisted', status(`http://127.0.0.2:${away}/`)),
        exec('listed-other-port', status(`http://127.0.0.3:${away}/`)),
        exec(
            'tunnel',
            `curl -s -m 5 -o /dev/null -w '%{http_connect}' https://unlisted.test/`,
        ),
    ];
    runner = spawn(process.execPath, [process.argv[2]], {
        env: {
            PATH: process.env.PATH,
            RUNNER_URL: controlPlane,
            RUNNER_TOKEN: 'test-token',
            RUNNER_ROOT: root,
            RUNNER_EGRESS_ALLOW: '127.0.0.3, .registry.test',
            RUNNER_EGRESS_PORT: String(proxyPort),
        },
        stdio: ['ignore', 'ignore', 'inherit'],
    });
    await Promise.race([
        finished,
        new Promise((_, reject) => {
            timer = setTimeout(
                () => reject(new Error('Runner did not return the results.')),
                20_000,
            );
        }),
        once(runner, 'exit').then(() => {
            throw new Error('Runner exited before returning the results.');
        }),
    ]);

    const output = (id) => results.get(id).output.trim();
    const proxy = `http://127.0.0.1:${proxyPort}`;
    assert.equal(output('proxy'), `${proxy} ${proxy}`);
    assert.equal(output('control-plane'), '200');
    assert.equal(output('unlisted'), '403');
    assert.equal(output('listed-other-port'), '403');
    assert.equal(output('tunnel'), '403');
} finally {
    clearTimeout(timer);
    if (runner && runner.exitCode === null) {
        runner.kill('SIGTERM');
        await once(runner, 'exit');
    }
    for (const each of [server, elsewhere]) {
        each.closeAllConnections();
        await new Promise((resolve) => each.close(resolve));
    }
    await rm(root, { recursive: true, force: true });
}
