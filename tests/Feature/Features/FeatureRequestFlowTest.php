<?php

namespace Tests\Feature\Features;

use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInLocalWorkspaces;
use Tests\Concerns\UsesReferenceSolutions;
use Tests\TestCase;

class FeatureRequestFlowTest extends TestCase
{
    use BuildsInLocalWorkspaces, RefreshDatabase, UsesReferenceSolutions;

    public function test_the_owner_requests_a_feature_previews_it_and_restricts_its_permission_step()
    {
        Queue::fake([VerifyFeatureRequest::class]);
        $this->buildInLocalWorkspaces();
        $solutions = $this->useReferenceSolutions();
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create(['source_path' => "{$solutions}/source"]);

        $response = $this->actingAs($owner)
            ->post(route('feature-requests.store', $project), ['prompt' => 'Let owners and admins invite people by email.']);

        $request = $project->featureRequests()->sole();
        $response->assertRedirect(route('projects.show', ['project' => $project, 'change' => $request->id]));
        $this->assertSame(FeatureRequestStatus::Generated, $request->status);
        $this->assertSame(['Invitations/ContractTest.php'], $request->acceptance);
        $this->assertSame(RunStatus::Verifying, $request->latestRun->status);
        $this->assertSame($request->latestRun->id, $request->verifications()->sole()->run_id);

        $this->get(route('feature-requests.show', $request))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('feature-requests/Show')
                ->where('featureRequest.status', 'generated')
                ->where('featureRequest.summary', 'Owners and admins can invite people.')
                ->has('featureRequest.files', 2)
                ->where('featureRequest.files.0.path', 'app/Policies/TeamPolicy.php')
                ->where('featureRequest.files.0.additions', 2)
                ->where('featureRequest.steps.0.key', 'permission')
                ->where('featureRequest.steps.0.symbol', 'TeamPolicy::inviteMember'));

        $response = $this->post(route('feature-requests.step-changes.store', $request), [
            'step' => 'permission',
            'prompt' => 'Only the team owner may invite people.',
        ]);

        $followUp = $request->followUps()->sole();
        $response->assertRedirect(route('projects.show', ['project' => $followUp->project_id, 'change' => $followUp->id]));
        $this->assertSame(FeatureRequestStatus::Generated, $followUp->status);
        $this->assertSame('owner-only-invitations', $followUp->solution_key);

        $this->get(route('feature-requests.show', $followUp))
            ->assertInertia(fn (Assert $page) => $page
                ->where('parent.id', $request->id)
                ->where('featureRequest.target_step.label', 'Who may invite people')
                ->where('featureRequest.files.0.deletions', 1));

        $this->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->has('followUps', 1)
                ->where('followUps.0.target_step', 'permission'));
    }

    public function test_a_request_the_generator_cannot_answer_is_marked_failed_with_a_reason()
    {
        $this->buildInLocalWorkspaces();
        $solutions = $this->useReferenceSolutions();
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create(['source_path' => "{$solutions}/source"]);

        $this->actingAs($owner)->post(route('feature-requests.store', $project), ['prompt' => 'Add billing']);

        $request = $project->featureRequests()->sole();
        $this->assertSame(FeatureRequestStatus::Failed, $request->status);
        $this->assertSame('The reference generator has no solution for this request.', $request->error);
        $this->assertSame(RunStatus::Failed, $request->latestRun->status);
        $this->assertSame('The reference generator has no solution for this request.', $request->latestRun->error);
    }

    public function test_only_steps_of_the_generated_change_can_be_changed()
    {
        $request = FeatureRequest::factory()->generated()->create();

        $this->actingAs($request->project->owner)
            ->post(route('feature-requests.step-changes.store', $request), ['step' => 'nope', 'prompt' => 'Owner only'])
            ->assertSessionHasErrors('step');

        $this->assertSame(0, $request->followUps()->count());
    }

    public function test_other_users_cannot_see_or_change_requests()
    {
        $request = FeatureRequest::factory()->generated()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('feature-requests.show', $request))->assertForbidden();
        $this->actingAs($stranger)
            ->post(route('feature-requests.store', $request->project), ['prompt' => 'Invite people'])
            ->assertForbidden();
        $this->actingAs($stranger)
            ->post(route('feature-requests.step-changes.store', $request), ['step' => 'permission', 'prompt' => 'Owner only'])
            ->assertForbidden();
    }

    public function test_the_workspace_chat_shows_one_change_of_the_project()
    {
        $request = FeatureRequest::factory()->generated()->create(['summary' => 'Owners can invite people.']);

        $this->actingAs($request->project->owner)
            ->get(route('projects.show', ['project' => $request->project, 'change' => $request->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/Show')
                ->where('change.featureRequest.id', $request->id)
                ->where('change.featureRequest.summary', 'Owners can invite people.'));

        $this->get(route('projects.show', $request->project))
            ->assertInertia(fn (Assert $page) => $page->where('change', null));
    }

    public function test_the_workspace_does_not_show_a_change_of_another_project()
    {
        $request = FeatureRequest::factory()->generated()->create();
        $project = Project::factory()->for($request->project->owner, 'owner')->create();

        $this->actingAs($project->owner)
            ->get(route('projects.show', ['project' => $project, 'change' => $request->id]))
            ->assertNotFound();
    }
}
