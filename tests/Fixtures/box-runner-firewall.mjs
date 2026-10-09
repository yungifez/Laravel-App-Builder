// Runs the real box runner as root, with iptables, against a fake control
// plane and checks that one workspace cannot reach another's preview or the
// private network. Run it as root with NET_ADMIN:
//   node box-runner-firewall.mjs runner.mjs [host:port]
// The optional host:port is a private address that answers, such as the
// database; root must reach it and a workspace must not. It exits with 77
// when this machine does not let it use iptables.
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { once } from 'node:events';
import { mkdtemp, rm } from 'node:fs/promises';
import { createServer } from 'node:http';
import { connect } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

try {
    execFileSync('iptables', ['-w', '-S', 'OUTPUT'], { stdio: 'ignore' });
} catch {
    console.error('This machine does not let the runner use iptables.');
    process.exit(77);
}

const root = await mkdtemp(join(tmpdir(), 'builder-runner-firewall-'));
const port = 20000 + Math.floor(Math.random() * 10000);
const target = process.argv[3];
const exec = (box, script) => ({
    type: 'exec',
    box,
    payload: { command: ['sh', '-c', script], env: {} },
});
const reach = (address) =>
    `curl -s -m 3 -o /dev/null -w '%{http_code}' ${address}`;
const tcp = (address) => {
    const [host, at] = address.split(':');

    return `node -e "require('net').connect(${at}, '${host}').on('connect', () => process.exit(0)).on('error', () => process.exit(1))"`;
};

// Each phase starts once the one before it has answered.
const phases = [
    [
        { type: 'open', box: 'workspace-a' },
        { type: 'open', box: 'workspace-b' },
    ],
    [
        {
            type: 'start_service',
            box: 'workspace-a',
            payload: {
                port,
                command: [
                    'node',
                    '-e',
                    `require('http').createServer((q, r) => r.end('a')).listen(${port}, '127.0.0.1')`,
                ],
            },
        },
    ],
    [
        // a reaches its own preview once it is up.
        exec(
            'workspace-a',
            `for i in $(seq 50); do [ "$(${reach(`127.0.0.1:${port}`)})" = 200 ] && exit 0; sleep 0.1; done; exit 1`,
        ),
    ],
    [
        exec('workspace-b', reach(`127.0.0.1:${port}`)),
        exec('workspace-b', target ? tcp(target) : 'exit 1'),
        exec('workspace-a', target ? tcp(target) : 'exit 1'),
    ],
    [{ type: 'close', box: 'workspace-a' }],
];
let phase = 0;
let pending = [];
let next = 0;
const results = new Map();
let finish;
const finished = new Promise((resolve) => {
    finish = resolve;
});

function release() {
    pending = phases[phase].map((command) => ({
        id: String(next++),
        timeout_seconds: 10,
        ...command,
    }));
}

const server = createServer(async (request, response) => {
    let answer = {};

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

        if (results.size === next) {
            phase++;
            if (phase < phases.length) {
                release();
            } else {
                finish();
            }
        }
    }

    response.writeHead(200, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify(answer));
});
const previews = () =>
    execFileSync('iptables', ['-w', '-S', 'BUILDER-PREVIEWS'], {
        encoding: 'utf8',
    });
let runner;
let timer;

try {
    release();
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    runner = spawn(process.execPath, [process.argv[2]], {
        env: {
            PATH: process.env.PATH,
            RUNNER_URL: `http://127.0.0.1:${server.address().port}`,
            RUNNER_TOKEN: 'test-token',
            RUNNER_ROOT: root,
            RUNNER_FIREWALL: process.env.RUNNER_FIREWALL ?? '',
        },
        stdio: ['ignore', 'ignore', 'inherit'],
    });
    timer = setTimeout(() => {
        console.error('The runner did not answer in time.');
        process.exit(1);
    }, 60000);
    await finished;

    const out = (id) => results.get(String(id));

    assert.equal(out(2).exit_code, 0, out(2).error_output);
    assert.equal(out(3).exit_code, 0, 'a could not reach its own preview');

    // b cannot reach a's preview on this machine.
    assert.notEqual(out(4).output, '200', "b reached a's preview");

    // Root reaches the private address; a workspace does not.
    if (target) {
        const [host, at] = target.split(':');
        const socket = connect(Number(at), host);
        await once(socket, 'connect');
        socket.destroy();

        assert.notEqual(out(5).exit_code, 0, 'b reached the private network');
        assert.notEqual(out(6).exit_code, 0, 'a reached the private network');
    }

    // Closing a workspace removes its preview rule.
    assert.equal(out(7).exit_code, 0, out(7).error_output);
    assert.doesNotMatch(previews(), /builder:workspace-a/);
} finally {
    clearTimeout(timer);
    runner?.kill('SIGTERM');
    server.close();
    await rm(root, { recursive: true, force: true });
}
