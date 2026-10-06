// The box runner's dependency cache: each app's installed PHP packages and
// both package managers' downloads, kept between workspaces so an install
// that the lock files have not changed is quick. The install itself always
// runs, so a workspace ends up with exactly what its lock files say; the
// cache only means less to fetch and unpack.
//
// The cache never takes what a workspace installed. Installs run after the
// change is applied, and composer's scripts run the app's own code, which
// could write into vendor/ and reach every later change of the app. After
// an install that missed, the runner fills the cache itself instead: in a
// folder of its own that holds only the app's manifest and lock files, as
// a user no workspace has, with the app's scripts off. Only the locked
// packages' own code runs there, as it does in every install anyway.
//
// Each app has its own folder, only root can read the cache, and a
// workspace gets copies (reflinks where the disk has them, so copying
// costs almost nothing): what a workspace changes stays in the workspace.
// An entry is made in a temp folder and renamed into place whole, so a
// fill cut short leaves nothing that is used.

import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import {
    chmodSync,
    closeSync,
    constants,
    fstatSync,
    lstatSync,
    mkdirSync,
    mkdtempSync,
    openSync,
    readFileSync,
    readdirSync,
    renameSync,
    rmSync,
    utimesSync,
    writeFileSync,
} from 'node:fs';
import { join } from 'node:path';

/** What each package manager reads and where it keeps its downloads. */
const KINDS = {
    composer: {
        files: ['composer.json', 'composer.lock'],
        // Installed packages are kept as well: with them in place, composer
        // only checks them against the lock.
        tree: 'vendor',
        cacheVariable: 'COMPOSER_CACHE_DIR',
        fill: [
            'composer',
            'install',
            '--no-interaction',
            '--prefer-dist',
            '--no-progress',
            '--no-scripts',
            '--no-autoloader',
        ],
    },
    npm: {
        files: ['package.json', 'package-lock.json'],
        // npm ci always starts from an empty node_modules, so only its
        // downloads help. npm checks each one against the lock's hash.
        tree: null,
        cacheVariable: 'npm_config_cache',
        fill: ['npm', 'ci', '--no-audit', '--no-fund', '--ignore-scripts'],
    },
};

/** The largest manifest or lock file read. */
const FILE_LIMIT = 16 * 1024 * 1024;

const FILL_SECONDS = 900;

/**
 * @param {object} options
 * @param {() => {directory?: string, limit_mb?: number}|undefined} options.settings  the control plane's settings, read when used
 * @param {string} options.root  where workspaces live
 * @param {boolean} options.switching  whether the runner runs commands as other users
 * @param {number} options.fillId  the user id fills run as, which no workspace has
 * @param {Function} options.run  the runner's run(command, options)
 */
