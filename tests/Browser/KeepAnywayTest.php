<?php

use App\Actions\Projects\CreateProject;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Enums\VerificationStatus;
use App\Jobs\StartPreview;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\User;
use App\Models\Verification;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\PreparesRuns;

/*
| Only our review doubted a change whose checks passed: the owner may keep
| it anyway, after reading why and saying yes once more.
*/

uses(PreparesRuns::class);

function doubtedChange(VerificationStatus $checks): FeatureRequest
{
    Bus::fake([StartPreview::class]);
    $owner = User::factory()->create(['detail_level' => 1]);
    $project = app(CreateProject::class)->handle($owner, 'Acme', test()->makeProjectSource(['app/A.php' => "<?php\n"]), draftNotes: false);

    $change = FeatureRequest::factory()->generated()->for($project)->for($owner, 'user')->create([
        'prompt' => 'Add a comment.',
        'patch' => "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1 +1,2 @@\n <?php\n+// added\n",
        'base_revision' => app(ProjectRepository::class)->head($project),
    ]);
    $run = Run::factory()->for($change)->create([
        'status' => RunStatus::NeedsUserDecision,
        'stop_reason' => StopReason::ReviewFindings,
        'feedback' => ['reason' => 'review_findings', 'details' => ['The comment says nothing.']],
    ]);
    $run->recordEvent('status', ['from' => 'reviewing', 'to' => 'needs_user_decision', 'reason' => 'review_findings']);
    Verification::factory()->for($change)->create(['status' => $checks]);
    test()->actingAs($owner);

    return $change;
}

it('keeps a change only the review doubted once the owner says yes', function () {
    $change = doubtedChange(VerificationStatus::Passed);

    visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->assertSeeIn('[data-test="keep-anyway"]', "Your app's checks passed.")
        ->click('[data-test="keep-anyway"] summary')
        ->assertSeeIn('[data-test="keep-anyway-doubts"]', 'The comment says nothing.')
        ->click('[data-test="keep-anyway-button"]')
        ->assertSee('Keep it in your app with these problems?')
        ->click('[data-test="keep-anyway-confirm"]')
        ->assertMissing('[data-test="thread-failed"]')
        ->assertNoJavaScriptErrors();

    expect($change->refresh()->commit_sha)->not->toBeNull();
});

it('keeps nothing until the owner says yes', function () {
    $change = doubtedChange(VerificationStatus::Unverified);

    visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->resize(390, 844)
        ->assertSeeIn('[data-test="keep-anyway"]', "Your app's checks could not run.")
        ->click('[data-test="keep-anyway-button"]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->click('Not now')
        ->assertVisible('[data-test="keep-anyway-button"]')
        ->assertNoJavaScriptErrors();

    expect($change->refresh()->commit_sha)->toBeNull();
});

it('never offers to keep a change whose checks failed', function () {
    $change = doubtedChange(VerificationStatus::Failed);

    visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->assertVisible('[data-test="thread-failed"]')
        ->assertMissing('[data-test="keep-anyway"]')
        ->assertNoJavaScriptErrors();
});
