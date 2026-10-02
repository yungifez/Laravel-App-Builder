<?php

namespace Tests\Feature\Previews;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class StartDatabaseTest extends TestCase
{
    protected string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/database-'.getmypid());
        File::deleteDirectory($this->folder);
        File::ensureDirectoryExists("{$this->folder}/tmp");
        File::ensureDirectoryExists("{$this->folder}/bin");
        File::ensureDirectoryExists("{$this->folder}/.git");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    /**
     * Run the setup step that starts the database, with stand-ins for the
     * server's programs: "mysqladmin" answers with the given exit code and
     * "mysql" writes down what it was asked to run.
     */
    protected function start(int $ping): ProcessResult
    {
        File::put("{$this->folder}/bin/mysqladmin", "#!/bin/sh\nexit {$ping}\n");
        File::put("{$this->folder}/bin/mysql", "#!/bin/sh\nprintf '%s\\n' \"\$4\" >> \"{$this->folder}/asked.log\"\n");
        File::chmod("{$this->folder}/bin/mysqladmin", 0755);
        File::chmod("{$this->folder}/bin/mysql", 0755);

        $step = collect(config('builder.preview.setup'))->firstWhere('name', 'Start the database');

        return Process::path($this->folder)
            ->env(['PATH' => "{$this->folder}/bin:/usr/bin:/bin", 'TMPDIR' => "{$this->folder}/tmp"])
            ->run($step['command']);
    }

    public function test_a_mysql_app_is_pointed_at_its_own_server_with_the_databases_it_and_its_tests_name()
    {
        File::put("{$this->folder}/.env", "APP_NAME=School\nDB_CONNECTION=mysql\nDB_HOST=mysql\nDB_DATABASE=school\n");
        File::put("{$this->folder}/phpunit.xml", '<phpunit><php><env name="DB_DATABASE" value="testing" force="true"/><env name="DB_DATABASE" value="x`; drop"/></php></phpunit>');

        $started = $this->start(ping: 0);

        $this->assertSame(0, $started->exitCode(), $started->errorOutput());
        $this->assertSame("CREATE DATABASE IF NOT EXISTS `school`\nCREATE DATABASE IF NOT EXISTS `testing`\n", File::get("{$this->folder}/asked.log"));

        $this->assertMatchesRegularExpression('#^APP_NAME=School\nDB_CONNECTION=mysql\nDB_HOST=mysql\nDB_DATABASE=school\nDB_SOCKET=.+/tmp/database-\d+/mysqld\.sock\n$#', File::get("{$this->folder}/.env"));
        // Tests that read a committed .env.testing find the server too, and
        // no file of the app holds the address.
        $this->assertMatchesRegularExpression('#^DB_SOCKET=.+/mysqld\.sock\n$#', File::get("{$this->folder}/.git/environment"));
        $this->assertSame(0600, fileperms("{$this->folder}/.git/environment") & 0777);

        $this->assertSame(0, $this->start(ping: 0)->exitCode(), 'Starting again changes nothing.');
        $this->assertSame(1, substr_count(File::get("{$this->folder}/.env"), 'DB_SOCKET='));
    }

    public function test_an_app_on_sqlite_needs_no_server()
    {
        File::put("{$this->folder}/.env", "DB_CONNECTION=sqlite\n");

        $this->assertSame(0, $this->start(ping: 1)->exitCode());
        $this->assertSame("DB_CONNECTION=sqlite\n", File::get("{$this->folder}/.env"));
        $this->assertFileDoesNotExist("{$this->folder}/.git/environment");
    }

    public function test_a_server_the_workspace_cannot_start_is_our_fault()
    {
        File::put("{$this->folder}/.env", "DB_CONNECTION=mysql\n");

        $started = $this->start(ping: 1);

        $this->assertSame(1, $started->exitCode());
        $this->assertStringContainsString("The workspace could not start the app's MySQL database. This is our fault.", $started->errorOutput());
    }
}
