// Runs the real box runner against a fake control plane and has it unpack
// three projects: a large one served in small, slow pieces (tar reads
// slower than it arrives), one whose download stops halfway, and one that
// is not there. The first must unpack whole; the others must fail as
// commands while the runner keeps running:
//   node box-runner-unpack.mjs runner.mjs
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { once } from 'node:events';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const root = await mkdtemp(join(tmpdir(), 'builder-runner-unpack-'));
const source = join(root, '.source');
// Random bytes do not compress, so the archive stays large.
const contents = randomBytes(24 * 1024 * 1024);
await mkdir(source);
await writeFile(join(source, 'large.bin'), contents);
const archive = execFileSync('tar', ['-czf', '-', '-C', source, '.'], {
    maxBuffer: 64 * 1024 * 1024,
});

const unpack = (id) => ({
    id,
    type: 'unpack',
    box: `workspace-${id}`,
    payload: {},
    timeout_seconds: 60,
});
const phases = [
    ['whole', 'cut', 'missing'].map((id) => ({
        id: `open-${id}`,
        type: 'open',
        box: `workspace-${id}`,
        payload: {},
        timeout_seconds: 5,
    })),
    [unpack('whole'), unpack('cut'), unpack('missing')],
];
let pending = [];
let opened = 0;
const results = new Map();
let finish;
const finished = new Promise((resolve) => {
    finish = resolve;
});

async function serve(response, bytes) {
    response.writeHead(200, {
        'Content-Type': 'application/gzip',
        'Content-Length': String(archive.length),
    });

    for (let at = 0; at < bytes; at += 256 * 1024) {
        if (
            !response.write(
                archive.subarray(at, Math.min(at + 256 * 1024, bytes)),
            )
        ) {
            await once(response, 'drain');
        }
    }
}

const server = createServer(async (request, response) => {
    const chunks = [];
    for await (const chunk of request) chunks.push(chunk);
    const archiveOf = /\/commands\/([^/]+)\/archive$/.exec(request.url)?.[1];

    if (archiveOf === 'whole') {
        await serve(response, archive.length);
        response.end();

        return;
    }

    if (archiveOf === 'cut') {
        await serve(response, Math.floor(archive.length / 2));
        response.socket.destroy();

        return;
    }

    if (archiveOf !== undefined) {
        response.writeHead(404).end();

        return;
    }

    let answer = {};

    if (request.url.endsWith('/hello')) {
        pending = phases.shift();
        answer = {
            runner: 'test',
            poll_seconds: 0.05,
            output_limit: 4096,
            socket: null,
        };
    } else if (request.url.endsWith('/claim')) {
        answer = { commands: pending, cancel: [] };
        pending = [];
    } else if (request.url.endsWith('/result')) {
        const id = request.url.split('/').at(-2);

        if (id.startsWith('open-')) {
            opened++;

            if (opened === 3) {
                pending = phases.shift();
            }
        } else {
            results.set(id, JSON.parse(Buffer.concat(chunks)));

            if (results.size === 3) {
                finish();
            }
        }
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
        stdio: ['ignore', 'ignore', 'inherit'],
    });
    await Promise.race([
        finished,
        new Promise((_, reject) => {
            timer = setTimeout(
                () =>
                    reject(new Error('The runner did not report all unpacks.')),
                60_000,
            );
        }),
        once(runner, 'exit').then(() => {
            throw new Error('The runner stopped while unpacking.');
        }),
    ]);

    assert.equal(
        results.get('whole').exit_code,
        0,
        results.get('whole').error_output,
    );
    assert.equal(
        createHash('sha256')
            .update(await readFile(join(root, 'workspace-whole', 'large.bin')))
            .digest('hex'),
        createHash('sha256').update(contents).digest('hex'),
    );
    assert.notEqual(results.get('cut').exit_code, 0);
    assert.notEqual(results.get('missing').exit_code, 0);
    assert.match(results.get('missing').error_output, /404/);
    assert.equal(runner.exitCode, null, 'The runner keeps running.');
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
