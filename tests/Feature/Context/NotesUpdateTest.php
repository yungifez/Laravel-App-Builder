<?php

namespace Tests\Feature\Context;

use App\Actions\Projects\CreateProject;
use App\Ai\Agents\NotesKeeper;
use App\Context\ProjectNotes;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Projects\ProjectRepository;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class NotesUpdateTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected const PLANS = "---\ncapability: plans\npaths: [app/Plans/*]\n---\n\n# Plans\n\nA plan has a name.\n";

    protected const TEAMS = "---\ncapability: teams\npaths: [app/Models/Team.php]\n---\n\n# Teams\n\nA team has members.\n";

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.anthropic.key' => 'anthropic-test-key']);

        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([
            '.builder/project.md' => "# Acme\n",
            '.builder/capabilities/plans.md' => self::PLANS,
            '.builder/capabilities/teams.md' => self::TEAMS,
            'app/Plans/Plan.php' => "<?php\n",
        ]));
        app(ProjectRepository::class)->import($this->project);
    }

    /**
     * A kept change to plans whose notes it left behind.
     *
     * @param  list<string>  $behind
     */
    private function kept(array $behind = ['plans']): FeatureRequest
    {
        $change = FeatureRequest::factory()->generated()->for($this->project)->for($this->owner, 'user')->create([
            'commit_sha' => fake()->sha1(),
            'accepted_at' => now(),
            'patch' => "diff --git a/app/Plans/Plan.php b/app/Plans/Plan.php\n--- a/app/Plans/Plan.php\n+++ b/app/Plans/Plan.php\n@@ -1 +1,2 @@\n <?php\n+// A plan has a price.\n",
        ]);
        Run::factory()->for($change)->create([
            'status' => RunStatus::Completed,
            'context' => ['mode' => 'selective', 'targets' => ['plans'], 'text' => '', 'included' => [], 'problems' => [], 'outline' => [
                ['key' => 'plans', 'name' => 'Plans', 'summary' => null, 'file' => 'capabilities/plans.md', 'paths' => ['app/Plans/*'], 'behaviors' => [], 'effects' => []],
                ['key' => 'teams', 'name' => 'Teams', 'summary' => null, 'file' => 'capabilities/teams.md', 'paths' => ['app/Models/Team.php'], 'behaviors' => [], 'effects' => []],
            ]],
            'review' => ['approved' => true, 'summary' => 'Plans now have a price.', 'preserved' => [], 'verified' => [], 'coverage' => [], 'findings' => [], 'changes' => [],
                'classification' => ['requested' => ['plans' => ['app/Plans/Plan.php']], 'may_also_affect' => [], 'unexpected' => [], 'unclaimed' => [], 'context_updates' => [], 'targets' => ['plans'], 'observed' => [], 'notes_behind' => $behind]],
        ]);

        return $change;
    }

    private function page(FeatureRequest $change): TestResponse
    {
        return $this->actingAs($this->owner)->get(route('feature-requests.show', $change));
    }

    /**
     * @return array<string, string>
     */
    private function notes(): array
    {
        return app(ProjectNotes::class)->files($this->project, 'main');
    }

    public function test_the_owner_brings_the_notes_a_kept_change_left_behind_up_to_date()
    {
        $change = $this->kept();
        $this->page($change)->assertInertia(fn (Assert $page) => $page->where('run.review.notes_update', ['can' => true, 'state' => null, 'updated' => [], 'message' => null]));

        NotesKeeper::fake([['files' => [
            ['path' => 'capabilities/plans.md', 'contents' => str_replace('A plan has a name.', 'A plan has a name and a price.', self::PLANS)],
            // Not one of the notes it was shown: never written.
            ['path' => 'capabilities/teams.md', 'contents' => self::TEAMS.'Teams pay.'],
        ]]]);

        $this->actingAs($this->owner)->post(route('feature-requests.notes-updates.store', $change))->assertRedirect();

        $this->assertStringContainsString('A plan has a name and a price.', $this->notes()['capabilities/plans.md']);
        $this->assertSame(self::TEAMS, $this->notes()['capabilities/teams.md']);
        $this->assertSame(1, $change->latestRun->events()->where('type', 'model_call')->count());
        $this->page($change)->assertInertia(fn (Assert $page) => $page->where('run.review.notes_update', ['can' => false, 'state' => 'done', 'updated' => ['Plans'], 'message' => null]));
    }

    public function test_nothing_behind_or_a_change_not_kept_offers_no_update()
    {
        $this->page($this->kept(behind: []))->assertInertia(fn (Assert $page) => $page->where('run.review.notes_update.can', false));

        $unkept = $this->kept();
        $unkept->update(['commit_sha' => null, 'accepted_at' => null]);
        $this->page($unkept)->assertInertia(fn (Assert $page) => $page->where('run.review.notes_update.can', false));

        $this->actingAs($this->owner)->post(route('feature-requests.notes-updates.store', $unkept))->assertSessionHasErrors('notes');
        $this->actingAs(User::factory()->create())->post(route('feature-requests.notes-updates.store', $this->kept()))->assertForbidden();
    }

    public function test_a_refused_call_leaves_the_notes_as_they_were_and_says_it_is_our_fault()
    {
        $change = $this->kept();
        $before = $this->notes();
        NotesKeeper::fake(function () {
            throw new RequestException(new Response(new Psr7Response(400, ['Content-Type' => 'application/json'], (string) json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'The request echoed back.']]))));
        });

        $this->actingAs($this->owner)->post(route('feature-requests.notes-updates.store', $change))->assertRedirect();

        $this->assertSame($before, $this->notes());
        $run = $change->latestRun;
        $this->assertSame(['reason' => 'request_refused', 'status' => 400, 'type' => 'invalid_request_error', 'message' => 'The request echoed back.'], $run->events()->where('type', 'ai_service_error')->sole()->data);
        $this->page($change)->assertInertia(fn (Assert $page) => $page
            ->where('run.review.notes_update.state', 'failed')
            ->where('run.review.notes_update.can', true)
            ->where('run.review.notes_update.message', 'This is our fault: the AI service we use could not accept how we asked it. We have been told. Nothing in your app changed. Try again later.'));
    }

    public function test_notes_the_owner_edited_meanwhile_or_that_change_what_a_part_is_are_never_saved()
    {
        $change = $this->kept();

        // The model renames the part: refused, and the notes stay.
        NotesKeeper::fake([['files' => [['path' => 'capabilities/plans.md', 'contents' => str_replace('capability: plans', 'capability: pricing', self::PLANS)]]]]);
        $this->actingAs($this->owner)->post(route('feature-requests.notes-updates.store', $change));

        $this->assertSame(self::PLANS, $this->notes()['capabilities/plans.md']);
        $this->page($change)->assertInertia(fn (Assert $page) => $page->where('run.review.notes_update.state', 'done')->where('run.review.notes_update.updated', []));

        // The owner writes the notes while the model reads them: theirs win.
        $owners = str_replace('A plan has a name.', 'A plan has a name the owner chose.', self::PLANS);
        NotesKeeper::fake(function () use ($owners) {
            app(ProjectNotes::class)->put($this->project, 'main', ['capabilities/plans.md' => $owners]);

            return ['files' => [['path' => 'capabilities/plans.md', 'contents' => self::PLANS.'The model wrote this.']]];
        });
        $change = $this->kept();
        $this->actingAs($this->owner)->post(route('feature-requests.notes-updates.store', $change));

        $this->assertSame($owners, $this->notes()['capabilities/plans.md']);
        $this->page($change)->assertInertia(fn (Assert $page) => $page
            ->where('run.review.notes_update.state', 'failed')
            ->where('run.review.notes_update.message', 'Your notes changed while I was updating them, so I left them as they were. Ask again to update them.'));
    }
}
