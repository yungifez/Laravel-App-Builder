<?php

use App\Actions\Projects\CreateProject;
use App\Enums\FeatureRequestStatus;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\User;
use App\Models\Workspace;
use App\Previews\LoggedProblems;
use App\Projects\ProjectRepository;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;

uses(FakesWorkspaces::class, PreparesRuns::class);

/*
| A problem met while the owner made something fail on purpose is a
| question, not a fault: the owner says whether the app should cope, and
| saying it need not cope costs no AI.
*/

it('asks the owner whether the app should cope and puts the problem away when failing is fine', function () {
    $driver = $this->fakeWorkspaces();
    $owner = User::factory()->create();
    $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
    app(ProjectRepository::class)->import($project);
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    Preview::factory()->editable()->ready()->create(['project_id' => $project->id, 'workspace_id' => $workspace->id]);
    $log = '['.now()->format('Y-m-d H:i:s').'] local.ERROR: Could not send the welcome email [] {"simulated_outage":"mail: the mail server was made unreachable on purpose to see how the app copes"}'."\n";
    $driver->files["{$workspace->driver_id}:storage/logs/laravel.log"] = $log;
    $id = LoggedProblems::in($log)[0]['id'];

    $this->actingAs($owner);

    visit(route('projects.show', $project))
        ->click('@showing-problems')
        ->assertSee('Your app stopped with an error while email was down.')
        ->assertSee('Should your app keep working when email is down?')
        ->assertSee('If it should cope, I change your app, and that uses AI.')
        ->click("@app-problem-fine-{$id}")
        ->assertSee('No problems')
        ->click('@app-problems-done')
        ->assertSeeIn('@app-problems-done', 'Fine to fail when email is down')
        ->assertNoJavaScriptErrors();

    expect(FeatureRequest::count())->toBe(0)
        ->and($project->clearedProblems()->sole()->fine)->toBeTrue();
});

it('says the owner\'s answer back instead of asking again when a try to cope stopped', function () {
    $driver = $this->fakeWorkspaces();
    $owner = User::factory()->create();
    $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
    app(ProjectRepository::class)->import($project);
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    Preview::factory()->editable()->ready()->create(['project_id' => $project->id, 'workspace_id' => $workspace->id]);
    $log = '['.now()->format('Y-m-d H:i:s').'] local.ERROR: Could not send the welcome email [] {"simulated_outage":"mail: the mail server was made unreachable on purpose to see how the app copes"}'."\n";
    $driver->files["{$workspace->driver_id}:storage/logs/laravel.log"] = $log;
    $id = LoggedProblems::in($log)[0]['id'];
    FeatureRequest::factory()->create(['project_id' => $project->id, 'user_id' => $owner->id, 'status' => FeatureRequestStatus::Failed, 'live_errors' => ['problem' => $id]]);

    $this->actingAs($owner);

    visit(route('projects.show', $project))
        ->click('@showing-problems')
        ->assertSee('You said your app should keep working when email is down.')
        ->assertSeeIn("@app-problem-cope-{$id}", 'Try again')
        ->assertSee('Trying again uses AI.')
        ->assertDontSee('Should your app keep working when email is down?')
        ->assertNoJavaScriptErrors();
});
