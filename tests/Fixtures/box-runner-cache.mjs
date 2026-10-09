// Runs the real box runner against a fake control plane, with fake
// composer and npm that say whether they found their packages in place
// ("warm"), only their downloads ("downloads-warm") or nothing ("cold"),
// and checks the dependency cache: a lock seen before is warm, a changed
// lock or another app starts cold, a workspace's own changes never reach
// the cache, and an entry a fill left unfinished is never used. As root it
// also checks that a workspace gets its packages as its own user and cannot
// reach the cache. Run: node box-runner-cache.mjs runner.mjs
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import {
    chmod,
    mkdir,
    mkdtemp,
    readFile,
    readdir,
    rm,
    stat,
    writeFile,
} from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const asRoot = process.getuid?.() === 0;
const root = await mkdtemp(join(tmpdir(), 'builder-runner-cache-'));
const bin = await mkdtemp(join(tmpdir(), 'builder-runner-cache-bin-'));
const home = await mkdtemp(join(tmpdir(), 'builder-runner-cache-home-'));
const cache = join(root, '.dependencies');

const COMPOSER = `#!/bin/sh
set -e
[ "$1" = install ] || exit 2
grep -q FAIL composer.lock && exit 1
# A fill has the app's scripts off and no app code to run.
case " $* " in *" --no-scripts "*) [ ! -e artisan ] || exit 3 ;; esac
key=$(sha256sum composer.lock | cut -c1-16)
if [ -f vendor/installed ] && [ "$(cat vendor/installed)" = "$key" ]; then echo warm; exit 0; fi
if [ -n "$COMPOSER_CACHE_DIR" ] && [ -f "$COMPOSER_CACHE_DIR/$key" ]; then echo downloads-warm; else echo cold; fi
rm -rf vendor && mkdir vendor && echo "$key" > vendor/installed && echo original > vendor/package.php
[ -z "$COMPOSER_CACHE_DIR" ] || { mkdir -p "$COMPOSER_CACHE_DIR" && touch "$COMPOSER_CACHE_DIR/$key"; }
`;
const NPM = `#!/bin/sh
set -e
[ "$1" = ci ] || exit 2
key=$(sha256sum package-lock.json | cut -c1-16)
rm -rf node_modules
if [ -n "$npm_config_cache" ] && [ -f "$npm_config_cache/$key" ]; then echo downloads-warm; else echo cold; fi
mkdir node_modules && echo "$key" > node_modules/installed
[ -z "$npm_config_cache" ] || { mkdir -p "$npm_config_cache" && touch "$npm_config_cache/$key"; }
`;

const composer = (box, scope = 'project-1') => ({
    type: 'exec',
    box,
    payload: {
        command: [
            'composer',
            'install',
            '--no-interaction',
            '--prefer-dist',
            '--no-progress',
        ],
        env: {},
        cache: { scope, kind: 'composer' },
    },
});
const npm = (box) => ({
    type: 'exec',
    box,
    payload: {
        command: ['npm', 'ci'],
        env: {},
        cache: { scope: 'project-1', kind: 'npm' },
    },
});
const exec = (box, script) => ({
    type: 'exec',
    box,
    payload: { command: ['sh', '-c', script], env: {} },
});
const write = (box, path, contents) => ({
    type: 'write',
    box,
    payload: { path, contents: Buffer.from(contents).toString('base64') },
});
const app = (box, lock) => [
    write(box, 'composer.json', '{"name": "acme/app"}'),
    write(box, 'composer.lock', lock),
    write(box, 'package.json', '{"name": "app"}'),
    write(box, 'package-lock.json', '{"lockfileVersion": 3}'),
    // App code: never seen by a fill.
    write(box, 'artisan', '<?php'),
];

async function waitFor(check, what) {
    for (let attempt = 0; attempt < 200; attempt++) {
        if (await check()) {
            return;
        }

        await new Promise((resolve) => setTimeout(resolve, 50));
    }

    throw new Error(`Timed out waiting for ${what}.`);
}

async function entries(scope, prefix) {
    try {
        return (await readdir(join(cache, scope))).filter((name) =>
            name.startsWith(prefix),
        );
    } catch {
        return [];
    }
}

const boxes = ['a', 'b', 'c', 'd', 'e', 'g'].map((name) => `workspace-${name}`);

// Each phase starts once the one before it has answered and its "after"
// has passed.
const phases = [
    { commands: boxes.map((box) => ({ type: 'open', box })) },
    {
        commands: [
            ...app('workspace-a', 'lock one'),
            ...app('workspace-b', 'lock one'),
            ...app('workspace-c', 'lock two'),
            ...app('workspace-d', 'lock FAIL'),
            ...app('workspace-e', 'lock one'),
            ...app('workspace-g', 'lock one'),
        ],
    },
    // Nothing cached yet, and an install that fails.
    {
        commands: [
            composer('workspace-a'),
            npm('workspace-a'),
            composer('workspace-d'),
        ],
        after: async () => {
            await waitFor(
                async () =>
                    (await entries('project-1', 'vendor-')).length === 1 &&
                    (await entries('project-1', 'filled-')).length === 1,
                'the first fill',
            );
            [lockOne] = await entries('project-1', 'vendor-');
        },
    },
    // The same lock again; the same lock in another app; a changed lock.
    {
        commands: [
            composer('workspace-b'),
            npm('workspace-b'),
            composer('workspace-g', 'project-2'),
            composer('workspace-c'),
        ],
    },
    // A workspace changes its packages; nothing of it reaches the cache.
    {
        commands: [
            exec(
                'workspace-b',
                'echo changed > vendor/package.php && id -u && stat -c %u vendor/package.php && { ls ../.dependencies/project-1 || ls ../.dependencies; }',
            ),
        ],
        after: async () => {
            await waitFor(
                async () =>
                    (await entries('project-1', 'vendor-')).length === 2 &&
                    (await entries('project-2', 'vendor-')).length === 1,
                'the fills of the changed lock and the other app',
            );

            // A fill cut short: the entry has no mark that it is complete.
            await rm(join(cache, 'project-1', lockOne, 'complete'));
        },
    },
    { commands: [composer('workspace-e')] },
];

