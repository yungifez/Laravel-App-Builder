<?php

namespace Tests\Feature\Context;

use App\Actions\Context\ClassifyChange;
use App\Context\ChangeClassification;
use App\Context\ProjectContext;
use App\Models\FeatureRequest;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotesBehindTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Teams and billing have notes files; members has none yet.
     */
    private function context(): ProjectContext
    {
        $area = fn (string $key, ?string $file) => ['key' => $key, 'name' => ucfirst($key), 'summary' => null, 'file' => $file, 'paths' => ['app/'.ucfirst($key).'/*'], 'behaviors' => [], 'effects' => []];

        return ProjectContext::fromOutline([
            $area('teams', 'capabilities/teams.md'),
            $area('billing', 'capabilities/billing.md'),
            $area('members', null),
        ]);
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $notes
     */
    private function classify(array $files, array $notes): ChangeClassification
    {
        $patch = implode('', array_map(fn (string $file) => "diff --git a/{$file} b/{$file}\n--- a/{$file}\n+++ b/{$file}\n@@ -1 +1,2 @@\n+change\n", $files));

        return app(ClassifyChange::class)->handle($this->context(), ['teams'], $patch, $notes);
    }

    public function test_a_part_whose_code_changed_without_its_notes_is_listed()
    {
        $classification = $this->classify(['app/Teams/Team.php', 'app/Billing/Seats.php'], ['capabilities/teams.md']);

        $this->assertSame(['billing'], $classification->notesBehind);
        $this->assertSame(['billing'], $classification->toArray()['notes_behind']);
    }

    public function test_parts_whose_notes_changed_too_or_whose_code_did_not_change_are_not_listed()
    {
        $this->assertSame([], $this->classify(['app/Teams/Team.php', 'app/Billing/Seats.php'], ['capabilities/billing.md', 'capabilities/teams.md'])->notesBehind);
        // Code no part claims has no notes to fall behind.
        $this->assertSame([], $this->classify(['app/Other.php'], [])->notesBehind);
        // Teams is what the change is about, but none of its code changed.
        $this->assertSame([], $this->classify(['app/Billing/Seats.php'], ['capabilities/billing.md'])->notesBehind);
    }

    public function test_a_part_with_no_notes_file_is_listed_and_a_failed_notes_update_is_our_fault()
    {
        $this->assertSame(['members'], $this->classify(['app/Members/Invite.php'], [])->notesBehind);

        $request = FeatureRequest::factory()->generated()->create();
        $run = Run::factory()->for($request)->create([
            'context' => ['mode' => 'selective', 'targets' => ['teams'], 'text' => '', 'included' => [], 'problems' => [], 'outline' => $this->context()->outline()],
            'review' => ['approved' => true, 'summary' => '', 'preserved' => [], 'verified' => [], 'coverage' => [], 'findings' => [], 'changes' => [],
                'classification' => $this->classify(['app/Teams/Team.php', 'app/Members/Invite.php'], [])->toArray()],
        ]);

        $page = fn () => $this->actingAs($request->project->owner)->get(route('feature-requests.show', $request));

        $page()->assertInertia(fn (Assert $page) => $page
            ->where('run.review.notes_behind', [['key' => 'members', 'name' => 'Members'], ['key' => 'teams', 'name' => 'Teams']])
            ->where('run.review.notes_failed', false));

        // A worker's change whose notes update failed.
        $run->recordEvent('notes_not_updated', ['reason' => 'The model did not answer.']);

        $page()->assertInertia(fn (Assert $page) => $page->where('run.review.notes_failed', true));
    }
}
