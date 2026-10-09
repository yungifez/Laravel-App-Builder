<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

#[Signature('projects:mobile-template {--force : Replace the template that is already there}')]
#[Description('Put the phone app that new phone apps start from in place')]
class MakeMobileTemplate extends Command
{
    /**
     * The settings every phone app reads, and what each is for. The
     * starter already has some of them; only the missing ones are added,
     * because a later copy of a setting would win over the one filled in.
     *
     * @var array<string, string>
     */
    public const SETTINGS = [
        'BACKEND_URL' => 'The app this phone app talks to.',
        'NATIVEPHP_APP_ID' => "The phone app's store id.",
    ];

    /**
     * Execute the console command.
     *
     * The NativePHP starter still starts on an older NativePHP, so the
     * template is moved to the configured versions and its screen
     * components are registered, which needs the packages installed for a
     * moment. Like the app template, it keeps its lock files and leaves its
     * dependencies out: a workspace installs them.
     */
    public function handle(): int
    {
        $path = (string) config('builder.projects.mobile_template');
        $package = (string) config('builder.projects.mobile_template_package');

        if ($path === '') {
            $this->components->error('Set BUILDER_MOBILE_TEMPLATE_PATH first.');

            return self::FAILURE;
        }

        if (File::isDirectory($path)) {
            if (! $this->option('force')) {
                $this->components->info("The phone app template is already at [{$path}]. Use --force to replace it.");

                return self::SUCCESS;
            }

            File::deleteDirectory($path);
        }

        $steps = [
            [null, ['composer', 'create-project', $package, $path, '--no-install', '--no-scripts', '--no-interaction', '--prefer-dist']],
            [$path, ['composer', 'require', ...config('builder.projects.mobile_packages'), '--no-install', '--no-scripts', '--no-interaction', '--update-with-all-dependencies']],
            [$path, ['composer', 'install', '--no-scripts', '--no-interaction', '--no-progress']],
            [$path, ['php', 'artisan', 'vendor:publish', '--tag=nativephp-plugins-provider', '--no-interaction']],
            [$path, ['php', 'artisan', 'native:plugin:register', 'nativephp/mobile-ui', '--no-interaction']],
            // The starter has no lock file, and npm 12 will not resolve one of
            // Tailwind's packages from scratch on its own. Once the lock file
            // is made, a workspace installs from it with npm's defaults.
            [$path, ['npm', 'install', '--package-lock-only', '--no-audit', '--no-fund', '--allow-remote=all']],
        ];

        foreach ($steps as [$directory, $command]) {
            // The template is another app: it must not read this one's
            // database or keys from the environment it inherits.
            $result = Process::path($directory ?? base_path())->timeout(900)->run(['env', '-i', 'PATH='.getenv('PATH'), 'HOME='.getenv('HOME'), ...$command]);

            if ($result->failed()) {
                File::deleteDirectory($path);
                $this->components->error("Could not get [{$package}]: ".trim($result->errorOutput() ?: $result->output()));

                return self::FAILURE;
            }
        }

        $this->wire($path);

        File::deleteDirectory("{$path}/vendor");
        File::delete(File::glob("{$path}/bootstrap/cache/*.php"));

        $this->components->info("New phone apps now start from [{$package}] at [{$path}].");

        return self::SUCCESS;
    }

    /**
     * Give the template the settings that point it at the owner's app.
     */
    protected function wire(string $path): void
    {
        $example = "{$path}/.env.example";

        foreach (File::exists($example) ? self::SETTINGS : [] as $key => $meaning) {
            if (preg_match("/^{$key}=/m", File::get($example)) !== 1) {
                File::append($example, "\n# {$meaning}\n{$key}=\n");
            }
        }

        $services = "{$path}/config/services.php";

        $contents = File::exists($services) ? rtrim(File::get($services)) : '';

        if (str_ends_with($contents, '];') && ! str_contains($contents, "'backend'")) {
            $backend = "\n    // The app this phone app talks to. Sign-in gives each phone its own\n    // token, so no shared secret ships inside the phone app.\n    'backend' => [\n        'url' => env('BACKEND_URL'),\n    ],\n\n];\n";

            File::put($services, substr($contents, 0, -2).$backend);
        }
    }
}
