// The runner inside a workspace box. It connects out to the control plane,
// takes commands (run a program, write or read a file, unpack the project,
// start a service), runs them in the box and posts the results back. The box
// accepts no connections.
//
// Commands and results travel over HTTPS. The socket (Reverb, which speaks
// the Pusher protocol) only rings a doorbell so new work is fetched at once;
// without it the runner still polls, so a lost socket only slows things down.
//
// Every command runs as a user of its own workspace, never as the runner's
// own user, so code in a workspace cannot read the runner's token, stop the
// runner, or read another workspace (another owner's code). The runner
// gives each workspace a user id of its own when it opens it and keeps it
// as the owner of the workspace folder, so nothing else needs to remember
// it. Each workspace also gets a home and a temp folder of its own. This
// needs the runner to run as root; when it does not (in tests), commands
// run as the runner's user.
//
// Environment:
//   RUNNER_URL    the control plane's base URL, for example http://laravel.test
//   RUNNER_TOKEN  this runner's token
//   RUNNER_ROOT   where workspaces live (default /workspaces)

import { execFileSync, spawn } from 'node:child_process';
import {
    chmodSync,
    chownSync,
    mkdirSync,
    readdirSync,
    rmSync,
    statSync,
    writeFileSync,
} from 'node:fs';
import { join } from 'node:path';
import { Readable } from 'node:stream';

const url = (process.env.RUNNER_URL ?? '').replace(/\/$/, '');
const token = process.env.RUNNER_TOKEN ?? '';
const root = process.env.RUNNER_ROOT ?? '/workspaces';

// Children never inherit the runner's token or settings.
delete process.env.RUNNER_TOKEN;

if (url === '' || token === '') {
    console.error('RUNNER_URL and RUNNER_TOKEN are required.');
    process.exit(1);
}

/** Variables passed through to commands; everything else is left out. */
const PASSTHROUGH = [
    'PATH',
    'LANG',
    'COMPOSER_HOME',
    'HTTPS_PROXY',
    'HTTP_PROXY',
    'NO_PROXY',
    'https_proxy',
    'http_proxy',
    'no_proxy',
    'SSL_CERT_FILE',
    'NODE_EXTRA_CA_CERTS',
    'REQUESTS_CA_BUNDLE',
];

const KILL_AFTER_MS = 5000;

/** Whether the runner may run commands as other users. */
const switching = process.getuid?.() === 0;

/**
 * The user ids workspaces get, far above the image's own users. Each id is
 * also the workspace user's group id.
 */
const FIRST_ID = 20000;
const LAST_ID = 59999;

let settings = { poll_seconds: 5, output_limit: 65536, socket: null };

/** Commands in progress, by id, each with a way to stop it. */
const running = new Map();

/** Services started in each box: box -> list of process group ids. */
const services = new Map();

let wake = null;

function ring() {
    wake?.();
}

function sleep(ms) {
    return new Promise((resolve) => {
        const timer = setTimeout(resolve, ms);

        wake = () => {
            clearTimeout(timer);
            resolve();
        };
    });
}

