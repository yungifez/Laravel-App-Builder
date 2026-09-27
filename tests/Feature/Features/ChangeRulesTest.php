<?php

namespace Tests\Feature\Features;

use App\Actions\Features\DescribeProof;
use App\Actions\Projects\CreateProject;
use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Models\User;
use App\Models\Verification;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class ChangeRulesTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    public function test_the_proof_names_what_must_stay_true_in_the_parts_a_change_touched()
    {
        $this->fakeWorkspaces();
        $repository = app(ProjectRepository::class);
        $owner = User::factory()->create();
        $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource([
            'app/Teams/RenameTeam.php' => "<?php\n",
            'app/Billing/Charge.php' => "<?php\n",
            '.builder/capabilities/teams.md' => "---\ncapability: teams\nsummary: Teams.\npaths: [app/Teams/*]\n---\n# Teams\n\n## Rules\n\n- A team has exactly one owner.\n- Only owners can rename a team.\n",
            '.builder/capabilities/billing.md' => "---\ncapability: billing\nsummary: Billing.\npaths: [app/Billing/*]\n---\n# Billing\n\n## Rules\n\n- Customers are never charged twice.\n",
        ]), draftNotes: false);
        $repository->import($project);

        $commit = $repository->commitFiles($project, $repository->head($project), ['app/Teams/RenameTeam.php' => "<?php\n\n// Renamed.\n"], 'Rename teams', null);
        $featureRequest = FeatureRequest::factory()->create(['project_id' => $project->id, 'commit_sha' => $commit]);
        Verification::factory()->for($featureRequest)->create(['status' => VerificationStatus::Passed, 'results' => []]);

        $rules = array_values(array_filter(app(DescribeProof::class)->handle($featureRequest), fn (array $line) => $line['kind'] === 'rule'));

        // Billing was not touched, so its rule is not named; a rule is what
        // the change had to keep, never evidence that it did.
        $this->assertSame([
            ['kind' => 'rule', 'text' => 'Must stay true in Teams:', 'items' => ['A team has exactly one owner.', 'Only owners can rename a team.']],
        ], $rules);
    }
}
