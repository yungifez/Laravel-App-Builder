// Keep an editable preview's frontend build running in watch mode, so the
// rebuild after an edit builds only what changed (under a second, where a
// fresh build takes a few).
//
// Runs in a preview workspace only, from the app's root. "state" is a
// directory of ours in the workspace that the build does not watch.
//
//   node watch-build.mjs watch <state> <started> <done> <command ...>
//     Run the build command. Each time its output has <started> or <done>
//     in it, write "<pid> <builds> building|built" to <state>/state.
//
//   node watch-build.mjs wait <state> <quiet ms> <timeout s>
//     Wait for the first build to end, giving the watcher a few seconds to
//     start.
//
//   node watch-build.mjs place <state> <quiet ms> <timeout s> [path ...]
//     Move the files staged under <state>/stage into the app, remove each
//     given path, and wait for the build that takes them in.
//
// Waiting ends once a build has ended and no other has started for
// <quiet ms>: a change made during a build starts another one right after.
// Exit codes: 3 when no watcher runs (nothing is moved in, and the staged
// files are dropped), 4 when the build did not end in time.

import { spawn } from 'node:child_process';
import {
    mkdirSync,
    readdirSync,
    readFileSync,
    renameSync,
    rmSync,
    statSync,
    writeFileSync,
} from 'node:fs';
import { dirname, join, relative } from 'node:path';

const [mode, state, ...rest] = process.argv.slice(2);
const stateFile = join(state ?? '', 'state');

if (mode === 'watch') {
    watch(...rest);
} else if (mode === 'wait') {
    // The watcher may not have written its state yet.
    process.exit(await settle(0, Number(rest[0]), Number(rest[1]), 5000));
} else if (mode === 'place') {
    const [quiet, timeout, ...removed] = rest;
    const before = read();

    if (before === null) {
        // The files are copied in as usual instead.
        rmSync(join(state, 'stage'), { recursive: true, force: true });
        process.exit(3);
    }

    place(join(state, 'stage'), removed);
    process.exit(
        await settle(before.builds + 1, Number(quiet), Number(timeout)),
    );
} else {
    console.error(`Unknown mode "${mode}".`);
    process.exit(2);
}

function watch(started, done, ...command) {
    mkdirSync(state, { recursive: true });

    let builds = 0;
    let pending = '';
    const write = (status) => {
        writeFileSync(
            `${stateFile}.next`,
            `${process.pid} ${builds} ${status}`,
        );
        renameSync(`${stateFile}.next`, stateFile);
    };

    write('building');

    const build = spawn(command[0], command.slice(1), {
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    const scan = (chunk) => {
        process.stdout.write(chunk);

        const lines = (pending + chunk.toString()).split('\n');
        pending = lines.pop() ?? '';

        for (const line of lines) {
            if (line.includes(done)) {
                builds++;
                write('built');
            } else if (line.includes(started)) {
                write('building');
            }
        }
    };

    build.stdout.on('data', scan);
    build.stderr.on('data', scan);
    build.on('exit', (code) => {
        rmSync(stateFile, { force: true });
        process.exit(code ?? 1);
    });

    for (const signal of ['SIGTERM', 'SIGINT']) {
        process.on(signal, () => build.kill(signal));
    }
}

// The watcher's state, or null when it is not running.
function read() {
    let text;

    try {
        text = readFileSync(stateFile, 'utf8');
    } catch {
        return null;
    }

    const [pid, builds, status] = text.trim().split(' ');

    try {
        process.kill(Number(pid), 0);
    } catch {
        return null;
    }

    return { builds: Number(builds), status };
}

function place(stage, removed) {
    for (const file of files(stage)) {
        const target = relative(stage, file);

        mkdirSync(dirname(target), { recursive: true });
        renameSync(file, target);
    }

    for (const path of removed) {
        rmSync(path, { force: true });
    }
}

function* files(directory) {
    let entries;

    try {
        entries = readdirSync(directory);
    } catch {
        return;
    }

    for (const entry of entries) {
        const path = join(directory, entry);

        if (statSync(path).isDirectory()) {
            yield* files(path);
        } else {
            yield path;
        }
    }
}

async function settle(builds, quietMs, timeoutSeconds, graceMs = 0) {
    const startedAt = Date.now();
    const giveUpAt = startedAt + timeoutSeconds * 1000;
    let quietSince = null;

    while (Date.now() < giveUpAt) {
        const now = read();

        if (now === null) {
            if (Date.now() - startedAt >= graceMs) {
                return 3;
            }

            await new Promise((resolve) => setTimeout(resolve, 20));
            continue;
        }

        if (now.status === 'built' && now.builds >= Math.max(builds, 1)) {
            quietSince ??= { at: Date.now(), builds: now.builds };

            if (quietSince.builds !== now.builds) {
                quietSince = { at: Date.now(), builds: now.builds };
            } else if (Date.now() - quietSince.at >= quietMs) {
                return 0;
            }
        } else {
            quietSince = null;
        }

        await new Promise((resolve) => setTimeout(resolve, 20));
    }

    return 4;
}
