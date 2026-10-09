<?php

use App\Actions\Projects\CreateProject;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Experiment;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;
use Tests\Concerns\PreparesRuns;

uses(PreparesRuns::class);

/*
| A new version that went online but does not work, in a real browser: the
| owner's step is to fix it, not to send the same version again. A host
| still starting it is checked again first, with "Try again" second.
*/

/**
 * @param  list<array{path: string, status: int|null, passed: bool, key?: string}>  $health
 */
function publishedNeedingAttention(Project $project, array $health, ?string $cause = null): void
{
    $project->update(['deploy_remote' => 'https://git.example.com/acme.git', 'deploy_branch' => 'main']);

    Deployment::factory()->for($project)->create([
        'user_id' => $project->user_id,
        'commit_sha' => app(ProjectRepository::class)->head($project, Experiment::mainBranch()),
        'status' => DeploymentStatus::NeedsAttention,
        'checks' => [['name' => 'Tests', 'passed' => true]],
        'health' => $health,
        'error' => 'Your app is online at https://shop.example.com, but people cannot sign in.',
        'error_cause' => $cause,
    ]);
}

it('offers a fix and no same-version retry when the app online does not work', function () {
    $owner = User::factory()->create();
    $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
    app(ProjectRepository::class)->import($project);
    publishedNeedingAttention($project, [['path' => 'login', 'status' => 500, 'passed' => false, 'key' => 'auth.sign-in']]);

    $this->actingAs($owner);

    visit(route('projects.show', $project))
        ->click('@publish-open')
        ->assertSee('people cannot sign in')
        ->assertVisible('@fix-failed-checks')
        ->assertMissing('@publish-button')
        ->assertNoJavaScriptErrors();
});

it('checks again first and keeps try again when the host is still starting the new version', function () {
    $owner = User::factory()->create();
    $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
    app(ProjectRepository::class)->import($project);
    publishedNeedingAttention($project, [], 'starting');

    $this->actingAs($owner);

    visit(route('projects.show', $project))
        ->click('@publish-open')
        ->assertVisible('@publish-check')
        ->assertSeeIn('@publish-button', 'Try again')
        ->assertMissing('@fix-failed-checks')
        ->assertNoJavaScriptErrors();
});

it('offers to check a version sent before the web address was given', function () {
    $owner = User::factory()->create();
    $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
    app(ProjectRepository::class)->import($project);
    $project->update(['deploy_remote' => 'https://git.example.com/acme.git', 'deploy_branch' => 'main']);
    Deployment::factory()->for($project)->create([
        'user_id' => $owner->id,
        'commit_sha' => app(ProjectRepository::class)->head($project, Experiment::mainBranch()),
        'status' => DeploymentStatus::Sent,
        'pushed_at' => now(),
    ]);

    $this->actingAs($owner);

    // Without an address there is nowhere to check yet.
    visit(route('projects.show', $project))
        ->click('@publish-open')
        ->assertSee('Add your app’s web address')
        ->assertMissing('@publish-check');

    $project->update(['live_url' => 'https://shop.example.com']);

    visit(route('projects.show', $project))
        ->click('@publish-open')
        ->assertSee('Check that it’s online at your web address.')
        ->assertVisible('@publish-check')
        ->assertMissing('@publish-button')
        ->assertNoJavaScriptErrors();
});
