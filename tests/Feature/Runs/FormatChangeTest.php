<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\FormatChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class FormatChangeTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    public function test_only_the_files_the_change_touched_are_formatted()
    {
        [$run] = $this->implementingRun();
        config(['builder.construction.formatters' => [
            ['name' => 'PHP', 'command' => ['sh', '-c', 'for f; do echo "<?php // formatted" > "$f"; done', 'sh'], 'extensions' => ['php'], 'timeout' => 30],
            ['name' => 'Missing', 'command' => ['a-formatter-this-project-does-not-have'], 'extensions' => ['php'], 'timeout' => 30],
            ['name' => 'Styles', 'command' => ['true'], 'extensions' => ['css'], 'timeout' => 30],
        ]]);

        File::put($this->workspaceFile($run, 'app/Models/Team.php'), "<?php  class Team {}\n");
        File::put($this->workspaceFile($run, 'app/New.php'), "<?php  class NewThing {}\n");
        File::ensureDirectoryExists($this->workspaceFile($run, '.product-notes'));
        File::put($this->workspaceFile($run, '.product-notes/notes.php'), "<?php  // notes\n");

        $ran = app(FormatChange::class)->handle($run->workspace);

        $this->assertSame(['PHP'], $ran);
        $this->assertSame("<?php // formatted\n", File::get($this->workspaceFile($run, 'app/Models/Team.php')));
        $this->assertSame("<?php // formatted\n", File::get($this->workspaceFile($run, 'app/New.php')));
        $this->assertSame("<?php\n\nreturn [\n    'owner' => ['members:invite'],\n];\n", File::get($this->workspaceFile($run, 'config/teams.php')));
        $this->assertSame("<?php  // notes\n", File::get($this->workspaceFile($run, '.product-notes/notes.php')));
    }
}
