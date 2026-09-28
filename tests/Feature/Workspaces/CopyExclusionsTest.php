<?php

namespace Tests\Feature\Workspaces;

use App\Workspaces\Drivers\CopyExclusions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class CopyExclusionsTest extends TestCase
{
    public function test_an_older_change_that_edited_the_apps_notes_still_applies_to_a_workspace()
    {
        $source = storage_path('framework/testing/exclusions-'.getmypid());
        $git = fn (array $command) => Process::path($source)->run(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', ...$command])->throw();

        File::ensureDirectoryExists("{$source}/.builder/capabilities");
        File::ensureDirectoryExists("{$source}/app");
        File::put("{$source}/.builder/capabilities/teams.md", "Teams\n");
        File::put("{$source}/.builder/project.md", "Acme\n");
        File::put("{$source}/app/Team.php", "<?php\n");

        try {
            $git(['init', '-q']);
            $git(['add', '.']);
            $git(['commit', '-q', '-m', 'Start']);

            File::put("{$source}/.builder/capabilities/teams.md", "Teams have a description\n");
            File::put("{$source}/.builder/project.md", "Acme, for teams\n");
            File::put("{$source}/app/Team.php", "<?php\n\n// A team has a description.\n");
            File::put("{$source}/change.patch", $git(['diff'])->output());
            $git(['checkout', '-q', '--', '.']);

            // A workspace gets no .builder.
            File::deleteDirectory("{$source}/.builder");

            $applied = Process::path($source)->run(['git', 'apply', '--whitespace=nowarn', ...CopyExclusions::applyFlags(), 'change.patch']);

            $this->assertSame(0, $applied->exitCode(), $applied->errorOutput());
            $this->assertSame("<?php\n\n// A team has a description.\n", File::get("{$source}/app/Team.php"));
            $this->assertDirectoryDoesNotExist("{$source}/.builder");
        } finally {
            File::deleteDirectory($source);
        }
    }
}
