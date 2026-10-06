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
//   RUNNER_EGRESS_ALLOW  the hosts workspaces may reach, separated by
//                 commas, such as package registries; ".example.com" also
//                 lets its subdomains through. When set, commands go out
//                 through the runner's proxy, which lets only these hosts
//                 and the control plane through, and the firewall refuses
//                 everything else. Unset, workspaces may reach the internet.
//   RUNNER_EGRESS_PORT  the proxy's port on this machine (default 3128)
//   RUNNER_REQUIRE_FENCE  "on" to stop at once when the firewall cannot be
//                 set, so no workspace ever runs without it
//   RUNNER_PREVIEW_DOOR_PORT  a port for the preview door, for a control
//                 plane that shares no private network with this machine
//                 (such as one on Laravel Cloud). The door answers HTTPS
//                 there and leads only the control plane, which holds its
//                 key, to the previews; previews then listen only on this
//                 machine. RUNNER_SERVICE_HOST is then this machine's
//                 public address.

import { execFileSync, spawn } from 'node:child_process';
import {
    createHash,
    createPublicKey,
    randomBytes,
    timingSafeEqual,
} from 'node:crypto';
import {
    chmodSync,
    chownSync,
    closeSync,
    constants,
    fstatSync,
    mkdirSync,
    mkdtempSync,
    openSync,
    readFileSync,
    readdirSync,
    readSync,
    rmSync,
    statfsSync,
    statSync,
    writeFileSync,
} from 'node:fs';
import {
    createServer as createHttpServer,
    request as forward,
    get as httpGet,
} from 'node:http';
import { createServer as createHttpsServer, get as httpsGet } from 'node:https';
import { connect as connectTcp } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Readable } from 'node:stream';
import { dependencyCache } from './dependency-cache.mjs';

const url = (process.env.RUNNER_URL ?? '').replace(/\/$/, '');
const token = process.env.RUNNER_TOKEN ?? '';
const root = process.env.RUNNER_ROOT ?? '/workspaces';
const serviceHost = process.env.RUNNER_SERVICE_HOST || null;
const doorPort = Number(process.env.RUNNER_PREVIEW_DOOR_PORT) || null;
const egressAllow = (process.env.RUNNER_EGRESS_ALLOW ?? '')
    .split(',')
    .map((host) => host.trim().toLowerCase())
    .filter((host) => host !== '');
const egressPort = Number(process.env.RUNNER_EGRESS_PORT) || 3128;
const requireFence = process.env.RUNNER_REQUIRE_FENCE === 'on';

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
    // The image the box started from, set by whoever started it, so a run
    // can record what it built with.
    'BOX_IMAGE_DIGEST',
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

/**
 * The user the dependency cache is filled as: inside the fenced range, and
 * never given to a workspace.
 */
const FILL_ID = LAST_ID;

let settings = { poll_seconds: 5, output_limit: 65536, socket: null };

/** Commands in progress, by id, each with a way to stop it. */
const running = new Map();

/** Services started in each box: box -> list of process group ids. */
const services = new Map();

/** The ports of the services started: port -> box. */
const servicePorts = new Map();

let wake = null;

const dependencies = dependencyCache({
    settings: () => settings.dependency_cache,
    root,
    switching,
    fillId: FILL_ID,
    run,
});

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

/**
 * Get a large download from the control plane as a stream. Node's own HTTP
 * client, not fetch: a fetch body read slowly, as tar reads a big project,
 * can fail inside fetch's parser at the end of the stream and stop the
 * whole runner, with every command in every workspace.
 */
