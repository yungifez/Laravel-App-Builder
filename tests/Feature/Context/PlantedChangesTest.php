<?php

namespace Tests\Feature\Context;

use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PlantedChangesTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config(['builder.context.experiment.min_pairs' => 2]);
        $this->project = Project::factory()->for(User::factory(), 'owner')->create();
    }

    /**
     * Keep a change about the given areas. Bookings says it may also affect
     * billing; nothing says it affects members.
     *
     * @param  list<string>  $targets
     * @param  list<string>  $files
     * @param  array<string, mixed>  $attributes
     */
    private function kept(array $targets, array $files, array $attributes = [], bool $withContext = true): FeatureRequest
    {
        $request = FeatureRequest::factory()->for($this->project)->create([
            'patch' => implode('', array_map(fn (string $file) => "diff --git a/{$file} b/{$file}\n--- a/{$file}\n+++ b/{$file}\n@@ -1 +1,2 @@\n+change\n", $files)),
            'commit_sha' => sha1(implode($files)),
            'accepted_at' => now(),
            ...$attributes,
        ]);

        Run::factory()->for($request)->create([
            'context' => $withContext ? ['mode' => 'selective', 'targets' => $targets, 'text' => '', 'included' => [], 'problems' => [], 'outline' => [
                ['key' => 'bookings', 'name' => 'Bookings', 'summary' => null, 'file' => null, 'paths' => ['app/Bookings/*'], 'behaviors' => [], 'effects' => [
                    ['to' => 'billing', 'strength' => 'possible', 'reason' => 'A booking takes a payment.', 'source' => 'owner', 'observed' => null],
                ]],
                ['key' => 'billing', 'name' => 'Billing', 'summary' => null, 'file' => null, 'paths' => ['app/Billing/*'], 'behaviors' => [], 'effects' => []],
                ['key' => 'members', 'name' => 'Members', 'summary' => null, 'file' => null, 'paths' => ['app/Members/*', 'routes/members.php'], 'behaviors' => [], 'effects' => []],
            ]] : null,
        ]);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function results(): array
    {
        Artisan::call('builder:planted-changes', ['project' => $this->project->id, '--json' => true]);

        return json_decode(Artisan::output(), true);
    }

    public function test_effects_explain_away_a_plant_in_an_area_the_change_may_affect()
    {
        $first = $this->kept(['bookings'], ['app/Bookings/Book.php']);
        $this->kept(['billing'], ['app/Billing/Charge.php', 'app/Members/Invite.php']);

        $results = $this->results();

        $this->assertSame(2, $results['changes']);
        // The first change gets a plant in billing and in members, from files other kept changes touched.
        $this->assertSame([
            ['change' => $first->id, 'area' => 'billing', 'path' => 'app/Billing/Charge.php', 'with_effects' => 'may_also_affect', 'without_effects' => 'unexpected'],
            ['change' => $first->id, 'area' => 'members', 'path' => 'app/Members/Invite.php', 'with_effects' => 'unexpected', 'without_effects' => 'unexpected'],
        ], array_slice($results['plants'], 0, 2));
        // Billing's change says nothing about what it affects, so its plants are caught either way.
        $this->assertSame(['unexpected' => 4, 'may_also_affect' => 0, 'unclaimed' => 0], $results['without_effects']);
        $this->assertSame(['unexpected' => 3, 'may_also_affect' => 1, 'unclaimed' => 0], $results['with_effects']);
        $this->assertSame(4, $results['pairs']);
        $this->assertTrue($results['enough']);
        $this->assertSame(['app/Billing/Charge.php'], array_column($results['explained_away'], 'path'));

        $this->artisan('builder:planted-changes', ['project' => $this->project->id])
            ->expectsOutputToContain('Of 4 plants caught without Effects, Effects explained away 1.')
            ->assertSuccessful();
    }

    public function test_the_same_changes_give_the_same_plants_and_one_plant_is_too_few()
    {
        // No kept change touched a members file, so members is planted at its one named file.
        $this->kept(['bookings'], ['app/Bookings/Book.php']);

        $first = $this->results();
        $this->assertSame($first, $this->results(), 'Runs are comparable.');
        $this->assertSame([['routes/members.php', 'members', 'unexpected', 'unexpected']], array_map(fn (array $plant) => [$plant['path'], $plant['area'], $plant['with_effects'], $plant['without_effects']], $first['plants']));
        $this->assertSame(1, $first['pairs']);
        $this->assertFalse($first['enough']);

        $this->artisan('builder:planted-changes', ['project' => $this->project->id])
            ->expectsOutputToContain('1 plants: too few pairs.')
            ->assertSuccessful();
    }

    public function test_changes_that_were_not_kept_or_have_no_context_plant_nothing()
    {
        $this->artisan('builder:planted-changes', ['project' => 999999])->assertFailed();

        $this->kept(['bookings'], ['app/Bookings/Book.php'], ['commit_sha' => null, 'accepted_at' => null]);
        $this->kept(['bookings'], ['app/Bookings/Cancel.php'], ['reverted_at' => now()]);
        $this->kept(['bookings'], ['app/Bookings/Move.php'], withContext: false);

        $results = $this->results();
        $this->assertSame(0, $results['changes']);
        $this->assertSame([], $results['plants']);

        $this->artisan('builder:planted-changes', ['project' => $this->project->id])
            ->expectsOutputToContain('Nothing to plant yet.')
            ->assertSuccessful();
        $this->assertSame(3, $this->project->featureRequests()->count(), 'Nothing is saved.');
    }
}
