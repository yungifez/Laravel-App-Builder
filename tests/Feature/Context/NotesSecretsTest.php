<?php

namespace Tests\Feature\Context;

use App\Context\ProjectNotes;
use App\Models\Project;
use App\Support\Secrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class NotesSecretsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A key of a known shape, made up for the test.
     */
    protected const KEY = 'sk_live_TESTONLY0000000000000000';

    public function test_a_key_written_into_the_notes_is_cut_from_the_notes_and_their_history_and_logged_by_note()
    {
        Log::spy();
        $project = Project::factory()->create();

        app(ProjectNotes::class)->put($project, 'main', ['areas/billing.md' => 'Charge with '.self::KEY.' each month.']);

        $expected = 'Charge with '.Secrets::REMOVED.' each month.';
        $this->assertSame($expected, app(ProjectNotes::class)->files($project, 'main')['areas/billing.md']);
        $this->assertSame([$expected], $project->noteRevisions()->pluck('contents')->all());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context === ['project' => $project->id, 'branch' => 'main', 'path' => 'areas/billing.md']
            && ! str_contains($message.json_encode($context), self::KEY));
    }

    public function test_a_change_that_wrote_a_key_into_the_notes_can_still_be_undone()
    {
        $project = Project::factory()->create();
        $notes = app(ProjectNotes::class);
        $changes = ['areas/billing.md' => ['before' => null, 'after' => 'Charge with '.self::KEY.'.']];

        $this->assertSame([], $notes->apply($project, 'main', $changes));
        $this->assertSame('Charge with '.Secrets::REMOVED.'.', $notes->files($project, 'main')['areas/billing.md']);

        // What was saved is the change's text with the key cut, so the
        // change still owns the note and takes it away again.
        $this->assertSame([], $notes->undo($project, 'main', $changes));
        $this->assertSame([], $notes->files($project, 'main'));
    }

    public function test_keys_saved_in_notes_before_are_cut_once_and_clean_notes_are_left_alone()
    {
        Log::spy();
        $project = Project::factory()->create();
        $row = fn (string $contents) => ['project_id' => $project->id, 'branch' => 'main', 'path' => 'areas/'.md5($contents).'.md', 'contents' => $contents, 'created_at' => now(), 'updated_at' => now()];
        DB::table('project_notes')->insert([$row('Key: '.self::KEY), $row('Nothing to hide.')]);
        DB::table('project_note_revisions')->insert([$row('Key: '.self::KEY), [...$row('Removed.'), 'contents' => null]]);

        (require database_path('migrations/2026_10_05_124906_cut_secret_keys_from_saved_notes.php'))->up();

        $this->assertSame(['Key: '.Secrets::REMOVED, 'Nothing to hide.'], DB::table('project_notes')->orderBy('id')->pluck('contents')->all());
        $this->assertSame(['Key: '.Secrets::REMOVED, null], DB::table('project_note_revisions')->orderBy('id')->pluck('contents')->all());
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_the_owner_is_told_to_keep_a_key_out_of_the_notes_and_nothing_is_saved()
    {
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->put(route('projects.understanding.update', $project), [
                'part' => 'introduction',
                'body' => 'Our Stripe key is '.self::KEY,
                'revision' => str_repeat('a', 40),
            ])
            ->assertSessionHasErrors(['body' => 'This looks like a secret key. Keep keys in your app\'s settings, not in its notes.']);

        $this->assertSame([], app(ProjectNotes::class)->files($project, 'main'));
    }
}
