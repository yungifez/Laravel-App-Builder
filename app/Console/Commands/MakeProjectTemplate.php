<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

#[Signature('projects:template {--force : Replace the template that is already there}')]
#[Description('Put the app that new projects start from in place')]
class MakeProjectTemplate extends Command
{
    /**
     * Execute the console command.
     *
     * The template is the configured Composer package with its lock files,
     * but without its dependencies: a preview installs them, as for any
     * other app.
     */
    public function handle(): int
    {
        $path = (string) config('builder.projects.template');
        $package = (string) config('builder.projects.template_package');

        if ($path === '') {
            $this->components->error('Set BUILDER_TEMPLATE_PATH first.');

            return self::FAILURE;
        }

        if (File::isDirectory($path)) {
            if (! $this->option('force')) {
                $this->components->info("The template is already at [{$path}]. Use --force to replace it.");

                return self::SUCCESS;
            }

            File::deleteDirectory($path);
        }

        // Lock files pin what every new app installs, and a preview's setup
        // needs them (`npm ci`). Nothing is installed here.
        $steps = [
            [null, ['composer', 'create-project', $package, $path, '--no-install', '--no-scripts', '--no-interaction', '--prefer-dist']],
            [$path, ['composer', 'update', '--no-install', '--no-scripts', '--no-interaction', '--no-progress']],
            [$path, ['npm', 'install', '--package-lock-only', '--no-audit', '--no-fund']],
        ];

        foreach ($steps as [$directory, $command]) {
            $result = Process::path($directory ?? base_path())->timeout(900)->run($command);

            if ($result->failed()) {
                File::deleteDirectory($path);
                $this->components->error("Could not get [{$package}]: ".trim($result->errorOutput() ?: $result->output()));

                return self::FAILURE;
            }
        }

        $this->components->info("New apps now start from [{$package}] at [{$path}].");

        return self::SUCCESS;
    }
}
