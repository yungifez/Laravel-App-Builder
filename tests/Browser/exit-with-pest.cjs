// Ends Pest's Playwright server when the test run that started it ends.
//
// The browser plugin starts the server as `sh -c "…playwright run-server…"`
// and stops it by signalling that shell only, so the server itself lived on
// after every run: one orphan per run, holding memory for hours. It also
// lived on when the run was killed, because then nothing stops it at all.
// So the server watches its own parents: it ends when the shell is gone
// (the run finished) or when the Pest process above it is gone (the run was
// killed). Loaded through NODE_OPTIONS by tests/Pest.php; any other Node
// process the tests start ignores it.
const { readFileSync } = require('node:fs');

if (process.argv.includes('run-server')) {
    const shell = process.ppid;
    let pest = null;

    try {
        // The fourth field of /proc/<pid>/stat is that process's parent.
        pest = Number(
            readFileSync(`/proc/${shell}/stat`, 'utf8')
                .split(') ')[1]
                .split(' ')[1],
        );
    } catch {
        // Not on Linux: the shell check alone still covers a finished run.
    }

    const alive = (pid) => {
        try {
            process.kill(pid, 0);

            return true;
        } catch (error) {
            return error.code === 'EPERM';
        }
    };

    setInterval(() => {
        if (
            process.ppid !== shell ||
            (pest !== null && pest > 1 && !alive(pest))
        ) {
            process.exit(0);
        }
    }, 500).unref();
}
