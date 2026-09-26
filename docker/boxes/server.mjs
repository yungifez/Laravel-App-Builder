// A stand-in for a hosting provider's box API, for local development. It
// holds the Docker socket and offers only three things: create a box, destroy
// a box and list boxes. Every box is a container from one image (BOX_IMAGE)
// on one network (BOX_NETWORK), labelled as a box, so the control plane can
// never use this to start, stop or inspect anything else on the machine.
//
// POST   /boxes          {name, cpus, memory_mb, pids, env}  -> 201 {name}
// DELETE /boxes/{name}                                       -> 204
// GET    /boxes                                              -> 200 {boxes: [name]}
//
// Every request needs "Authorization: Bearer $BOXES_TOKEN".

import { timingSafeEqual } from 'node:crypto';
import http from 'node:http';

const token = process.env.BOXES_TOKEN ?? '';
const image = process.env.BOX_IMAGE ?? 'builder-box';
const network = process.env.BOX_NETWORK ?? '';
const port = Number(process.env.BOXES_PORT ?? 8090);
const LABEL = 'builder.box';
const NAME = /^[a-z0-9-]{1,60}$/;
const VARIABLE = /^[A-Z_][A-Z0-9_]*$/;

if (token === '' || network === '') {
    console.error('BOXES_TOKEN and BOX_NETWORK are required.');
    process.exit(1);
}

/** Call the Docker Engine API over its socket. */
function docker(method, path, body) {
    return new Promise((resolve, reject) => {
        const request = http.request(
            {
                socketPath: '/var/run/docker.sock',
                path,
                method,
                headers: { 'Content-Type': 'application/json' },
            },
            (response) => {
                let data = '';

                response.on('data', (chunk) => (data += chunk));
                response.on('end', () => {
                    let json = null;

                    try {
                        json = data === '' ? null : JSON.parse(data);
                    } catch {
                        json = { message: data };
                    }

                    resolve({ status: response.statusCode ?? 500, json });
                });
            },
        );

        request.on('error', reject);
        request.end(body === undefined ? undefined : JSON.stringify(body));
    });
}

function authorized(request) {
    const given = Buffer.from(
        (request.headers.authorization ?? '').replace(/^Bearer /, ''),
    );
    const wanted = Buffer.from(token);

    return given.length === wanted.length && timingSafeEqual(given, wanted);
}

function readJson(request) {
    return new Promise((resolve) => {
        let data = '';

        request.on('data', (chunk) => (data += chunk));
        request.on('end', () => {
            try {
                resolve(JSON.parse(data));
            } catch {
                resolve(null);
            }
        });
    });
}

function send(response, status, body = null) {
    response.writeHead(status, { 'Content-Type': 'application/json' });
    response.end(body === null ? '' : JSON.stringify(body));
}

async function create(request, response) {
    const box = await readJson(request);

    if (!box || !NAME.test(box.name ?? '')) {
        return send(response, 422, {
            message:
                'A box needs a name of lowercase letters, digits and dashes.',
        });
    }

    const env = Object.entries(box.env ?? {});

    if (
        env.some(
            ([name, value]) =>
                !VARIABLE.test(name) || typeof value !== 'string',
        )
    ) {
        return send(response, 422, {
            message: 'Environment variables must be NAME: "value".',
        });
    }

    const container = `box-${box.name}`;
    const memory = Math.max(64, Number(box.memory_mb) || 2048) * 1024 * 1024;
    const created = await docker(
        'POST',
        `/containers/create?name=${container}`,
        {
            Image: image,
            Hostname: container,
            Env: env.map(([name, value]) => `${name}=${value}`),
            Labels: { [LABEL]: box.name },
            HostConfig: {
                NetworkMode: network,
                NanoCpus: Math.round((Number(box.cpus) || 2) * 1e9),
                Memory: memory,
                MemorySwap: memory,
                PidsLimit: Number(box.pids) || 512,
                RestartPolicy: { Name: 'no' },
            },
        },
    );

    if (created.status !== 201) {
        return send(response, created.status === 409 ? 409 : 502, {
            message: created.json?.message ?? 'Docker refused the box.',
        });
    }

    const started = await docker('POST', `/containers/${container}/start`);

    if (started.status !== 204) {
        await docker('DELETE', `/containers/${container}?force=true&v=true`);

        return send(response, 502, {
            message: started.json?.message ?? 'Docker could not start the box.',
        });
    }

    return send(response, 201, { name: box.name });
}

async function destroy(name, response) {
    if (!NAME.test(name)) {
        return send(response, 404);
    }

    const container = `box-${name}`;
    const found = await docker('GET', `/containers/${container}/json`);

    // Only containers this service made are ever removed.
    if (found.status === 404 || found.json?.Config?.Labels?.[LABEL] !== name) {
        return send(response, 204);
    }

    const removed = await docker(
        'DELETE',
        `/containers/${container}?force=true&v=true`,
    );

    return send(
        response,
        removed.status === 204 || removed.status === 404 ? 204 : 502,
    );
}

async function list(response) {
    const filters = encodeURIComponent(JSON.stringify({ label: [LABEL] }));
    const found = await docker(
        'GET',
        `/containers/json?all=true&filters=${filters}`,
    );

    return send(response, 200, {
        boxes: (found.json ?? []).map((container) => container.Labels[LABEL]),
    });
}

http.createServer(async (request, response) => {
    try {
        if (!authorized(request)) {
            return send(response, 401);
        }

        const path = new URL(request.url ?? '/', 'http://boxes').pathname;
        const one = path.match(/^\/boxes\/([^/]+)$/);

        if (request.method === 'POST' && path === '/boxes') {
            return await create(request, response);
        }

        if (request.method === 'DELETE' && one) {
            return await destroy(decodeURIComponent(one[1]), response);
        }

        if (request.method === 'GET' && path === '/boxes') {
            return await list(response);
        }

        return send(response, 404);
    } catch (error) {
        console.error(error);

        return send(response, 500, { message: 'The box service failed.' });
    }
}).listen(port, () => console.log(`Box service is listening on ${port}.`));
