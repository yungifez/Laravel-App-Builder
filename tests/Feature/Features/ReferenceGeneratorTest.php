<?php

namespace Tests\Feature\Features;

use App\Features\Exceptions\CannotGenerateFeature;
use App\Features\FeatureGeneratorManager;
use App\Models\FeatureRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesReferenceSolutions;
use Tests\TestCase;

class ReferenceGeneratorTest extends TestCase
{
    use RefreshDatabase, UsesReferenceSolutions;

    public function test_a_request_is_answered_by_the_matching_top_level_solution()
    {
        $this->useReferenceSolutions();
        $request = FeatureRequest::factory()->create(['prompt' => 'Let owners INVITE people']);

        $change = app(FeatureGeneratorManager::class)->driver()->generate($request);

        $this->assertSame('team-invitations', $change->solutionKey);
        $this->assertStringContainsString('members:invite', $change->patch);
        $this->assertSame('permission', $change->steps[0]['key']);
    }

    public function test_a_follow_up_is_answered_by_the_solution_for_its_parent_and_step()
    {
        $this->useReferenceSolutions();
        $parent = FeatureRequest::factory()->generated()->create();
        $followUp = FeatureRequest::factory()->for($parent->project)->create([
            'parent_id' => $parent->id,
            'target_step' => 'permission',
            'prompt' => 'Only the owner may do this.',
        ]);

        $change = app(FeatureGeneratorManager::class)->driver()->generate($followUp);

        $this->assertSame('owner-only-invitations', $change->solutionKey);
    }

    public function test_requests_without_a_known_solution_cannot_be_generated()
    {
        $this->useReferenceSolutions();
        $request = FeatureRequest::factory()->create(['prompt' => 'Add billing']);

        $this->expectException(CannotGenerateFeature::class);

        app(FeatureGeneratorManager::class)->driver()->generate($request);
    }

    public function test_a_missing_solutions_directory_is_reported()
    {
        config(['builder.generators.reference.path' => null]);
        $request = FeatureRequest::factory()->create();

        $this->expectException(CannotGenerateFeature::class);
        $this->expectExceptionMessage('BUILDER_REFERENCE_SOLUTIONS');

        app(FeatureGeneratorManager::class)->driver('reference')->generate($request);
    }
}
