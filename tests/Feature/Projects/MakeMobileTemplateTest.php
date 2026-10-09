<?php

namespace Tests\Feature\Projects;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class MakeMobileTemplateTest extends TestCase
{
    protected string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/builder-mobile-template-'.uniqid();
        config([
            'builder.projects.mobile_template' => $this->path,
            'builder.projects.mobile_template_package' => 'nativephp/mobile-starter',
            'builder.projects.mobile_packages' => ['nativephp/mobile:^4.6', 'nativephp/mobile-ui:^0.8'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->path);

        parent::tearDown();
    }

    /**
     * Fake the commands, with the starter's files appearing as Composer
     * would put them.
     */
    protected function fakeStarter(string $settings = "APP_NAME=Laravel\n"): void
    {
        Process::fake(function (PendingProcess $process) use ($settings) {
            if (in_array('create-project', $process->command, true)) {
                File::ensureDirectoryExists($this->path.'/config');
                File::ensureDirectoryExists($this->path.'/vendor/nativephp');
                File::ensureDirectoryExists($this->path.'/bootstrap/cache');
                File::put($this->path.'/.env.example', $settings);
                File::put($this->path.'/config/services.php', "<?php\n\nreturn [\n\n    'resend' => [\n        'key' => env('RESEND_KEY'),\n    ],\n\n];\n");
                File::put($this->path.'/bootstrap/cache/packages.php', '<?php return [];');
            }

            return Process::result();
        });
    }

    public function test_it_moves_the_starter_to_the_set_versions_and_registers_its_screens()
    {
        $this->fakeStarter();

        $this->artisan('projects:mobile-template')->assertSuccessful();

        // Each step runs without this app's settings in its environment.
        $ran = fn (array $command) => Process::assertRan(fn (PendingProcess $process) => array_slice($process->command, 0, 3) === ['env', '-i', 'PATH='.getenv('PATH')]
            && array_slice($process->command, 4) === $command);
        $ran(['composer', 'create-project', 'nativephp/mobile-starter', $this->path, '--no-install', '--no-scripts', '--no-interaction', '--prefer-dist']);
        $ran(['composer', 'require', 'nativephp/mobile:^4.6', 'nativephp/mobile-ui:^0.8', '--no-install', '--no-scripts', '--no-interaction', '--update-with-all-dependencies']);
        $ran(['php', 'artisan', 'vendor:publish', '--tag=nativephp-plugins-provider', '--no-interaction']);
        $ran(['php', 'artisan', 'native:plugin:register', 'nativephp/mobile-ui', '--no-interaction']);
        $ran(['npm', 'install', '--package-lock-only', '--no-audit', '--no-fund', '--allow-remote=all']);

        // It knows where the owner's app is, holds no secret, and leaves
        // the packages for a workspace to install.
        $this->assertSame("APP_NAME=Laravel\n\n# The app this phone app talks to.\nBACKEND_URL=\n\n# The phone app's store id.\nNATIVEPHP_APP_ID=\n", File::get($this->path.'/.env.example'));
        $this->assertStringContainsString("'backend' => [\n        'url' => env('BACKEND_URL'),\n    ],\n\n];\n", File::get($this->path.'/config/services.php'));
        $this->assertStringContainsString("'resend' =>", File::get($this->path.'/config/services.php'));
        $this->assertDirectoryDoesNotExist($this->path.'/vendor');
        $this->assertFileDoesNotExist($this->path.'/bootstrap/cache/packages.php');
    }

    public function test_a_template_in_place_is_kept_unless_forced_and_its_settings_are_not_added_twice()
    {
        $this->fakeStarter();
        File::ensureDirectoryExists($this->path);
        File::put($this->path.'/marker', 'kept');

        $this->artisan('projects:mobile-template')->assertSuccessful();
        Process::assertNothingRan();
        $this->assertFileExists($this->path.'/marker');

        $this->artisan('projects:mobile-template --force')->assertSuccessful();
        $this->assertFileDoesNotExist($this->path.'/marker');
        $settings = File::get($this->path.'/.env.example');

        $this->artisan('projects:mobile-template --force')->assertSuccessful();
        $this->assertSame($settings, File::get($this->path.'/.env.example'));
        $this->assertSame(1, substr_count(File::get($this->path.'/config/services.php'), "'backend'"));
    }

    public function test_a_setting_the_starter_already_has_is_not_added_again()
    {
        $this->fakeStarter("APP_NAME=Laravel\nNATIVEPHP_APP_ID=\nNATIVEPHP_APP_VERSION=\"DEBUG\"\n");

        $this->artisan('projects:mobile-template')->assertSuccessful();

        // A second, empty copy would be the one the phone app reads.
        $this->assertSame("APP_NAME=Laravel\nNATIVEPHP_APP_ID=\nNATIVEPHP_APP_VERSION=\"DEBUG\"\n\n# The app this phone app talks to.\nBACKEND_URL=\n", File::get($this->path.'/.env.example'));
    }

    public function test_a_failed_step_leaves_no_half_made_template()
    {
        Process::fake(function (PendingProcess $process) {
            File::ensureDirectoryExists($this->path);

            return in_array('native:plugin:register', $process->command, true)
                ? Process::result(errorOutput: 'Plugin not found', exitCode: 1)
                : Process::result();
        });

        $this->artisan('projects:mobile-template')
            ->expectsOutputToContain('Plugin not found')
            ->assertFailed();

        $this->assertDirectoryDoesNotExist($this->path);
    }
}
