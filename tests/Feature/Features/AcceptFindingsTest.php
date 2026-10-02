<?php

namespace Tests\Feature\Features;

use App\Actions\Features\DescribeProof;
use App\Enums\VerificationStatus;
use App\Features\AppBoundaries;
use App\Features\AppFaults;
use App\Models\FeatureRequest;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcceptFindingsTest extends TestCase
{
    use RefreshDatabase;

    protected const GAP = 'At /posts your app saves or sends something while it checks who may do something. That check can run many times, for example once for each item on a page, so it happens again each time.';

    protected const CHOSEN = 'You said you want this: at /posts your app saves or sends something while it checks who may do something, so it happens again each time that check runs. If a later change does more of this, I will ask again.';

    /**
     * A change whose checks saw its policy save while checking who may act,
     * and read a second save of it from the code.
     */
    protected function found(array $attributes = []): FeatureRequest
    {
        $change = FeatureRequest::factory()->generated()->create($attributes);
        $finding = fn (string $at, string $what) => ['kind' => AppBoundaries::CHANGED_WHILE_AUTHORIZING, 'route' => 'GET /posts', 'what' => $what, 'at' => $at, 'in' => 'App\Policies\PostPolicy::view', 'test' => null];

        Verification::factory()->for($change)->create([
            'status' => VerificationStatus::Passed,
            'results' => [],
            'evidence' => [
                'traces' => ['requests' => 4, 'reached' => 2, 'unseen' => 0, 'existing' => 0, 'findings' => [], 'repeats' => []],
                'boundaries' => ['phased' => 10, 'unknown' => 0, 'existing' => 0, 'findings' => [
                    $finding('app/Policies/PostPolicy.php:12', 'update posts'),
                    $finding('app/Policies/PostPolicy.php:14', 'http POST stats.example.com'),
                ], 'read' => [
                    ['kind' => AppBoundaries::CHANGED_WHILE_AUTHORIZING, 'what' => 'save', 'at' => 'app/Policies/PostPolicy.php:20', 'in' => 'App\Policies\PostPolicy::view'],
                ]],
            ],
        ]);

        return $change;
    }

    /**
     * Get the proof line about saving while checking who may act.
     *
     * @return array<string, mixed>|null
     */
    protected function line(FeatureRequest $change): ?array
    {
        return collect(app(DescribeProof::class)->handle($change->refresh()))->first(fn (array $line) => str_contains($line['text'], 'checks who may do something'));
    }

    public function test_the_owner_says_they_want_what_a_boundary_rule_found_and_takes_it_back()
    {
        $change = $this->found();
        $owner = $change->project->owner;

        $this->assertSame(['kind' => 'gap', 'text' => self::GAP, 'decision' => ['change' => $change->uuid, 'finding' => AppBoundaries::CHANGED_WHILE_AUTHORIZING, 'accepted' => false]], $this->line($change));

        $this->actingAs($owner)->post(route('feature-requests.accepted-findings.store', [$change, AppBoundaries::CHANGED_WHILE_AUTHORIZING]))->assertRedirect();

        // Each finding by what it is: the seen save and the read one are the same save.
        $this->assertEqualsCanonicalizing([
            'changed_while_authorizing|App\Policies\PostPolicy::view|save',
            'changed_while_authorizing|App\Policies\PostPolicy::view|http',
        ], $change->acceptedFindings()->pluck('identity')->all());
        $this->assertSame($owner->id, $change->acceptedFindings()->first()->user_id);
        $this->assertSame(['kind' => 'chosen', 'text' => self::CHOSEN, 'decision' => ['change' => $change->uuid, 'finding' => AppBoundaries::CHANGED_WHILE_AUTHORIZING, 'accepted' => true]], $this->line($change));

        // Saying it twice changes nothing.
        $this->actingAs($owner)->post(route('feature-requests.accepted-findings.store', [$change, AppBoundaries::CHANGED_WHILE_AUTHORIZING]))->assertRedirect();
        $this->assertSame(2, $change->acceptedFindings()->count());

        $this->actingAs($owner)->delete(route('feature-requests.accepted-findings.destroy', [$change, AppBoundaries::CHANGED_WHILE_AUTHORIZING]))->assertRedirect();

        $this->assertSame(0, $change->acceptedFindings()->count());
        $this->assertSame('gap', $this->line($change)['kind']);
    }

    public function test_a_finding_the_owner_did_not_see_is_still_a_gap()
    {
        $change = $this->found();
        $change->acceptedFindings()->create(['kind' => AppBoundaries::CHANGED_WHILE_AUTHORIZING, 'identity' => 'changed_while_authorizing|App\Policies\PostPolicy::view|save']);

        // The call out was not accepted, so the rule still has something to say.
        $this->assertSame('gap', $this->line($change)['kind']);
    }

    public function test_the_owner_says_they_want_what_a_caused_failure_left_behind()
    {
        $change = FeatureRequest::factory()->generated()->create();
        $owner = $change->project->owner;
        $finding = fn (string $at) => ['kind' => AppFaults::SAVED_THEN_FAILED, 'route' => 'POST /contact', 'failed' => 'mail App\Mail\ContactReceived', 'what' => 'insert messages', 'at' => $at, 'test' => 'Tests\Feature\ContactTest::test_a_visitor_writes'];
        $checked = fn (string $at) => Verification::factory()->for($change)->create([
            'status' => VerificationStatus::Passed,
            'results' => [],
            'evidence' => ['faults' => ['points' => 2, 'run' => 2, 'missed' => 0, 'existing' => 0, 'findings' => [$finding($at)]]],
        ]);
        $line = fn () => collect(app(DescribeProof::class)->handle($change->refresh()))->first(fn (array $line) => str_contains($line['text'], 'an email cannot be sent'));
        $gap = 'If an email cannot be sent at /contact, the person sees an error, but your app has already saved what they did. They may try again and do it twice.';
        $checked('app/Http/Controllers/ContactController.php:20');

        $this->assertSame(['kind' => 'gap', 'text' => $gap, 'decision' => ['change' => $change->uuid, 'finding' => AppFaults::SAVED_THEN_FAILED, 'accepted' => false]], $line());

        // Nothing of this kind was left behind, so there is nothing to want.
        $this->actingAs($owner)->post(route('feature-requests.accepted-findings.store', [$change, AppFaults::SENT_THEN_LOST]))->assertSessionHasErrors('kind');
        $this->actingAs($owner)->post(route('feature-requests.accepted-findings.store', [$change, AppFaults::SAVED_THEN_FAILED]))->assertRedirect();

        $this->assertSame(['saved_then_failed|POST /contact|mail App\Mail\ContactReceived'], $change->acceptedFindings()->pluck('identity')->all());
        $this->assertSame(['kind' => 'chosen', 'text' => "You said you want this. {$gap} If a later change does more of this, I will ask again.", 'decision' => ['change' => $change->uuid, 'finding' => AppFaults::SAVED_THEN_FAILED, 'accepted' => true]], $line());

        // The same thing on another line, in the next checks, is still what the owner wants.
        $checked('app/Http/Controllers/ContactController.php:24');
        $this->assertSame('chosen', $line()['kind']);

        $this->actingAs($owner)->delete(route('feature-requests.accepted-findings.destroy', [$change, AppFaults::SAVED_THEN_FAILED]))->assertRedirect();

        $this->assertSame(0, $change->acceptedFindings()->count());
        $this->assertSame('gap', $line()['kind']);
    }

    public function test_only_the_owner_decides_and_only_while_the_change_waits_for_them()
    {
        $change = $this->found();

        $this->actingAs(User::factory()->create())->post(route('feature-requests.accepted-findings.store', [$change, AppBoundaries::CHANGED_WHILE_AUTHORIZING]))->assertForbidden();
        $this->actingAs($change->project->owner)->post(route('feature-requests.accepted-findings.store', [$change, 'changed_while_booting']))->assertNotFound();
        $this->actingAs($change->project->owner)->post(route('feature-requests.accepted-findings.store', [$change, AppBoundaries::CHANGED_WHILE_RENDERING]))->assertSessionHasErrors('kind');
        $this->assertSame(0, $change->acceptedFindings()->count());

        // A kept change is part of the app: the line says what it does, with nothing to decide.
        $kept = $this->found(['commit_sha' => str_repeat('b', 40), 'accepted_at' => now()]);

        $this->actingAs($kept->project->owner)->post(route('feature-requests.accepted-findings.store', [$kept, AppBoundaries::CHANGED_WHILE_AUTHORIZING]))->assertSessionHasErrors('kind');
        $this->assertSame(['kind' => 'gap', 'text' => self::GAP], $this->line($kept));
    }
}