function download(address) {
    return new Promise((resolve, reject) => {
        const get = address.startsWith('https:') ? httpsGet : httpGet;

        get(
            address,
            { headers: { Authorization: `Bearer ${token}` } },
            resolve,
        ).on('error', reject);
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
        throw Object.assign(new Error(`${path} answered ${response.status}`), {
            status: response.status,
        });
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

    for (let id = FIRST_ID; id < FILL_ID; id++) {
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
 * Whether a workspace may reach this host and port through the proxy: the
 * hosts on the list on the web's ports, and the control plane on its own.
 */
function egressAllowed(host, port) {
    const name = host.toLowerCase().replace(/^\[|\]$/g, '');
    const controlPlane = new URL(url);

    if (name === controlPlane.hostname.toLowerCase()) {
        return (
            port ===
            (Number(controlPlane.port) ||
                (controlPlane.protocol === 'https:' ? 443 : 80))
        );
    }

    return (
        [80, 443].includes(port) &&
        egressAllow.some((allowed) =>
            allowed.startsWith('.')
                ? name === allowed.slice(1) || name.endsWith(allowed)
                : name === allowed,
        )
    );
}

/**
 * The proxy workspaces go out through when RUNNER_EGRESS_ALLOW is set. It
 * runs as the runner's user, which the firewall lets out, and passes on
 * only calls to the hosts on the list: plain HTTP, and HTTPS tunnels.
 */
function egressProxy() {
    const refuse = (host) =>
        `${host} is not on the list of places workspaces on this machine may reach.\n`;

    const server = createHttpServer((request, response) => {
        let target = null;

        try {
            target = new URL(request.url ?? '');
        } catch {
            // Not a proxy request.
        }

        if (
            target === null ||
            target.protocol !== 'http:' ||
            !egressAllowed(target.hostname, Number(target.port) || 80)
        ) {
            response.writeHead(403, { 'Content-Type': 'text/plain' });
            response.end(refuse(target?.hostname ?? 'This address'));

            return;
        }

        const upstream = forward(
            {
                host: target.hostname,
                port: target.port || 80,
                method: request.method,
                path: `${target.pathname}${target.search}`,
                headers: request.headers,
            },
            (answer) => {
                response.writeHead(answer.statusCode ?? 502, answer.headers);
                answer.pipe(response);
            },
        );

        upstream.on('error', () => {
            if (!response.headersSent) {
                response.writeHead(502);
            }

            response.end();
        });
        request.pipe(upstream);
    });

    server.on('connect', (request, socket, head) => {
        // A client that hangs up early is no fault of the runner's.
        socket.on('error', () => socket.destroy());

        const match = /^\[?([^\]]+?)\]?:(\d+)$/.exec(request.url ?? '');

        if (match === null || !egressAllowed(match[1], Number(match[2]))) {
            socket.end(
                `HTTP/1.1 403 Forbidden\r\n\r\n${refuse(match?.[1] ?? 'This address')}`,
            );

            return;
        }

        const upstream = connectTcp(Number(match[2]), match[1], () => {
            socket.write('HTTP/1.1 200 Connection Established\r\n\r\n');
            upstream.write(head);
            upstream.pipe(socket);
            socket.pipe(upstream);
        });

        upstream.on('error', () => socket.destroy());
        socket.on('close', () => upstream.destroy());
    });

    server.on('error', (error) => {
        console.error(`The egress proxy stopped: ${error.message}`);
        process.exit(1);
    });
    server.listen(egressPort, '127.0.0.1');
    console.log(
        `Workspaces reach only: ${egressAllow.join(', ')} and the control plane.`,
    );
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

            const reject = ['-j', 'REJECT', '--reject-with', fence.reject];

            for (const rule of [
                ['-o', 'lo', '-j', PORTS],
                ['-o', 'lo', '-j', 'RETURN'],
                // With a list, the proxy looks names up; workspaces need not.
                ...(egressAllow.length > 0
                    ? [reject]
                    : [
                          ['-p', 'udp', '--dport', '53', '-j', 'RETURN'],
                          ['-p', 'tcp', '--dport', '53', '-j', 'RETURN'],
                          ...fence.private.map((range) => [
                              '-d',
                              range,
                              ...reject,
                          ]),
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

    // With a list of places to reach, everything goes out through the
    // proxy; the app's own previews on this machine do not.
    if (egressAllow.length > 0) {
        const proxy = `http://127.0.0.1:${egressPort}`;

        for (const name of [
            'HTTP_PROXY',
            'HTTPS_PROXY',
            'http_proxy',
            'https_proxy',
        ]) {
            env[name] = proxy;
        }

        env.NO_PROXY = env.no_proxy = 'localhost,127.0.0.1,::1';
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
            // A command that exits before reading all its input must not
            // take the runner down with a broken pipe.
            process_.stdin.on('error', () => {});

            if (input instanceof Readable) {
                input.on('error', () => process_.stdin.destroy());
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

    for (const [port, owner] of servicePorts) {
        if (owner === name) {
            servicePorts.delete(port);
        }
    }
}

/**
 * Open the preview door: an HTTPS server on RUNNER_PREVIEW_DOOR_PORT whose
 * certificate is made now, so it needs no domain. The control plane trusts
 * it by the public key's pin, and the door lets in only requests with the
 * key, both sent in the hello. "/~<port>/path" leads to "/path" of the
 * service on that port, and only to ports of services the runner started.
 * Anything it cannot reach closes the connection, so the control plane
 * sees a stopped preview, as without the door.
 */
function openDoor() {
    if (doorPort === null) {
        return null;
    }

    const directory = mkdtempSync(join(tmpdir(), 'builder-door-'));
    let certificate;
    let privateKey;

    try {
        execFileSync(
            'openssl',
            [
                'req',
                '-x509',
                '-newkey',
                'ec',
                '-pkeyopt',
                'ec_paramgen_curve:prime256v1',
                '-nodes',
                '-days',
                '3650',
                '-subj',
                '/CN=builder-runner',
                '-keyout',
                join(directory, 'key.pem'),
                '-out',
                join(directory, 'cert.pem'),
            ],
            { stdio: 'ignore' },
        );
        certificate = readFileSync(join(directory, 'cert.pem'));
        privateKey = readFileSync(join(directory, 'key.pem'));
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }

    const key = randomBytes(32).toString('base64url');
    const expected = Buffer.from(key);
    const pin = createHash('sha256')
        .update(
            createPublicKey(certificate).export({
                type: 'spki',
                format: 'der',
            }),
        )
        .digest('base64');

    const server = createHttpsServer(
        { cert: certificate, key: privateKey },
        (request, response) => {
            const given = Buffer.from(
                String(request.headers['x-builder-door-key'] ?? ''),
            );

            if (
                given.length !== expected.length ||
                !timingSafeEqual(given, expected)
            ) {
                response.writeHead(401).end();

                return;
            }

            const match = /^\/~(\d+)(.*)$/s.exec(request.url ?? '');
            const port = Number(match?.[1]);

            if (match === null || !servicePorts.has(port)) {
                request.socket.destroy();

                return;
            }

            const headers = { ...request.headers };
            delete headers['x-builder-door-key'];

            const path = match[2] === '' ? '/' : match[2];
            const upstream = forward(
                {
                    host: '127.0.0.1',
                    port,
                    method: request.method,
                    path: path.startsWith('/') ? path : `/${path}`,
                    headers,
                },
                (answer) => {
                    response.writeHead(
                        answer.statusCode ?? 502,
                        answer.rawHeaders,
                    );
                    answer.pipe(response);
                },
            );

            upstream.on('error', () => request.socket.destroy());
            request.pipe(upstream);
        },
    );

    server.on('clientError', (error, socket) => socket.destroy());
    server.listen(doorPort);
    console.log(`Preview door is open on port ${doorPort}.`);

    return { port: doorPort, pin, key };
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
        dependencies.close(command.box);

        return ok();
    },

    async exec(command) {
        const { command: argv, env, cache } = command.payload;
        const as = identity(command.box);
        let warm = null;

        // An install the control plane marked: warmed from the app's
        // dependency cache when it can be, and run as usual either way.
        if (cache) {
            try {
                warm = dependencies.prepare(
                    command.box,
                    box(command.box),
                    as,
                    cache,
                );
            } catch (error) {
                console.error(`Dependency cache skipped: ${error.message}`);
            }
        }

        const result = await run(
            argv,
            {
                as,
                cwd: box(command.box),
                env: { ...warm?.env, ...env },
                workspace: command.box,
                timeoutSeconds: command.timeout_seconds,
            },
            command.id,
        );

        warm?.after(result);

        return result;
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
        let response;

        try {
            response = await download(
                `${url}/api/runner/commands/${command.id}/archive`,
            );
        } catch (error) {
            return failed(`Could not fetch the project: ${error.message}`);
        }

        if (response.statusCode !== 200) {
            response.resume();

            return failed(
                `Could not fetch the project (${response.statusCode}).`,
            );
        }

        const result = await run(['tar', '--no-same-owner', '-xzf', '-'], {
            as: identity(command.box),
            cwd: box(command.box),
            timeoutSeconds: command.timeout_seconds,
            input: response,
        });

        if (result.exit_code === 0 && !response.complete) {
            return failed('The project stopped coming before its end.');
        }

        return result;
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
        servicePorts.set(Number(port), command.box);

        return ok();
    },
};

const tooLarge = 'The result was too large to send back.';

async function report(id, result) {
    for (let attempt = 0; attempt < 10; attempt++) {
        try {
            await api(`commands/${id}/result`, result);

            return;
        } catch (error) {
            console.error(`Could not report ${id}: ${error.message}`);

            // Sending it again cannot make it smaller: say so at once,
            // or the control plane waits until it gives the command up.
            if (error.status === 413 && result.error_output !== tooLarge) {
                result = {
                    ...failed(tooLarge),
                    duration_ms: result.duration_ms,
                };

                continue;
            }

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

    if (fences.length === 0 && requireFence) {
        console.error(
            'RUNNER_REQUIRE_FENCE is on, but the firewall could not be set, so no workspace may run here.',
        );
        process.exit(1);
    }

    if (egressAllow.length > 0) {
        egressProxy();

        if (fences.length === 0) {
            console.error(
                'RUNNER_EGRESS_ALLOW is set, but the firewall is off: a workspace can still go around the proxy.',
            );
        }
    }

    const door = openDoor();

    for (;;) {
        try {
            settings = await api('hello', {
                service_host: serviceHost,
                preview_door: door,
            });
            break;
        } catch (error) {
            console.error(`Waiting for the control plane: ${error.message}`);
            await new Promise((resolve) => setTimeout(resolve, 3000));
        }
    }

    dependencies.start();
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

// One failure that nothing caught must not stop every command in every
// workspace; it is logged, and the command it belonged to fails or times
// out on its own.
process.on('uncaughtException', (error) => {
    console.error(`Unexpected error, kept running: ${error.stack ?? error}`);
});
process.on('unhandledRejection', (error) => {
    console.error(`Unexpected error, kept running: ${error?.stack ?? error}`);
});

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
