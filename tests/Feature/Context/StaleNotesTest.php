<?php

namespace Tests\Feature\Context;

use App\Actions\Context\CheckProjectNotes;
use App\Actions\Projects\CreateProject;
use App\Context\ProjectNotes;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\ProjectNoteRevision;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class StaleNotesTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected const TITLE = 'The notes on ":name" were written before later changes to its code. Read them below and correct anything that changed.';

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config(['builder.context.stale_notes.min_files' => 2]);

        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([
            '.builder/project.md' => "# Acme\n",
            '.builder/capabilities/plans.md' => "---\ncapability: plans\npaths: [app/Plans/*, tests/Feature/PlanTest.php]\n---\n\n# Plans\n",
            '.builder/capabilities/teams.md' => "---\ncapability: teams\npaths: [app/Models/Team.php]\n---\n\n# Teams\n",
            'app/Plans/Plan.php' => "<?php\n",
            'app/Plans/Price.php' => "<?php\n",
            'tests/Feature/PlanTest.php' => "<?php\n",
        ]));
        app(ProjectRepository::class)->import($this->project);
    }

    /**
     * Say when each part's notes were last written, relative to now: the
     * app's code was all committed in the last few seconds.
     *
     * @param  array<string, int>  $minutes  By part, minutes from now
     */
    private function notesWritten(array $minutes): void
    {
        foreach ($minutes as $part => $offset) {
            ProjectNote::query()->where('project_id', $this->project->id)->where('path', "capabilities/{$part}.md")
                ->update(['updated_at' => now()->addMinutes($offset)]);
        }
    }

    private function commit(array $files): void
    {
        $repository = app(ProjectRepository::class);
        $repository->commitFiles($this->project, $repository->head($this->project), $files, 'Change the code', null);
    }

    /**
     * @return array<string, list<string>>
     */
    private function stale(): array
    {
        $findings = app(CheckProjectNotes::class)->handle($this->project);
        $stale = [];

        foreach (['Plans', 'Teams'] as $name) {
            foreach ($findings as $finding) {
                if ($finding['title'] === __(self::TITLE, ['name' => $name])) {
                    $stale[$name] = $finding['details'];
                }
            }
        }

        return $stale;
    }

    private function confirm(string $part, ?string $revision = null): TestResponse
    {
        return $this->actingAs($this->owner)->put(route('projects.understanding.update', $this->project), [
            'part' => $part,
            'body' => '',
            'revision' => $revision ?? app(ProjectNotes::class)->version($this->project),
        ]);
    }

    private function plansNotes(): string
    {
        return app(ProjectNotes::class)->files($this->project)['capabilities/plans.md'];
    }

    public function test_a_part_whose_named_code_changed_after_its_notes_is_listed()
    {
        $this->notesWritten(['plans' => -60, 'teams' => -60]);
        $this->commit(['app/Plans/Plan.php' => "<?php // changed\n"]);

        // Its own test changing does not count; only the code it names.
        $this->assertSame(['Plans' => ['app/Plans/Plan.php', 'app/Plans/Price.php']], $this->stale());
    }

    public function test_notes_written_after_the_code_or_another_parts_change_are_not_listed()
    {
        // The plans notes were rewritten after every change to its code.
        $this->notesWritten(['plans' => 1, 'teams' => -60]);
        $this->assertSame([], $this->stale());

        // Only one teams file changed, under the minimum of two.
        $this->commit(['app/Models/Team.php' => "<?php // changed\n"]);
        $this->assertSame([], $this->stale());

        config(['builder.context.stale_notes.min_files' => 1]);
        $this->assertSame(['Teams' => ['app/Models/Team.php']], $this->stale());
    }

    public function test_removed_code_is_not_counted_and_a_long_list_is_cut()
    {
        $this->notesWritten(['plans' => -60, 'teams' => -60]);
        // A removed file is the "files that are not in the app" finding's
        // job, so only one plans file is left to count: under the minimum.
        $this->commit(['app/Plans/Price.php' => null]);

        $this->assertSame([], $this->stale());

        $this->commit(collect(range(1, 12))->mapWithKeys(fn (int $i) => ["app/Plans/Extra{$i}.php" => "<?php\n"])->all());
        $plans = $this->stale()['Plans'];

        $this->assertCount(11, $plans);
        $this->assertSame('and 3 more', $plans[10]);
    }

    public function test_the_owner_says_the_notes_are_still_right_and_the_part_is_no_longer_listed()
    {
        $this->notesWritten(['plans' => -60, 'teams' => -60]);
        $finding = collect(app(CheckProjectNotes::class)->handle($this->project))->firstWhere('title', __(self::TITLE, ['name' => 'Plans']));
        $this->assertSame(['part' => 'checked:plans'], $finding['confirm']);

        $this->confirm('checked:plans')->assertSessionHasNoErrors();

        $this->assertSame([], $this->stale());
        $this->assertMatchesRegularExpression("/^---\ncapability: plans\nchecked: '\\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d:\\d\\d'\npaths:/", $this->plansNotes());
        // The confirmation is in the notes' history, with who gave it.
        $this->assertSame($this->owner->id, ProjectNoteRevision::query()->where('path', 'capabilities/plans.md')->latest('id')->value('user_id'));
    }

    public function test_code_that_changes_after_the_owner_confirmed_brings_the_part_back()
    {
        $this->notesWritten(['plans' => -60, 'teams' => -60]);
        $this->confirm('checked:plans')->assertSessionHasNoErrors();

        // The owner confirmed half an hour ago; the code changed since.
        $this->notesWritten(['plans' => -30]);
        $this->commit(['app/Plans/Plan.php' => "<?php // changed again\n"]);

        $this->assertSame(['Plans' => ['app/Plans/Plan.php', 'app/Plans/Price.php']], $this->stale());
        $this->assertStringContainsString("checked: '", $this->plansNotes());
    }

    public function test_a_stale_or_unknown_confirmation_changes_nothing()
    {
        $this->notesWritten(['plans' => -60, 'teams' => -60]);
        $stale = app(ProjectNotes::class)->version($this->project);
        $this->confirm('checked:teams')->assertSessionHasNoErrors();
        $before = $this->plansNotes();

        $this->confirm('checked:plans', $stale)
            ->assertSessionHasErrors(['body' => 'The notes changed while you were editing. Try again on the updated version.']);
        $this->confirm('checked:invoices')->assertSessionHasErrors(['body' => 'These notes no longer exist. Reload the page.']);
        $this->confirm('checked:')->assertSessionHasErrors('part');

        $this->assertSame($before, $this->plansNotes());
        $this->assertArrayHasKey('Plans', $this->stale());
    }
}