export function dependencyCache({ settings, root, switching, fillId, run }) {
    let queue = Promise.resolve();
    const queued = new Set();
    const versions = {};

    function directory() {
        const name = settings()?.directory;

        // One hidden folder of the runner's root: never taken for a
        // workspace, and on the same disk, so entries can be renamed in.
        return typeof name === 'string' && /^\.[a-z0-9-]{1,64}$/.test(name)
            ? join(root, name)
            : null;
    }

    function limitBytes() {
        return Math.max(0, Number(settings()?.limit_mb) || 0) * 1048576;
    }

    function enabled() {
        return directory() !== null && limitBytes() > 0;
    }

    function version(kind) {
        if (versions[kind] === undefined) {
            try {
                versions[kind] =
                    kind === 'composer'
                        ? execFileSync('php', ['-r', 'echo PHP_VERSION;'], {
                              encoding: 'utf8',
                          })
                        : process.version;
            } catch {
                versions[kind] = 'none';
            }
        }

        return versions[kind];
    }

    /**
     * Read a plain file of the workspace, never through a link: the runner
     * reads it as root.
     */
    function readPlain(folder, name, uid) {
        let file;

        try {
            file = openSync(
                join(folder, name),
                constants.O_RDONLY |
                    constants.O_NOFOLLOW |
                    constants.O_NONBLOCK,
            );
        } catch {
            return null;
        }

        try {
            const stats = fstatSync(file);

            if (
                !stats.isFile() ||
                stats.nlink !== 1 ||
                stats.size > FILE_LIMIT ||
                (uid !== undefined && stats.uid !== uid)
            ) {
                return null;
            }

            return readFileSync(file);
        } finally {
            closeSync(file);
        }
    }

    function exists(path) {
        try {
            lstatSync(path);

            return true;
        } catch {
            return false;
        }
    }

    function isFolder(path) {
        try {
            return lstatSync(path).isDirectory();
        } catch {
            return false;
        }
    }

    /** Make a root-only folder. */
    function privateFolder(path) {
        mkdirSync(path, { recursive: true, mode: 0o700 });
        chmodSync(path, 0o700);
    }

    /** Copy a folder, sharing its blocks where the disk can. */
    function copy(from, to) {
        execFileSync('cp', ['-a', '--reflink=auto', from, to], {
            stdio: 'ignore',
        });
    }

    function handOver(path, id) {
        if (switching) {
            execFileSync('chown', ['-R', '-h', `${id}:${id}`, path], {
                stdio: 'ignore',
            });
        }
    }

    /**
     * Copy a cached folder to a place in a workspace. The copy is made and
     * handed over in the cache's own folder first, then renamed into
     * place: a rename never follows a link the workspace put there, and it
     * fails if anything is already at that place.
     */
    function place(from, to, id) {
        const stage = mkdtempSync(join(directory(), '.stage-'));

        try {
            copy(from, join(stage, 'copy'));
            handOver(join(stage, 'copy'), id);
            renameSync(join(stage, 'copy'), to);

            return true;
        } catch {
            return false;
        } finally {
            rmSync(stage, { recursive: true, force: true });
        }
    }

    function complete(entry, key) {
        try {
            return (
                isFolder(entry) &&
                isFolder(join(entry, 'tree')) &&
                lstatSync(join(entry, 'complete')).isFile() &&
                readFileSync(join(entry, 'complete'), 'utf8') === key
            );
        } catch {
            return false;
        }
    }

    function touch(path) {
        const now = new Date();

        try {
            utimesSync(path, now, now);
        } catch {
            // Gone already: it is only used to pick what to drop first.
        }
    }

    function stopFill() {
        if (!switching) {
            return;
        }

        for (let attempt = 0; attempt < 5; attempt++) {
            try {
                execFileSync('pkill', ['-KILL', '-u', String(fillId)], {
                    stdio: 'ignore',
                });
            } catch {
                return; // None left.
            }
        }
    }

    function size(path) {
        try {
            return Number(
                execFileSync('du', ['-sb', path], { encoding: 'utf8' }).split(
                    '\t',
                )[0],
            );
        } catch {
            return 0;
        }
    }

    /** Drop the least recently used entries until the cache fits. */
    function evict() {
        const cache = directory();
        let total = size(cache);
        const entries = [];

        for (const scope of readdirSync(cache)) {
            if (scope.startsWith('.')) {
                continue;
            }

            for (const name of readdirSync(join(cache, scope))) {
                const path = join(cache, scope, name);

                entries.push({
                    path,
                    scope,
                    name,
                    at: lstatSync(path).mtimeMs,
                });
            }
        }

        entries.sort((a, b) => a.at - b.at);

        for (const entry of entries) {
            if (total <= limitBytes()) {
                break;
            }

            total -= size(entry.path);
            rmSync(entry.path, { recursive: true, force: true });

            // Without its downloads, a lock counts as not filled again.
            if (entry.name === 'downloads-npm') {
                for (const name of readdirSync(join(cache, entry.scope))) {
                    if (name.startsWith('filled-')) {
                        rmSync(join(cache, entry.scope, name), { force: true });
                    }
                }
            }
        }
    }

    async function fill(scope, kind, key, files) {
        const cache = directory();
        const { cacheVariable, tree, fill: command } = KINDS[kind];
        const scopeFolder = join(cache, scope);
        const work = mkdtempSync(join(cache, '.fill-'));

        try {
            chmodSync(work, 0o700);

            const app = join(work, 'app');
            const home = join(work, 'home');
            const downloads = join(home, 'downloads');

            mkdirSync(app);
            mkdirSync(join(home, 'tmp'), { recursive: true });

            for (const [name, contents] of Object.entries(files)) {
                writeFileSync(join(app, name), contents);
            }

            if (isFolder(join(scopeFolder, `downloads-${kind}`))) {
                copy(join(scopeFolder, `downloads-${kind}`), downloads);
            }

            // The fill's user reaches its folder through the cache's top
            // folder, which lets anyone pass but no one list it.
            handOver(work, fillId);

            const result = await run(command, {
                as: switching
                    ? { ids: { uid: fillId, gid: fillId }, home }
                    : { ids: {}, home },
                cwd: app,
                env: { [cacheVariable]: downloads },
                timeoutSeconds: FILL_SECONDS,
            });

            stopFill();

            if (result.exit_code !== 0) {
                console.error(
                    `Could not fill the ${kind} cache of ${scope}: ${result.error_output.slice(-500)}`,
                );

                return;
            }

            handOver(work, 0);
            privateFolder(scopeFolder);

            if (tree !== null) {
                const entry = join(work, 'entry');

                const target = join(scopeFolder, `${tree}-${key}`);

                mkdirSync(entry);
                renameSync(join(app, tree), join(entry, 'tree'));
                writeFileSync(join(entry, 'complete'), key);

                // What a cut-short fill left is replaced, never used.
                if (!complete(target, key)) {
                    rmSync(target, { recursive: true, force: true });
                }

                try {
                    renameSync(entry, target);
                } catch {
                    // Another fill made it first.
                }
            }

            if (isFolder(downloads)) {
                const current = join(scopeFolder, `downloads-${kind}`);

                if (exists(current)) {
                    renameSync(current, join(work, 'old'));
                }

                renameSync(downloads, current);
            }

            if (tree === null) {
                writeFileSync(join(scopeFolder, `filled-${key}`), '');
            }

            evict();
        } finally {
            rmSync(work, { recursive: true, force: true });
        }
    }

    return {
        /**
         * Clear what a runner stopped mid-fill left behind.
         */
        start() {
            const cache = directory();

            if (cache === null) {
                return;
            }

            stopFill();
            mkdirSync(cache, { recursive: true });
            chmodSync(cache, 0o711);

            for (const name of readdirSync(cache)) {
                if (name.startsWith('.fill-') || name.startsWith('.stage-')) {
                    rmSync(join(cache, name), { recursive: true, force: true });
                }
            }
        },

        /**
         * Warm a workspace for an install: put back its app's installed
         * packages when its lock files match an entry, and give the
         * package manager a copy of the app's downloads. Returns the
         * variables the install needs and what to do once it has run.
         *
         * @param {string} name  the workspace
         * @param {string} folder  the workspace's folder
         * @param {{ids: {uid?: number}, home: string}} as  who runs it
         * @param {{scope: string, kind: string}} wanted  what the control plane asked for
         */
        prepare(name, folder, as, wanted) {
            const scope = wanted?.scope;
            const kind = wanted?.kind;

            if (
                !enabled() ||
                typeof scope !== 'string' ||
                !/^[a-z0-9][a-z0-9-]{0,63}$/.test(scope) ||
                !Object.hasOwn(KINDS, kind)
            ) {
                return null;
            }

            const { files: names, tree, cacheVariable } = KINDS[kind];
            const files = {};
            const hash = createHash('sha256').update(
                JSON.stringify([kind, version(kind), KINDS[kind].fill]),
            );

            for (const file of names) {
                const contents = readPlain(folder, file, as.ids.uid);

                // Without its lock file, an install is not the same twice.
                if (contents === null) {
                    return null;
                }

                files[file] = contents;
                hash.update(`\0${file}\0${contents.length}\0`).update(contents);
            }

            const key = hash.digest('hex');
            const scopeFolder = join(directory(), scope);
            const env = {};
            let restored = false;

            if (tree !== null) {
                const entry = join(scopeFolder, `${tree}-${key}`);

                if (complete(entry, key) && !exists(join(folder, tree))) {
                    restored = place(
                        join(entry, 'tree'),
                        join(folder, tree),
                        as.ids.uid,
                    );
                    touch(entry);
                }
            }

            // With its packages in place, composer fetches nothing.
            if (!restored) {
                const downloads = join(scopeFolder, `downloads-${kind}`);
                const copied = join(
                    switching ? as.home : join(directory(), '.homes', name),
                    `.dependency-cache-${kind}`,
                );

                if (isFolder(downloads)) {
                    if (!switching) {
                        mkdirSync(join(directory(), '.homes', name), {
                            recursive: true,
                        });
                    }

                    if (place(downloads, copied, as.ids.uid)) {
                        env[cacheVariable] = copied;
                        touch(downloads);
                    }
                }
            }

            const filled =
                tree !== null
                    ? complete(join(scopeFolder, `${tree}-${key}`), key)
                    : exists(join(scopeFolder, `filled-${key}`));

            return {
                env,
                restored,
                after(result) {
                    const id = `${scope}/${kind}/${key}`;

                    if (filled || result.exit_code !== 0 || queued.has(id)) {
                        return;
                    }

                    // One fill at a time, all as the same user, behind
                    // the workspaces' commands.
                    queued.add(id);
                    queue = queue
                        .then(() => fill(scope, kind, key, files))
                        .catch((error) =>
                            console.error(
                                `Could not fill the ${kind} cache of ${scope}: ${error.message}`,
                            ),
                        )
                        .finally(() => queued.delete(id));
                },
            };
        },

        /** Wait until every fill asked for so far has finished. */
        settled() {
            return queue;
        },

        /** Drop what a closed workspace had of the cache. */
        close(name) {
            const cache = directory();

            if (cache !== null && !switching) {
                rmSync(join(cache, '.homes', name), {
                    recursive: true,
                    force: true,
                });
            }
        },
    };
}
