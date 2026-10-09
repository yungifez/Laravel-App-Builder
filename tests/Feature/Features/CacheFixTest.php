<?php

namespace Tests\Feature\Features;

use App\Actions\Features\DescribeProof;
use App\Actions\Runs\StartRun;
use App\Enums\FeatureRequestStatus;
use App\Enums\VerificationStatus;
use App\Features\ProductionCaches;
use App\Models\FeatureRequest;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class CacheFixTest extends TestCase
{
    use RefreshDatabase;

    protected const DUPE = 'route: Unable to prepare route [b] for serialization. Another route has already been assigned name [home].';

    protected FeatureRequest $change;

    protected function setUp(): void
    {
        parent::setUp();

        // Building the fix is not what these tests are about.
        $this->mock(StartRun::class, fn (MockInterface $mock) => $mock->shouldReceive('handle'));

        $this->change = FeatureRequest::factory()->generated()->create();
    }

    /**
     * Record the change's check of going online.
     *
     * @param  array<string, mixed>  $result
     */
    protected function checked(array $result): Verification
    {
        return Verification::factory()->for($this->change)->create(['status' => VerificationStatus::Passed, 'results' => [
            ['name' => ProductionCaches::CHECK, 'stage' => 'checks', 'outcome' => 'failed', 'exit_code' => 1, 'timed_out' => false, 'duration_ms' => 5, 'output' => self::DUPE, ...$result],
        ]]);
    }

    public function test_a_problem_from_before_the_change_is_fixed_as_its_own_ask_with_what_the_check_printed()
    {
        $verification = $this->checked(['at_start' => 'failed', 'new_problems' => []]);
        $owner = $this->change->project->owner;

        $line = collect(app(DescribeProof::class)->handle($this->change))->firstWhere('fix');
        $this->assertSame(['gap', 'Your app could not go online before this change either: two pages share a name.', ['change' => $this->change->uuid]], [$line['kind'], $line['text'], $line['fix']]);

        $response = $this->actingAs($owner)->post(route('feature-requests.cache-fixes.store', $this->change));

        $fix = FeatureRequest::query()->whereKeyNot($this->change->id)->sole();
        $response->assertRedirect(route('projects.show', ['project' => $this->change->project, 'change' => $fix->uuid]));
        $this->assertSame('Fix what keeps my app from going online.', $fix->prompt);
        $this->assertSame([$verification->id, FeatureRequestStatus::Generating], [$fix->failed_checks['verification_id'] ?? null, $fix->status]);
        $this->assertNull($fix->parent_id, 'the fix is for the app as it is, not built on the change');

        $instructions = $fix->instructions();
        $this->assertStringContainsString('failed the same way on the app before that change', $instructions);
        $this->assertStringContainsString('Another route has already been assigned name [home]', $instructions);
        $this->assertStringContainsString('Do not skip, weaken or delete a check or a test', $instructions);
    }

    public function test_a_second_tap_opens_the_fix_already_asked_for()
    {
        $this->checked(['at_start' => 'failed', 'new_problems' => []]);
        $owner = $this->change->project->owner;

        $this->actingAs($owner)->post(route('feature-requests.cache-fixes.store', $this->change));
        $first = FeatureRequest::query()->whereKeyNot($this->change->id)->sole();

        $this->actingAs($owner)->post(route('feature-requests.cache-fixes.store', $this->change))
            ->assertRedirect(route('projects.show', ['project' => $this->change->project, 'change' => $first->uuid]));

        $this->assertSame(2, FeatureRequest::count());
    }

    public function test_a_problem_the_change_brought_goes_back_with_the_change_and_offers_no_separate_fix()
    {
        // New with the change: the change is sent back to be fixed instead.
        $this->checked(['at_start' => 'passed']);
        $owner = $this->change->project->owner;

        $this->assertNull(collect(app(DescribeProof::class)->handle($this->change))->firstWhere('fix'));

        $this->actingAs($owner)->post(route('feature-requests.cache-fixes.store', $this->change))
            ->assertSessionHasErrors(['fix' => 'The last check found nothing that keeps your app from going online.']);
        $this->actingAs(User::factory()->create())->post(route('feature-requests.cache-fixes.store', $this->change))->assertForbidden();

        $this->assertSame(1, FeatureRequest::count());
    }
}
