// Runs the real box runner as root against a fake control plane and checks
// that each workspace's commands run as a user of its own, who cannot read
// another workspace. Run it as root: node box-runner-users.mjs runner.mjs
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { once } from 'node:events';
import { chown, mkdir, mkdtemp, rm, stat, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const root = await mkdtemp(join(tmpdir(), 'builder-runner-users-'));
const exec = (box, script, env = {}) => ({
    type: 'exec',
    box,
    payload: { command: ['sh', '-c', script], env },
});
// A file of the machine, owned by root, that no workspace may read.
const machineFile = join(root, '.machine-environment');

// Each phase starts once the one before it has answered.
const phases = [
    [
        { type: 'open', box: 'workspace-a' },
        { type: 'open', box: 'workspace-b' },
    ],
    [
        {
            type: 'write',
            box: 'workspace-b',
            payload: {
                path: 'secret.txt',
                contents: Buffer.from('b only').toString('base64'),
            },
        },
        exec('workspace-a', 'id -u; echo "$HOME"; echo "$TMPDIR"'),
        exec('workspace-b', 'id -u'),
    ],
    [
        exec('workspace-a', 'cat ../workspace-b/secret.txt'),
        exec('workspace-a', 'ls ..'),
        exec('workspace-a', 'ls ../workspace-old'),
        exec('workspace-b', 'cat secret.txt'),
        exec('workspace-old', 'id -u; touch made-later'),
    ],
    [
        // A workspace's own variables, and a process it leaves running.
        exec(
            'workspace-a',
            "mkdir -p .git && printf 'FROM_FILE=file\\nOVERRIDE=file\\nLD_PRELOAD=/nowhere.so\\nPATH=/nowhere\\nlower=x\\n' > .git/environment && chmod 600 .git/environment && (setsid sleep 300 > /dev/null 2>&1 &)",
        ),
        // A link to a file the workspace may not read.
        exec(
            'workspace-b',
            `mkdir -p .git && ln -s ${machineFile} .git/environment`,
        ),
    ],
    [
        exec(
            'workspace-a',
            'echo "$FROM_FILE|$OVERRIDE|${LD_PRELOAD:-none}|$PATH|${lower:-none}"',
            { OVERRIDE: 'plane' },
        ),
        exec('workspace-b', 'echo "${SECRET:-none}"'),
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
let runner;
let timer;

try {
    // A workspace from before workspaces had users of their own.
    await mkdir(join(root, 'workspace-old'));
    await writeFile(join(root, 'workspace-old', 'code.php'), '<?php');
    await chown(join(root, 'workspace-old'), 1337, 1000);
    await chown(join(root, 'workspace-old', 'code.php'), 1337, 1000);

    await writeFile(machineFile, 'SECRET=leaked\n');

    release();
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    runner = spawn(process.execPath, [process.argv[2]], {
        env: {
            PATH: process.env.PATH,
            RUNNER_URL: `http://127.0.0.1:${server.address().port}`,
            RUNNER_TOKEN: 'test-token',
            RUNNER_ROOT: root,
        },
        stdio: ['ignore', 'ignore', 'inherit'],
    });
    timer = setTimeout(() => {
        console.error('The runner did not answer in time.');
        process.exit(1);
    }, 30000);
    await finished;

    const out = (id) => results.get(String(id));
    const [aId, aHome, aTmp] = out(3).output.trim().split('\n');
    const bId = out(4).output.trim();

    assert.equal(out(0).exit_code, 0);
    assert.equal(out(1).exit_code, 0);
    assert.equal(out(2).exit_code, 0, out(2).error_output);
    assert.ok(Number(aId) >= 20000, `workspace-a runs as ${aId}`);
    assert.ok(Number(bId) >= 20000, `workspace-b runs as ${bId}`);
    assert.notEqual(aId, bId);
    assert.equal(aHome, join(root, '.homes', 'workspace-a'));
    assert.equal(aTmp, join(root, '.homes', 'workspace-a', 'tmp'));

    // One workspace cannot read or list another, or list the root.
    assert.notEqual(out(5).exit_code, 0, 'a read b');
    assert.equal(out(5).output, '');
    assert.notEqual(out(6).exit_code, 0, 'a listed the workspaces');
    assert.notEqual(out(7).exit_code, 0, 'a listed the old workspace');
    assert.equal(out(8).output, 'b only');

    // The old workspace was handed to a user of its own, files and all.
    const oldId = Number(out(9).output.trim());
    assert.equal(out(9).exit_code, 0, out(9).error_output);
    assert.ok(oldId >= 20000 && oldId !== Number(aId) && oldId !== Number(bId));
    assert.equal(
        (await stat(join(root, 'workspace-old', 'code.php'))).uid,
        oldId,
    );
    assert.equal((await stat(join(root, 'workspace-old'))).mode & 0o777, 0o700);
    assert.equal((await stat(root)).mode & 0o777, 0o711);

    // The workspace's own variables reach its commands, the control
    // plane's win, and names that change how programs load never pass.
    assert.equal(out(10).exit_code, 0, out(10).error_output);
    assert.equal(out(11).exit_code, 0, out(11).error_output);
    assert.equal(
        out(12).output.trim(),
        `file|plane|none|${process.env.PATH}|none`,
    );
    // A link is never followed.
    assert.equal(out(13).output.trim(), 'none');

    // Closing a workspace stops what its user left running. A stopped
    // process this script started is not reaped here, so only processes
    // still alive count.
    assert.equal(out(14).exit_code, 0, out(14).error_output);
    const alive = execFileSync('ps', ['-eo', 'uid=,stat='])
        .toString()
        .split('\n')
        .filter((line) => {
            const [uid, state] = line.trim().split(/\s+/);

            return uid === aId && !state.startsWith('Z');
        });
    assert.deepEqual(alive, [], 'workspace-a still runs a process');
} finally {
    clearTimeout(timer);
    runner?.kill('SIGTERM');
    server.close();
    await rm(root, { recursive: true, force: true });
}
