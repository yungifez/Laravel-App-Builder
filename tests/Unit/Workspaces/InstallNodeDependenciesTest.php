<?php

namespace Tests\Unit\Workspaces;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class InstallNodeDependenciesTest extends TestCase
{
    public function test_an_app_with_a_lock_file_installs_exactly_from_it()
    {
        $this->assertSame('ci --no-audit --no-fund', $this->install(withLockFile: true));
    }

    public function test_an_app_without_a_lock_file_installs_without_writing_one()
    {
        $this->assertSame('install --no-audit --no-fund --no-package-lock', $this->install(withLockFile: false));
    }

    /**
     * Run the setup step of checks and previews in an app folder, with an
     * npm that only says what it was asked to do.
     */
    protected function install(bool $withLockFile): string
    {
        $app = sys_get_temp_dir().'/install-node-'.uniqid();
        File::ensureDirectoryExists("{$app}/bin");
        File::put("{$app}/bin/npm", "#!/bin/sh\necho \"\$*\"\n");
        chmod("{$app}/bin/npm", 0755);
        File::put("{$app}/package.json", '{}');

        if ($withLockFile) {
            File::put("{$app}/package-lock.json", '{}');
        }

        $outputs = [];

        foreach (['verification', 'preview'] as $section) {
            $step = collect(config("builder.{$section}.setup"))->firstWhere('name', 'Install Node dependencies');
            $outputs[] = trim(Process::path($app)->env(['PATH' => "{$app}/bin:".getenv('PATH')])->run($step['command'])->throw()->output());
        }

        File::deleteDirectory($app);

        $this->assertSame($outputs[0], $outputs[1]);

        return $outputs[0];
    }
}
