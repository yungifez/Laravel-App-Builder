<?php

// Loaded by PHP before every script (auto_prepend_file). It does nothing
// unless TRACE_RECORDER_DIR names a folder to record into. Then it makes the
// recorder's classes loadable and adds its service provider to the list of
// discovered packages Laravel reads, in a copy outside the app. The app's
// own files are never changed.

$directory = getenv('TRACE_RECORDER_DIR');

if (! is_string($directory) || $directory === '' || ! is_dir($directory)) {
    return;
}

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'TraceRecorder\\')) {
        require __DIR__.'/src/'.substr($class, 14).'.php';
    }
});

(function (string $directory): void {
    $discovered = getcwd().'/bootstrap/cache/packages.php';
    $manifest = rtrim($directory, '/').'/packages.php';

    if (! is_file($discovered)) {
        return;
    }

    if (! is_file($manifest) || filemtime($manifest) < filemtime($discovered)) {
        $packages = require $discovered;

        if (! is_array($packages)) {
            return;
        }

        $packages['trace-recorder'] = ['providers' => ['TraceRecorder\\Provider']];
        $temporary = $manifest.'.'.getmypid();
        file_put_contents($temporary, '<?php return '.var_export($packages, true).';');
        rename($temporary, $manifest);
    }

    // Laravel keeps a list of every provider next to the package list. It
    // goes to the copy too, so the app's own cache files stay as they are.
    foreach (['APP_PACKAGES_CACHE' => $manifest, 'APP_SERVICES_CACHE' => rtrim($directory, '/').'/services.php'] as $name => $path) {
        putenv("{$name}={$path}");
        $_ENV[$name] = $_SERVER[$name] = $path;
    }
})($directory);
