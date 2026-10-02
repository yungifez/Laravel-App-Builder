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
//   RUNNER_SERVICE_HOST  the address the control plane reaches this
//                 machine's previews at, such as its private network
//                 address; previews listen only there
//   RUNNER_FIREWALL  "off" to leave the machine's firewall alone; by
//                 default the runner fences workspaces in when it can

import { execFileSync, spawn } from 'node:child_process';
import {
    chmodSync,
    chownSync,
    closeSync,
    constants,
    fstatSync,
    mkdirSync,
    openSync,
    readdirSync,
    readSync,
    rmSync,
    statfsSync,
    statSync,
    writeFileSync,
} from 'node:fs';
import { join } from 'node:path';
import { Readable } from 'node:stream';

const url = (process.env.RUNNER_URL ?? '').replace(/\/$/, '');
const token = process.env.RUNNER_TOKEN ?? '';
const root = process.env.RUNNER_ROOT ?? '/workspaces';
const serviceHost = process.env.RUNNER_SERVICE_HOST || null;

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

/**
 * Variables a workspace's own tools may set for its commands, in
 * .git/environment: never committed, and gone when the workspace closes.
 * Names that change how programs load or where they look, and the ones the
 * runner sets itself, are never taken from it.
 */
const OWN_ENVIRONMENT = join('.git', 'environment');
const OWN_ENVIRONMENT_LIMIT = 16 * 1024;
const RESERVED = new Set([
    'NODE_OPTIONS',
    'BASH_ENV',
    'ENV',
    'HOME',
    'TMPDIR',
    ...PASSTHROUGH,
]);

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

/**
 * The firewall, on a machine where the runner may use iptables (root with
 * NET_ADMIN, as on a runner VM). Workspace users may not reach private
 * networks, where the control plane's database and cache live, or the
 * cloud's metadata address. On this machine, a preview's port answers only
 * its own workspace (and the control plane, which comes from outside).
 * Without iptables, as in local Docker, the runner goes on without it.
 */
const FIREWALLS = [
    {
        program: 'iptables',
        reject: 'icmp-port-unreachable',
        private: [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '169.254.0.0/16',
            '100.64.0.0/10',
        ],
    },
    {
        program: 'ip6tables',
        reject: 'icmp6-port-unreachable',
        private: ['fc00::/7', 'fe80::/10'],
    },
];
const FENCE = 'BUILDER-WORKSPACES';
const PORTS = 'BUILDER-PREVIEWS';
let fences = [];

function firewall(program, args) {
    return execFileSync(program, ['-w', ...args], {
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });
}

/** Run a firewall change; one that cannot be made is reported, not fatal. */
function fenceEach(change) {
    for (const fence of fences) {
        try {
            change(fence);
        } catch (error) {
            console.error(`Firewall (${fence.program}): ${error.message}`);
        }
    }
}

/** Remove the preview rules a test picks out of the chain's listing. */
function unfence(fence, pick) {
    for (const rule of firewall(fence.program, ['-S', PORTS]).split('\n')) {
        if (rule.startsWith(`-A ${PORTS} `) && pick(rule)) {
            firewall(
                fence.program,
                ['-D', ...rule.slice(3).split(' ')].map((part) =>
                    part.replace(/^"|"$/g, ''),
                ),
            );
        }
    }
}

/**
 * Set the firewall up, keeping the preview rules of workspaces that are
 * still here so a restart of the runner leaves them fenced.
 */
function fenceUp() {
    if (!switching || process.env.RUNNER_FIREWALL === 'off') {
        return;
    }

    for (const fence of FIREWALLS) {
        try {
            firewall(fence.program, ['-S', 'OUTPUT']);
        } catch {
            continue;
        }

        try {
            for (const chain of [FENCE, PORTS]) {
                try {
                    firewall(fence.program, ['-N', chain]);
                } catch {
                    // Made by an earlier start.
                }
            }

            const users = [
                '-m',
                'owner',
                '--uid-owner',
                `${FIRST_ID}-${LAST_ID}`,
            ];
            firewall(fence.program, ['-F', FENCE]);

            for (const rule of [
                ['-o', 'lo', '-j', PORTS],
                ['-o', 'lo', '-j', 'RETURN'],
                ['-p', 'udp', '--dport', '53', '-j', 'RETURN'],
                ['-p', 'tcp', '--dport', '53', '-j', 'RETURN'],
                ...fence.private.map((range) => [
                    '-d',
                    range,
                    '-j',
                    'REJECT',
                    '--reject-with',
                    fence.reject,
                ]),
            ]) {
                firewall(fence.program, ['-A', FENCE, ...rule]);
            }

            try {
                firewall(fence.program, [
                    '-C',
                    'OUTPUT',
                    ...users,
                    '-j',
                    FENCE,
                ]);
            } catch {
                firewall(fence.program, [
                    '-I',
                    'OUTPUT',
                    '1',
                    ...users,
                    '-j',
                    FENCE,
                ]);
            }

            fences.push(fence);
        } catch (error) {
            console.error(
                `Firewall (${fence.program}) is off: ${error.message}`,
            );
        }
    }

    const here = new Set(workspaces());
    fenceEach((fence) =>
        unfence(fence, (rule) => {
            const owner = rule.match(/--comment "?builder:([^" ]+)/);

            return owner === null || !here.has(owner[1]);
        }),
    );

    console.log(
        fences.length > 0
            ? `Firewall is on (${fences.map((fence) => fence.program).join(', ')}).`
            : 'Firewall is off: this machine does not let the runner use iptables.',
    );
}