let lockOne;
let phase = 0;
let pending = [];
let next = 0;
const results = new Map();
const sent = [];
let finish;
let fail;
const finished = new Promise((resolve, reject) => {
    finish = resolve;
    fail = reject;
});

function release() {
    pending = phases[phase].commands.map((command) => ({
        id: String(next++),
        timeout_seconds: 20,
        ...command,
    }));
    sent.push(...pending);
}

async function advance() {
    try {
        await phases[phase].after?.();
    } catch (error) {
        fail(error);

        return;
    }

    phase++;

    if (phase < phases.length) {
        release();
    } else {
        finish();
    }
}

const server = createServer(async (request, response) => {
    let answer = {};

    if (request.url.endsWith('/hello')) {
        answer = {
            runner: 'test',
            poll_seconds: 0.02,
            output_limit: 4096,
            dependency_cache: { directory: '.dependencies', limit_mb: 100 },
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
            void advance();
        }
    }

    response.writeHead(200, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify(answer));
});
let runner;
let timer;

// The output of the command of a phase, by its place in the phase.
const out = (phaseIndex, place) => {
    let id = 0;

    for (let index = 0; index < phaseIndex; index++) {
        id += phases[index].commands.length;
    }

    return results.get(String(id + place));
};
const said = (phaseIndex, place) => {
    const result = out(phaseIndex, place);

    assert.equal(result.exit_code, 0, result.error_output);

    return result.output.trim();
};

try {
    await writeFile(join(bin, 'composer'), COMPOSER);
    await writeFile(join(bin, 'npm'), NPM);
    await chmod(join(bin, 'composer'), 0o755);
    await chmod(join(bin, 'npm'), 0o755);
    await chmod(bin, 0o755);

    // What a runner stopped mid-fill left behind.
    await mkdir(join(cache, '.fill-old', 'app'), { recursive: true });

    release();
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    runner = spawn(process.execPath, [process.argv[2]], {
        env: {
            PATH: `${bin}:${process.env.PATH}`,
            HOME: home,
            RUNNER_URL: `http://127.0.0.1:${server.address().port}`,
            RUNNER_TOKEN: 'test-token',
            RUNNER_ROOT: root,
            RUNNER_FIREWALL: 'off',
        },
        stdio: ['ignore', 'ignore', 'inherit'],
    });
    timer = setTimeout(() => {
        console.error('The runner did not answer in time.');
        process.exit(1);
    }, 60000);
    await finished;

    // Base: nothing was cached, so the first install ran cold. A failed
    // install filled nothing.
    assert.equal(said(2, 0), 'cold');
    assert.equal(said(2, 1), 'cold');
    assert.notEqual(out(2, 2).exit_code, 0);
    await assert.rejects(stat(join(root, '.dependencies', '.fill-old')));

    // The same lock: its packages were put back, and npm had its downloads.
    assert.equal(said(3, 0), 'warm');
    assert.equal(said(3, 1), 'downloads-warm');
    // Another app shares nothing, and a changed lock starts cold.
    assert.equal(said(3, 2), 'cold');
    assert.equal(said(3, 3), 'cold');

    // The workspace's own change stayed in the workspace.
    for (const name of await entries('project-1', 'vendor-')) {
        assert.equal(
            (
                await readFile(
                    join(cache, 'project-1', name, 'tree', 'package.php'),
                    'utf8',
                )
            ).trim(),
            'original',
        );
    }

    // An entry a fill left unfinished is never used, and is made again.
    assert.notEqual(said(5, 0), 'warm');
    await waitFor(async () => {
        try {
            await stat(join(cache, 'project-1', lockOne, 'complete'));

            return true;
        } catch {
            return false;
        }
    }, 'the unfinished entry to be made again');

    if (asRoot) {
        // The packages are the workspace's own, and the cache is out of
        // its reach.
        const result = out(4, 0);
        const [uid, owner] = result.output.trim().split('\n');

        assert.notEqual(result.exit_code, 0, 'the workspace listed the cache');
        assert.equal(owner, uid);
        assert.ok(Number(uid) >= 20000 && Number(uid) < 59999);
        assert.equal((await stat(cache)).mode & 0o777, 0o711);
        assert.equal(
            (await stat(join(cache, 'project-1'))).mode & 0o777,
            0o700,
        );
        assert.equal((await stat(cache)).uid, 0);
    }
} finally {
    clearTimeout(timer);
    runner?.kill('SIGTERM');
    server.close();
    await rm(root, { recursive: true, force: true });
    await rm(bin, { recursive: true, force: true });
    await rm(home, { recursive: true, force: true });
}
