// Runs the real box runner with its preview door open, against a fake
// control plane, and starts one preview service in a workspace. Once the
// service answers through the door, it prints one JSON line with what the
// runner sent in its hello and the ports, then keeps both running until
// it is told to stop (SIGTERM), so the test can reach the door as the
// control plane does:
//   node box-runner-door.mjs runner.mjs
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createHash, X509Certificate } from 'node:crypto';
import { once } from 'node:events';
import { mkdtemp, rm } from 'node:fs/promises';
import { createServer } from 'node:http';
import { request } from 'node:https';
import { createServer as createNetServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

async function freePort() {
    const probe = createNetServer().listen(0, '127.0.0.1');
    await once(probe, 'listening');
    const { port } = probe.address();
    await new Promise((resolve) => probe.close(resolve));

    return port;
}

const root = await mkdtemp(join(tmpdir(), 'builder-runner-door-'));
const doorPort = await freePort();
const servicePort = await freePort();
const phases = [
    [{ id: 'open', type: 'open', box: 'workspace-a', payload: {} }],
    [
        {
            id: 'serve',
            type: 'start_service',
            box: 'workspace-a',
            payload: {
                port: servicePort,
                command: [
                    process.execPath,
                    '-e',
                    `require('http').createServer((q, r) => r.end(JSON.stringify({ path: q.url, key: q.headers['x-builder-door-key'] ?? null, host: q.headers.host }))).listen(${servicePort}, '127.0.0.1')`,
                ],
            },
            timeout_seconds: 5,
        },
    ],
];
let pending = [];
let hello = null;
const server = createServer(async (incoming, response) => {
    const chunks = [];
    for await (const chunk of incoming) chunks.push(chunk);
    let answer = {};

    if (incoming.url.endsWith('/hello')) {
        hello = JSON.parse(Buffer.concat(chunks));
        pending = phases.shift();
        answer = {
            runner: 'test',
            poll_seconds: 0.05,
            output_limit: 1024,
            socket: null,
        };
    } else if (incoming.url.endsWith('/claim')) {
        answer = { commands: pending, cancel: [] };
        pending = [];
    } else if (incoming.url.endsWith('/result')) {
        pending = phases.shift() ?? [];
    }

    response.writeHead(200, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify(answer));
});

/** Ask the door the way the control plane does, and get the answer. */
function knock(path, key) {
    return new Promise((resolve) => {
        let pin = null;
        const asking = request(
            {
                host: '127.0.0.1',
                port: doorPort,
                path,
                // A new connection each time, so its certificate is read.
                agent: false,
                rejectUnauthorized: false,
                headers: key === null ? {} : { 'X-Builder-Door-Key': key },
            },
            (answer) => {
                const chunks = [];
                answer.on('error', () => resolve(null));
                answer.on('data', (chunk) => chunks.push(chunk));
                answer.on('end', () =>
                    resolve({
                        status: answer.statusCode,
                        body: Buffer.concat(chunks).toString(),
                        pin,
                    }),
                );
            },
        );
        // Read the certificate as soon as the connection is made: on a
        // busy machine the door may have closed it by the time an answer
        // is read, and its certificate is gone with it.
        asking.on('socket', (socket) =>
            socket.once('secureConnect', () => {
                pin = createHash('sha256')
                    .update(
                        new X509Certificate(
                            socket.getPeerCertificate().raw,
                        ).publicKey.export({ type: 'spki', format: 'der' }),
                    )
                    .digest('base64');
            }),
        );
        // A knock that stalls, as one can on a busy machine, is tried
        // again rather than waited on for ever.
        asking.setTimeout(2000, () => asking.destroy());
        asking.on('error', () => resolve(null));
        asking.end();
    });
}

let runner;

try {
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    runner = spawn(process.execPath, [process.argv[2]], {
        env: {
            PATH: process.env.PATH,
            RUNNER_URL: `http://127.0.0.1:${server.address().port}`,
            RUNNER_TOKEN: 'test-token',
            RUNNER_ROOT: root,
            RUNNER_SERVICE_HOST: '127.0.0.1',
            RUNNER_PREVIEW_DOOR_PORT: String(doorPort),
            RUNNER_FIREWALL: 'off',
        },
        stdio: ['ignore', 'ignore', 'inherit'],
    });

    let answer = null;
    const deadline = Date.now() + 30_000;

    while (Date.now() < deadline && answer?.status !== 200) {
        await new Promise((resolve) => setTimeout(resolve, 100));
        answer =
            hello?.preview_door == null
                ? null
                : await knock(`/~${servicePort}/up`, hello.preview_door.key);
    }

    assert.equal(
        answer?.status,
        200,
        'The service did not answer through the door.',
    );
    // The certificate is the one the hello pinned.
    assert.equal(answer.pin, hello.preview_door.pin);
    assert.equal(hello.preview_door.port, doorPort);

    process.stdout.write(
        JSON.stringify({
            hello,
            door_port: doorPort,
            service_port: servicePort,
        }) + '\n',
    );

    await once(process, 'SIGTERM');
} finally {
    if (runner && runner.exitCode === null) {
        runner.kill('SIGTERM');
        await once(runner, 'exit');
    }
    server.closeAllConnections();
    await new Promise((resolve) => server.close(resolve));
    await rm(root, { recursive: true, force: true });
}