/** Let only the workspace's own user reach a preview port on this machine. */
function fencePort(name, port, uid) {
    fenceEach((fence) => {
        // A port handed to another workspace before keeps no old rule.
        unfence(fence, (rule) => rule.includes(` --dport ${port} `));
        firewall(fence.program, [
            '-A',
            PORTS,
            '-p',
            'tcp',
            '--dport',
            String(port),
            '-m',
            'owner',
            '!',
            '--uid-owner',
            String(uid),
            '-m',
            'comment',
            '--comment',
            `builder:${name}`,
            '-j',
            'REJECT',
            '--reject-with',
            'tcp-reset',
        ]);
    });
}

function unfenceWorkspace(name) {
    fenceEach((fence) =>
        unfence(
            fence,
            (rule) => /--comment "?builder:([^" ]+)/.exec(rule)?.[1] === name,
        ),
    );
}

/**
 * Read a workspace's own variables. The runner may be root, so the file is
 * read only when it is a small regular file the workspace's user owns, and
 * never through a link: a link could point at a file of the machine or of
 * another workspace.
 */
function ownEnvironment(name, as) {
    let file;

    try {
        file = openSync(
            join(box(name), OWN_ENVIRONMENT),
            constants.O_RDONLY | constants.O_NOFOLLOW | constants.O_NONBLOCK,
        );
    } catch {
        return {};
    }

    try {
        const stats = fstatSync(file);

        if (
            !stats.isFile() ||
            stats.uid !== (as.ids.uid ?? process.getuid?.()) ||
            stats.size > OWN_ENVIRONMENT_LIMIT
        ) {
            return {};
        }

        const contents = Buffer.alloc(stats.size);
        const size = readSync(file, contents, 0, stats.size, 0);
        const env = {};

        for (const line of contents.subarray(0, size).toString().split('\n')) {
            const match = /^([A-Z_][A-Z0-9_]*)=(.*)$/.exec(line);

            if (
                match !== null &&
                !match[1].startsWith('LD_') &&
                !match[1].startsWith('RUNNER_') &&
                !RESERVED.has(match[1])
            ) {
                env[match[1]] = match[2];
            }
        }

        return env;
    } finally {
        closeSync(file);
    }
}

/**
 * The environment of a command run as a workspace's user: the runner's
 * own few variables, the workspace's own ones (when "workspace" names it),
 * then what the control plane sent, which wins.
 */
function environment(as, extra = {}, workspace = null) {
    const env = { HOME: as.home };

    if (switching) {
        env.TMPDIR = join(as.home, 'tmp');
    }

    for (const name of PASSTHROUGH) {
        if (process.env[name] !== undefined) {
            env[name] = process.env[name];
        }
    }

    return {
        ...env,
        ...(workspace === null ? {} : ownEnvironment(workspace, as)),
        ...extra,
    };
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
    {
        as,
        cwd,
        env = {},
        workspace = null,
        timeoutSeconds,
        input = null,
        stdout = null,
    },
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
                env: environment(as, env, workspace),
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

/**
 * Stop whatever the workspace's user still runs, such as a database server
 * a command started in the background, before the user id is given to
 * another workspace.
 */
function stopEverything(name) {
    if (!switching) {
        return;
    }

    let id;

    try {
        id = statSync(box(name)).uid;
    } catch {
        return;
    }

    if (id < FIRST_ID || id > LAST_ID) {
        return;
    }

    // A process may start another while the first are stopped.
    for (let attempt = 0; attempt < 5; attempt++) {
        try {
            execFileSync('pkill', ['-KILL', '-u', String(id)], {
                stdio: 'ignore',
            });
        } catch {
            return; // None left.
        }
    }
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
        stopEverything(command.box);
        unfenceWorkspace(command.box);
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
                workspace: command.box,
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
            fencePort(command.box, Number(port), as.ids.uid);
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
                env: environment(as, {}, command.box),
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

// The free disk space where workspaces live, so the control plane gives
// new workspaces to machines with room.
function diskFreeMb() {
    try {
        const stats = statfsSync(root);

        return Math.floor((stats.bavail * stats.bsize) / 1048576);
    } catch {
        return undefined;
    }
}

async function claim() {
    const work = await api('commands/claim', { disk_free_mb: diskFreeMb() });

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

        fenceUp();
    }

    for (;;) {
        try {
            settings = await api('hello', { service_host: serviceHost });
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
