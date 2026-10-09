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
    // The app's root is the working directory, or the one above it: the
    // server of an app on show runs inside public/, as `artisan serve` does.
    $root = (string) getcwd();

    if (! is_file($root.'/bootstrap/cache/packages.php') && is_file(dirname($root).'/bootstrap/cache/packages.php')) {
        $root = dirname($root);
    }

    $discovered = $root.'/bootstrap/cache/packages.php';
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
