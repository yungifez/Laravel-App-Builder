<?php

use App\Actions\Projects\CreateProject;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Experiment;
use App\Models\User;
use App\Projects\ProjectRepository;
use Tests\Concerns\PreparesRuns;

uses(PreparesRuns::class);

/*
| A publish that failed, in a real browser: the owner reads what to do in
| plain words, and what Git or the host said waits behind "Where it goes".
*/

it('points to the publishing settings when the repository could not be reached', function () {
    $owner = User::factory()->create();
    $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
    app(ProjectRepository::class)->import($project);
    $project->update(['deploy_remote' => 'https://git.example.com/acme.git', 'deploy_branch' => 'main']);
    Deployment::factory()->for($project)->create([
        'user_id' => $owner->id,
        'status' => DeploymentStatus::Failed,
        'error' => 'I could not send it to the repository at https://git.example.com/acme.git. Check the repository address and branch under "Change where to publish", then try again. Your app online has not changed.',
        'error_cause' => 'settings',
        'error_details' => 'fatal: unable to access the repository: Could not resolve host: git.example.com',
    ]);

    $this->actingAs($owner);

    $page = visit(route('projects.show', $project))
        ->click('@publish-open')
        ->assertSee('Check the repository address and branch')
        ->assertVisible('@publish-settings')
        ->assertSeeIn('@publish-button', 'Try again')
        ->assertMissing('@publish-ask-developer')
        ->assertDontSee('Could not resolve host');

    $page->click('Where it goes')
        ->assertSeeIn('@publish-error-details', 'Could not resolve host')
        ->click('@publish-settings')
        ->assertSee('Repository address')
        ->assertNoJavaScriptErrors();
});

it('says our own failure in plain words with no settings step', function () {
    $owner = User::factory()->create();
    $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
    app(ProjectRepository::class)->import($project);
    $project->update(['deploy_remote' => 'https://git.example.com/acme.git', 'deploy_branch' => 'main']);
    Deployment::factory()->for($project)->create([
        'user_id' => $owner->id,
        'status' => DeploymentStatus::Failed,
        'error' => 'This is our fault: publishing stopped on our side. Your app online has not changed. Try again.',
        'error_cause' => 'ours',
        'error_details' => 'Undefined index: release_id',
    ]);

    $this->actingAs($owner);

    visit(route('projects.show', $project))
        ->click('@publish-open')
        ->assertSee('This is our fault')
        ->assertSeeIn('@publish-button', 'Try again')
        ->assertMissing('@publish-settings')
        ->assertDontSee('Undefined index')
        ->assertNoJavaScriptErrors();
});

it('sends the owner to a developer, not to try again, when the branch has work the app does not', function () {
    $owner = User::factory()->create();
    $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
    app(ProjectRepository::class)->import($project);
    $project->update(['deploy_remote' => 'https://git.example.com/acme.git', 'deploy_branch' => 'main']);
    $deployment = Deployment::factory()->for($project)->create([
        'user_id' => $owner->id,
        'commit_sha' => app(ProjectRepository::class)->head($project, Experiment::mainBranch()),
        'status' => DeploymentStatus::Failed,
        'error' => 'The published app has changes that are not in this project, so I did not replace them. Ask your developer to bring them in first.',
        'error_cause' => 'conflict',
    ]);

    $this->actingAs($owner);

    visit(route('projects.show', $project))
        ->click('@publish-open')
        ->assertSee('Ask your developer to bring them in first')
        ->assertVisible('@publish-ask-developer')
        ->assertMissing('@publish-button')
        ->assertMissing('@publish-settings')
        ->click('@publish-ask-developer')
        ->assertPathIs('/projects/'.$project->uuid.'/developers')
        ->assertNoJavaScriptErrors();

    // Once a newer version is kept, putting that one online is offered again.
    $deployment->update(['commit_sha' => str_repeat('0', 40)]);

    visit(route('projects.show', $project))
        ->click('@publish-open')
        ->assertVisible('@publish-button')
        ->assertNoJavaScriptErrors();
});

it('offers a fix first when the hosting could not start the new version', function () {
    $owner = User::factory()->create();
    $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
    app(ProjectRepository::class)->import($project);
    $project->update(['deploy_remote' => 'https://git.example.com/acme.git', 'deploy_branch' => 'main']);
    Deployment::factory()->for($project)->create([
        'user_id' => $owner->id,
        'commit_sha' => app(ProjectRepository::class)->head($project, Experiment::mainBranch()),
        'status' => DeploymentStatus::Failed,
        'checks' => [['name' => 'Tests', 'passed' => true]],
        'error' => 'Your hosting could not start the new version, so your app online has not changed.',
        'error_cause' => 'release',
        'error_details' => 'SQLSTATE[42P01]: relation "plans" does not exist',
    ]);

    $this->actingAs($owner);

    // Sending it again stays, as the second step: a build can fail once.
    visit(route('projects.show', $project))
        ->click('@publish-open')
        ->assertVisible('@fix-failed-checks')
        ->assertSeeIn('@publish-button', 'Try again')
        ->assertMissing('@publish-ask-developer')
        ->assertDontSee('relation "plans"')
        ->assertNoJavaScriptErrors();
});
