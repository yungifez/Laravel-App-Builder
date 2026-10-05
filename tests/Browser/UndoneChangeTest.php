<?php

use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;

/*
| An undone change is settled: it does not look kept, and it offers nothing
| to try or check, since it is no longer part of the app.
*/

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->project = Project::factory()->for($this->owner, 'owner')->create();
    $this->actingAs($this->owner);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function keptChange(Project $project, array $attributes = []): FeatureRequest
{
    $change = FeatureRequest::factory()->for($project)->generated()->create(['commit_sha' => str_repeat('a', 40), 'accepted_at' => now(), ...$attributes]);
    Run::factory()->for($change)->create(['status' => RunStatus::Completed]);

    return $change;
}

it('shows an undone change as settled, with nothing to try or check', function () {
    $change = keptChange($this->project, ['revert_sha' => str_repeat('b', 40), 'reverted_at' => now()]);

    visit(route('feature-requests.show', $change))
        ->assertSee('Undone')
        ->assertAttributeContains('@state-dot', 'class', 'bg-muted-foreground')
        ->assertMissing('@preview')
        ->assertMissing('@verification')
        ->assertNoJavaScriptErrors();
});

it('shows a kept change as kept, still to try and check', function () {
    $change = keptChange($this->project);

    visit(route('feature-requests.show', $change))
        ->assertSee('Kept')
        ->assertAttributeContains('@state-dot', 'class', 'bg-green-600')
        ->assertPresent('@preview')
        ->assertPresent('@verification')
        ->assertNoJavaScriptErrors();
});