/** Post to the control plane's runner API and read its answer. */
async function api(path, body = {}) {
    const response = await fetch(`${url}/api/runner/${path}`, {
        method: 'POST',
        headers: {
            Authorization: `Bearer ${token}`,
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error(`${path} answered ${response.status}`);
    }

    return response.status === 204 ? null : response.json();
}

function box(name) {
    if (!/^[a-z0-9-]+$/.test(name ?? '')) {
        throw new Error(`Invalid workspace [${name}].`);
    }

    return join(root, name);
}

function relative(path) {
    if (
        typeof path !== 'string' ||
        path.startsWith('/') ||
        path.split('/').includes('..')
    ) {
        throw new Error(`Path [${path}] must stay inside the workspace.`);
    }

    return path;
}

function homes() {
    return join(root, '.homes');
}

function workspaces() {
    return readdirSync(root).filter((name) => !name.startsWith('.'));
}

/** A user id no open workspace has. */
function freeId() {
    const taken = new Set(
        workspaces().map((name) => statSync(join(root, name)).uid),
    );

    for (let id = FIRST_ID; id <= LAST_ID; id++) {
        if (!taken.has(id)) {
            return id;
        }
    }

    throw new Error('Every workspace user id is taken.');
}

/** Make a folder the workspace user's alone. */
function own(path, id) {
    mkdirSync(path, { recursive: true });
    chownSync(path, id, id);
    chmodSync(path, 0o700);
}

/**
 * Give a workspace a user of its own, with a home and a temp folder, unless
 * it has one. A workspace from before workspaces had users is handed over
 * to a new one.
 */
function settle(name) {
    const directory = box(name);
    let id = statSync(directory).uid;

    if (id < FIRST_ID || id > LAST_ID) {
        id = freeId();
        execFileSync('chown', ['-R', `${id}:${id}`, directory]);
    }

    chmodSync(directory, 0o700);
    own(join(homes(), name), id);
    own(join(homes(), name, 'tmp'), id);

    return id;
}

/** Who runs a workspace's commands, and with which home. */
function identity(name) {
    if (!switching) {
        return { ids: {}, home: process.env.HOME ?? '/tmp' };
    }

    const id = statSync(box(name)).uid;

    if (id < FIRST_ID || id > LAST_ID) {
        throw new Error(`Workspace [${name}] has no user of its own.`);
    }

    return { ids: { uid: id, gid: id }, home: join(homes(), name) };
}

function environment(as, extra = {}) {
    const env = { HOME: as.home };

    if (switching) {
        env.TMPDIR = join(as.home, 'tmp');
    }

    for (const name of PASSTHROUGH) {
        if (process.env[name] !== undefined) {
            env[name] = process.env[name];
        }
    }

    return { ...env, ...extra };
}

/** Signal a process group; one that has already ended is ignored. */
function killGroup(pid, signal) {
    if (pid === undefined) {
        return;
    }

    try {
        process.kill(-pid, signal);
    } catch {
        // Already gone.
    }
}

/** Keep the last "limit" bytes of a stream's output. */
function collector(limit) {
    let chunks = [];
    let size = 0;

    return {
        add(chunk) {
            chunks.push(chunk);
            size += chunk.length;

            while (size > limit && chunks.length > 1) {
                size -= chunks.shift().length;
            }
        },
        buffer() {
            const all = Buffer.concat(chunks);

            return all.length > limit ? all.subarray(all.length - limit) : all;
        },
    };
}

/**
 * Run a program as the command user in its own process group, so stopping
 * it stops everything it started.
 */
function run(
    command,
    { as, cwd, env = {}, timeoutSeconds, input = null, stdout = null },
    id = null,
) {
    const startedAt = Date.now();
    const limit = settings.output_limit * 4;
    const out = collector(limit);
    const err = collector(limit);

    return new Promise((resolve) => {
        let process_;

        try {
            process_ = spawn(command[0], command.slice(1), {
                cwd,
                env: environment(as, env),
                detached: true,
                stdio: [input === null ? 'ignore' : 'pipe', 'pipe', 'pipe'],
                ...as.ids,
            });
        } catch (error) {
            resolve({
                exit_code: 127,
                output: '',
                error_output: String(error.message),
                timed_out: false,
                duration_ms: 0,
            });

            return;
        }

        let timedOut = false;
        let cancelled = false;

        const stop = () => {
            killGroup(process_.pid, 'SIGTERM');
            setTimeout(
                () => killGroup(process_.pid, 'SIGKILL'),
                KILL_AFTER_MS,
            ).unref();
        };

        const timer = setTimeout(() => {
            timedOut = true;
            stop();
        }, timeoutSeconds * 1000);

        if (id !== null) {
            running.set(id, () => {
                cancelled = true;
                stop();
            });
        }

        if (stdout !== null) {
            process_.stdout.on('data', stdout);
        } else {
            process_.stdout.on('data', (chunk) => out.add(chunk));
        }

        process_.stderr.on('data', (chunk) => err.add(chunk));

        if (input !== null) {
            if (input instanceof Readable) {
                input.pipe(process_.stdin);
            } else {
                process_.stdin.end(input);
            }
        }

        const finish = (code, signal, message = '') => {
            clearTimeout(timer);

            if (id !== null) {
                running.delete(id);
            }

            const signals = { SIGTERM: 15, SIGKILL: 9, SIGINT: 2 };

            resolve({
                exit_code: timedOut
                    ? 124
                    : cancelled
                      ? 143
                      : (code ?? 128 + (signals[signal] ?? 1)),
                output: out.buffer().toString('utf8'),
                error_output: err.buffer().toString('utf8') + message,
                timed_out: timedOut,
                duration_ms: Date.now() - startedAt,
            });
        };

        process_.on('error', (error) =>
            finish(127, null, String(error.message)),
        );
        process_.on('close', (code, signal) => finish(code, signal));
    });
}

function ok(extra = {}) {
    return {
        exit_code: 0,
        output: '',
        error_output: '',
        timed_out: false,
        duration_ms: 0,
        ...extra,
    };
}

function failed(message) {
    return {
        exit_code: 1,
        output: '',
        error_output: message,
        timed_out: false,
        duration_ms: 0,
    };
}

function servicesDirectory(name) {
    return join(root, '.services', name);
}

function stopServices(name) {
    for (const group of services.get(name) ?? []) {
        killGroup(group, 'SIGTERM');
    }

    services.delete(name);
}

const handlers = {
    async open(command) {
        const directory = box(command.box);

        mkdirSync(directory, { recursive: true });

        if (switching) {
            settle(command.box);
        }

        return ok();
    },

    async close(command) {
        stopServices(command.box);
        rmSync(box(command.box), { recursive: true, force: true });
        rmSync(join(homes(), command.box), { recursive: true, force: true });
        rmSync(servicesDirectory(command.box), {
            recursive: true,
            force: true,
        });

        return ok();
    },

    async exec(command) {
        const { command: argv, env } = command.payload;

        return run(
            argv,
            {
                as: identity(command.box),
                cwd: box(command.box),
                env,
                timeoutSeconds: command.timeout_seconds,
            },
            command.id,
        );
    },

    async write(command) {
        const path = relative(command.payload.path);

        return run(
            [
                'sh',
                '-c',
                'mkdir -p "$(dirname "$1")" && cat > "$1"',
                'sh',
                path,
            ],
            {
                as: identity(command.box),
                cwd: box(command.box),
                timeoutSeconds: command.timeout_seconds,
                input: Buffer.from(command.payload.contents, 'base64'),
            },
        );
    },

    async read(command) {
        const tailBytes = command.payload.tail_bytes;

        if (
            tailBytes !== undefined &&
            (!Number.isSafeInteger(tailBytes) || tailBytes < 0)
        ) {
            throw new Error(
                'The file tail size must be a nonnegative integer.',
            );
        }

        const read =
            tailBytes === undefined
                ? ['cat']
                : ['tail', '-c', String(tailBytes)];
        const chunks = [];
        const result = await run(
            [...read, '--', relative(command.payload.path)],
            {
                as: identity(command.box),
                cwd: box(command.box),
                timeoutSeconds: command.timeout_seconds,
                stdout: (chunk) => chunks.push(chunk),
            },
        );

        return {
            ...result,
            contents: Buffer.concat(chunks).toString('base64'),
        };
    },

    async unpack(command) {
        const response = await fetch(
            `${url}/api/runner/commands/${command.id}/archive`,
            {
                headers: { Authorization: `Bearer ${token}` },
            },
        );

        if (!response.ok || response.body === null) {
            return failed(`Could not fetch the project (${response.status}).`);
        }

        return run(['tar', '--no-same-owner', '-xzf', '-'], {
            as: identity(command.box),
            cwd: box(command.box),
            timeoutSeconds: command.timeout_seconds,
            input: Readable.fromWeb(response.body),
        });
    },

    async start_service(command) {
        const { command: argv, port } = command.payload;
        const directory = servicesDirectory(command.box);
        const as = identity(command.box);

        mkdirSync(directory, { recursive: true });

        const log = join(directory, `${Number(port)}.log`);

        writeFileSync(log, '');

        if (switching) {
            own(directory, as.ids.uid);
            chownSync(log, as.ids.uid, as.ids.gid);
        }

        const service = spawn(
            'sh',
            [
                '-c',
                'log="$1"; shift; exec "$@" > "$log" 2>&1 < /dev/null',
                'sh',
                log,
                ...argv,
            ],
            {
                cwd: box(command.box),
                env: environment(as),
                detached: true,
                stdio: 'ignore',
                ...as.ids,
            },
        );

        service.unref();
        services.set(command.box, [
            ...(services.get(command.box) ?? []),
            service.pid,
        ]);

        return ok();
    },
};

async function report(id, result) {
    for (let attempt = 0; attempt < 10; attempt++) {
        try {
            await api(`commands/${id}/result`, result);

            return;
        } catch (error) {
            console.error(`Could not report ${id}: ${error.message}`);
            await new Promise((resolve) =>
                setTimeout(resolve, Math.min(1000 * 2 ** attempt, 30000)),
            );
        }
    }
}

async function handle(command) {
    const handler = handlers[command.type];
    let result;

    try {
        result = handler
            ? await handler(command)
            : failed(`Unknown command [${command.type}].`);
    } catch (error) {
        result = failed(String(error.message));
    }

    await report(command.id, result);
}

async function claim() {
    const work = await api('commands/claim');

    for (const id of work.cancel) {
        running.get(id)?.();
    }

    // Commands run side by side: reading a file must not wait for a
    // long test run in the same box.
    for (const command of work.commands) {
        void handle(command);
    }
}

function listen(socket, attempt = 0) {
    const ws = new WebSocket(
        `${socket.url.replace(/\/$/, '')}/app/${socket.key}?protocol=7&client=box-runner&version=1.0`,
    );

    ws.onmessage = async (message) => {
        const { event, data } = JSON.parse(message.data);

        if (event === 'pusher:connection_established') {
            attempt = 0;

            try {
                const { auth } = await api('socket-auth', {
                    socket_id: JSON.parse(data).socket_id,
                    channel_name: socket.channel,
                });

                ws.send(
                    JSON.stringify({
                        event: 'pusher:subscribe',
                        data: { channel: socket.channel, auth },
                    }),
                );
            } catch (error) {
                console.error(`Could not subscribe: ${error.message}`);
                ws.close();
            }
        } else if (event === 'pusher:ping') {
            ws.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
        } else if (
            event === 'work' ||
            event === 'pusher_internal:subscription_succeeded'
        ) {
            ring();
        }
    };

    ws.onclose = () => {
        setTimeout(
            () => listen(socket, attempt + 1),
            Math.min(1000 * 2 ** attempt, 30000),
        );
    };

    ws.onerror = () => {};
}

async function main() {
    mkdirSync(root, { recursive: true });

    // Workspaces may pass through the root and the shared folders to their
    // own, but not list or read the others.
    if (switching) {
        for (const shared of [root, homes(), join(root, '.services')]) {
            mkdirSync(shared, { recursive: true });
            chmodSync(shared, 0o711);
        }

        for (const name of workspaces()) {
            try {
                settle(name);
            } catch (error) {
                console.error(`Could not settle ${name}: ${error.message}`);
            }
        }
    }

    for (;;) {
        try {
            settings = await api('hello');
            break;
        } catch (error) {
            console.error(`Waiting for the control plane: ${error.message}`);
            await new Promise((resolve) => setTimeout(resolve, 3000));
        }
    }

    console.log(`Runner ${settings.runner} is ready.`);

    if (settings.socket) {
        listen(settings.socket);
    }

    for (;;) {
        try {
            await claim();
        } catch (error) {
            console.error(`Could not fetch work: ${error.message}`);
        }

        await sleep(settings.poll_seconds * 1000);
    }
}

process.on('SIGTERM', () => {
    for (const stop of running.values()) {
        stop();
    }

    for (const name of services.keys()) {
        stopServices(name);
    }

    process.exit(0);
});

void main();
