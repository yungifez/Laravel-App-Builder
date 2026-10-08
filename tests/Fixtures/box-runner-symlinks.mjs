// Run the real runner as root. Workspace-controlled links must never let
// its privileged home setup or service logging change another file.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import {
    chown,
    link,
    mkdir,
    mkdtemp,
    readFile,
    rm,
    stat,
    writeFile,
} from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const root = await mkdtemp(join(tmpdir(), 'builder-runner-symlinks-'));
const machine = join(root, '.machine');
const secret = join(machine, 'secret');
let pending = [];
let next = 0;
const waiting = new Map();
let runner;
let timer;

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
        const id = request.url.split('/').at(-2);
        waiting.get(id)?.(JSON.parse(Buffer.concat(chunks)));
        waiting.delete(id);
    }

    response.writeHead(200, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify(answer));
});

function command(type, box, payload = {}) {
    const id = String(next++);
    const result = new Promise((resolve) => waiting.set(id, resolve));
    pending.push({ id, type, box, payload, timeout_seconds: 5 });

    return result;
}

function exec(box, script, env = {}) {
    return command('exec', box, { command: ['sh', '-c', script], env });
}

function start() {
    return spawn(process.execPath, [process.argv[2]], {
        env: {
            PATH: process.env.PATH,
            RUNNER_URL: `http://127.0.0.1:${server.address().port}`,
            RUNNER_TOKEN: 'test-token',
            RUNNER_ROOT: root,
            RUNNER_FIREWALL: 'off',
        },
        stdio: ['ignore', 'ignore', 'ignore'],
    });
}

async function stop() {
    const exited = once(runner, 'exit');
    runner.kill('SIGTERM');
    await exited;
    runner = null;
}

try {
    assert.equal(process.getuid(), 0, 'This fixture needs root.');
    await mkdir(machine, { mode: 0o700 });
    await writeFile(secret, 'machine only', { mode: 0o600 });
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    timer = setTimeout(() => {
        runner?.kill('SIGKILL');
        console.error('The runner did not answer in time.');
        process.exit(1);
    }, 30000);
    runner = start();

    assert.equal((await command('open', 'workspace-a')).exit_code, 0);
    assert.equal((await command('open', 'workspace-b')).exit_code, 0);
    const bId = (await stat(join(root, 'workspace-b'))).uid;

    // A healthy service still writes a log, while its parent remains root's.
    assert.equal(
        (
            await command('start_service', 'workspace-a', {
                port: 0,
                command: ['sh', '-c', 'printf "service started"'],
            })
        ).exit_code,
        0,
    );
    assert.equal((await stat(join(root, '.services', 'workspace-a'))).uid, 0);
    const normal = await exec(
        'workspace-a',
        'sleep 0.1; cat "$LOG"; ln -s "$TARGET" "$LOGDIR/1.log"',
        {
            LOG: join(root, '.services', 'workspace-a', '0.log'),
            LOGDIR: join(root, '.services', 'workspace-a'),
            TARGET: secret,
        },
    );
    assert.equal(normal.output, 'service started');
    assert.notEqual(normal.exit_code, 0, 'A workspace replaced a log name.');

    // Simulate a service directory left writable by an older runner. A
    // workspace can plant a link there before the upgraded runner reclaims it.
    const legacy = join(root, '.services', 'workspace-b');
    await mkdir(legacy, { mode: 0o700 });
    await chown(legacy, bId, bId);
    assert.equal(
        (
            await exec('workspace-b', 'ln -s "$TARGET" "$LOG"', {
                TARGET: secret,
                LOG: join(legacy, '1.log'),
            })
        ).exit_code,
        0,
    );
    const linkedLog = await command('start_service', 'workspace-b', {
        port: 1,
        command: ['true'],
    });
    assert.notEqual(
        linkedLog.exit_code,
        0,
        'The runner accepted a linked log.',
    );
    assert.equal(await readFile(secret, 'utf8'), 'machine only');
    assert.equal((await stat(secret)).uid, 0);

    // A hard link must not make the runner truncate a second file either.
    const businessFile = join(root, 'workspace-b', 'business.txt');
    await writeFile(businessFile, 'keep this');
    await chown(businessFile, bId, bId);
    await link(businessFile, join(legacy, '2.log'));
    assert.notEqual(
        (
            await command('start_service', 'workspace-b', {
                port: 2,
                command: ['true'],
            })
        ).exit_code,
        0,
    );
    assert.equal(await readFile(businessFile, 'utf8'), 'keep this');

    // A workspace controls its temp entry. On restart, home setup must
    // refuse its link without handing over the machine directory.
    assert.equal(
        (
            await exec(
                'workspace-a',
                'rmdir "$TMPDIR"; ln -s "$TARGET" "$TMPDIR"',
                { TARGET: machine },
            )
        ).exit_code,
        0,
    );
    await stop();
    runner = start();
    const afterRestart = await exec('workspace-a', 'cat "$TARGET/secret"', {
        TARGET: machine,
    });
    assert.notEqual(
        afterRestart.exit_code,
        0,
        'Home setup exposed the machine file.',
    );
    assert.equal((await stat(machine)).uid, 0);
    assert.equal((await stat(machine)).mode & 0o777, 0o700);
    assert.equal(await readFile(secret, 'utf8'), 'machine only');
    assert.equal(
        (await exec('workspace-b', 'printf "still works"')).output,
        'still works',
    );
} finally {
    clearTimeout(timer);
    if (runner) await stop();
    server.close();
    await rm(root, { recursive: true, force: true });
}
