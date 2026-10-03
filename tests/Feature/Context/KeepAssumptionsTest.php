<?php

namespace Tests\Feature\Context;

use App\Actions\Context\KeepAssumptions;
use App\Actions\Runs\AcquireRunLease;
use App\Actions\Runs\ExtractCandidateChange;
use App\Actions\Runs\PrepareRunWorkspace;
use App\Context\ProjectNotes;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Runs\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class KeepAssumptionsTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    public function test_a_change_about_one_area_keeps_its_assumptions_in_that_areas_notes(): void
    {
        $run = $this->prepare(['project.md' => "# Project\n\nA booking app.\n", 'capabilities/bookings.md' => "# Bookings\n\nRooms are booked.\n"]);
        $plan = new Plan('Book rooms', assumptions: [
            'Any signed-in member may book any room.',
            'The bookings table gets a starts_at column.',
        ]);

        $kept = app(KeepAssumptions::class)->handle($run->workspace, $plan, ['bookings']);

        $this->assertSame(['Any signed-in member may book any room.'], $kept, 'how the code is built stays out');
        $notes = app(ExtractCandidateChange::class)->notes($run->workspace);
        $this->assertSame(['capabilities/bookings.md'], array_keys($notes));
        $this->assertSame(
            "# Bookings\n\nRooms are booked.\n\n## Assumptions\n\n- Any signed-in member may book any room. (assumed)\n",
            $notes['capabilities/bookings.md']['after'],
        );
    }

    public function test_assumptions_are_added_once_and_go_to_the_project_notes_without_one_area(): void
    {
        $run = $this->prepare(['project.md' => "# Project\n\n## Assumptions\n\n- Bookings last at most a day. (assumed)\n"]);
        $plan = new Plan('Book rooms', assumptions: ['Bookings last at most a day.', 'A cancelled booking frees the room.']);

        app(KeepAssumptions::class)->handle($run->workspace, $plan, ['bookings', 'rooms']);
        $again = app(KeepAssumptions::class)->handle($run->workspace, $plan, ['bookings', 'rooms']);

        $this->assertSame([], $again, 'a repair pass adds nothing twice');
        $this->assertSame(
            "# Project\n\n## Assumptions\n\n- Bookings last at most a day. (assumed)\n- A cancelled booking frees the room. (assumed)\n",
            File::get($this->workspaceFile($run, '.product-notes/project.md')),
        );
    }

    public function test_an_area_without_notes_shares_the_projects_and_an_app_without_notes_gets_none(): void
    {
        $plan = new Plan('Book rooms', assumptions: ['A cancelled booking frees the room.']);
        $run = $this->prepare(['project.md' => "# Project\n"]);

        app(KeepAssumptions::class)->handle($run->workspace, $plan, ['bookings']);

        $this->assertStringContainsString('- A cancelled booking frees the room. (assumed)', File::get($this->workspaceFile($run, '.product-notes/project.md')));

        $bare = $this->prepare([]);

        $this->assertSame([], app(KeepAssumptions::class)->handle($bare->workspace, $plan, []));
        $this->assertSame([], app(ExtractCandidateChange::class)->notes($bare->workspace));
    }

    public function test_a_replayed_known_solution_keeps_nothing(): void
    {
        $run = $this->prepare(['project.md' => "# Project\n"]);
        $plan = new Plan('Book rooms', assumptions: ['Replays the known-good solution "rooms".'], solutionKey: 'rooms');

        $this->assertSame([], app(KeepAssumptions::class)->handle($run->workspace, $plan, []));
    }

    /**
     * Prepare a workspace for a run of a change in an app with these notes.
     *
     * @param  array<string, string>  $notes
     */
    protected function prepare(array $notes): Run
    {
        $this->buildInLocalWorkspaces();
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        app(ProjectNotes::class)->put($project, 'main', $notes);
        $run = Run::factory()->implementing()->for(FeatureRequest::factory()->for($project))->create();
        $lease = app(AcquireRunLease::class)->handle($run, 'worker-a');
        $this->assertNotNull($lease);

        app(PrepareRunWorkspace::class)->handle($run, $lease);

        return $run->refresh();
    }
}
