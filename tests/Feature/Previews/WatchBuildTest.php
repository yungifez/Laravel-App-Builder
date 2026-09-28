<?php

namespace Tests\Feature\Previews;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class WatchBuildTest extends TestCase
{
    /**
     * A stand-in for a build in watch mode: it builds once, then again
     * shortly after each change to "src".
     */
    private const BUILD = <<<'JS'
        const fs = require('node:fs');
        let timer;
        const build = () => {
            console.log('build started..');
            setTimeout(() => console.log('built in 5ms.'), 50);
        };
        build();
        fs.watch('src', () => {
            clearTimeout(timer);
            timer = setTimeout(build, 10);
        });
        JS;

    public function test_staged_files_are_moved_in_and_waited_for_until_the_build_ends()
    {
        $app = storage_path('framework/testing/watch-'.getmypid());
        File::ensureDirectoryExists("{$app}/src");
        File::put("{$app}/build.cjs", self::BUILD);
        File::put("{$app}/src/old.txt", 'old');

        $watcher = Process::path($app)->start($this->tool('watch', 'build started', 'built in', config('builder.preview.locator.node'), 'build.cjs'));

        try {
            $this->assertSame(0, Process::path($app)->run($this->tool('wait', '100', '10'))->exitCode(), 'The first build ends.');

            File::ensureDirectoryExists("{$app}/state/stage/src");
            File::put("{$app}/state/stage/src/page.txt", 'new');

            $placed = Process::path($app)->run($this->tool('place', '100', '10', 'src/old.txt'));

            $this->assertSame(0, $placed->exitCode());
            $this->assertSame('new', File::get("{$app}/src/page.txt"));
            $this->assertFileDoesNotExist("{$app}/src/old.txt");
            $this->assertSame([], File::allFiles("{$app}/state/stage"));
            $this->assertMatchesRegularExpression('/^\d+ [2-9]\d* built$/', File::get("{$app}/state/state"));

            $watcher->stop();

            File::put("{$app}/state/stage/src/late.txt", 'late');

            $this->assertSame(3, Process::path($app)->run($this->tool('place', '100', '10'))->exitCode(), 'No watcher runs any more.');
            $this->assertFileDoesNotExist("{$app}/src/late.txt");
            $this->assertDirectoryDoesNotExist("{$app}/state/stage");
        } finally {
            $watcher->stop();
            File::deleteDirectory($app);
        }
    }

    /**
     * @return list<string>
     */
    private function tool(string $mode, string ...$arguments): array
    {
        return [config('builder.preview.locator.node'), config('builder.preview.watch.path'), $mode, 'state', ...$arguments];
    }
}
