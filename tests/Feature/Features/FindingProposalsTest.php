<?php

namespace Tests\Feature\Features;

use App\Actions\Features\AnswerFindingProposals;
use App\Actions\Features\ProposeFindings;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Features\AppBoundaries;
use App\Jobs\ExecuteRun;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FindingProposalsTest extends TestCase
{
    use RefreshDatabase;

    protected const SAVE = 'changed_while_authorizing|App\Policies\PostPolicy::view|save';

    protected const SEND = 'changed_while_rendering|App\Http\Resources\PostResource::toArray|http';

    /**
     * A change whose policy was seen saving while the app checked who may
     * act, waiting with what the gate found.
     */
    protected function found(): Run
    {
        $change = FeatureRequest::factory()->generated()->create();

        Verification::factory()->for($change)->create([
            'status' => VerificationStatus::Passed,
            'results' => [],
            'evidence' => ['boundaries' => ['phased' => 10, 'unknown' => 0, 'existing' => 0, 'findings' => [
                ['kind' => AppBoundaries::CHANGED_WHILE_AUTHORIZING, 'route' => 'GET /posts', 'what' => 'insert refusals', 'at' => 'app/Policies/PostPolicy.php:12', 'in' => 'App\Policies\PostPolicy::view', 'test' => null],
            ]]],
        ]);

        return Run::factory()->for($change)->create([
            'status' => RunStatus::NeedsUserDecision,
            'stop_reason' => AnswerFindingProposals::STOP,
            'feedback' => ['reason' => 'review_findings', 'details' => [], 'gate' => app(ProposeFindings::class)->keyed($change, [
                ['kind' => AppBoundaries::CHANGED_WHILE_AUTHORIZING, 'identity' => self::SAVE, 'text' => 'GET /posts saved while checking.'],
                ['kind' => AppBoundaries::CHANGED_WHILE_RENDERING, 'identity' => self::SEND, 'text' => 'GET /posts called out while building.'],
            ])],
        ]);
    }

    public function test_the_agent_asks_to_keep_a_finding_by_its_key_and_only_once()
    {
        $run = $this->found();

        $this->assertSame(['B1: GET /posts saved while checking.', 'B2: GET /posts called out while building.'], array_column($run->feedback['gate'], 'text'));

        $proposed = app(ProposeFindings::class)->fromReply($run, "Moved the call.\n\nKEEP B1: The owner asked for every refused visit to be logged.\nKEEP B9: There is no such problem.");

        $this->assertCount(1, $proposed);
        $this->assertSame([self::SAVE, 'The owner asked for every refused visit to be logged.', $run->id], [$proposed[0]->identity, $proposed[0]->reason, $proposed[0]->run_id]);
        $this->assertSame([self::SAVE], app(ProposeFindings::class)->pending($run->featureRequest));

        // Asking again changes nothing.
        $this->assertSame([], app(ProposeFindings::class)->fromReply($run, 'KEEP B1: Really.'));
    }

    public function test_the_owner_agrees_and_the_change_goes_back_to_its_review()
    {
        Queue::fake([ExecuteRun::class]);
        $run = $this->found();
        app(ProposeFindings::class)->fromReply($run, 'KEEP B1: The owner asked for every refused visit to be logged.');
        $owner = $run->featureRequest->project->owner;

        app(AnswerFindingProposals::class)->handle($run->featureRequest, AppBoundaries::CHANGED_WHILE_AUTHORIZING, true, $owner);

        $proposal = $run->featureRequest->findingProposals()->sole();
        $this->assertSame([true, $owner->id], [$proposal->agreed, $proposal->answered_by]);
        $this->assertSame([self::SAVE], $run->featureRequest->acceptedFindings()->pluck('identity')->all());
        $this->assertSame(RunStatus::Reviewing, $run->refresh()->status);
        Queue::assertPushed(ExecuteRun::class);
    }

    public function test_after_the_owner_says_no_the_finding_must_be_fixed()
    {
        Queue::fake([ExecuteRun::class]);
        $run = $this->found();
        app(ProposeFindings::class)->fromReply($run, 'KEEP B1: The owner asked for every refused visit to be logged.');

        app(AnswerFindingProposals::class)->handle($run->featureRequest, AppBoundaries::CHANGED_WHILE_AUTHORIZING, false, $run->featureRequest->project->owner);

        $this->assertSame(0, $run->featureRequest->acceptedFindings()->count());
        $this->assertSame(RunStatus::Reviewing, $run->refresh()->status);

        // The next pass gets no key for it, so it cannot ask again.
        $gate = app(ProposeFindings::class)->keyed($run->featureRequest, [
            ['kind' => AppBoundaries::CHANGED_WHILE_AUTHORIZING, 'identity' => self::SAVE, 'text' => 'GET /posts saved while checking.'],
        ]);
        $this->assertSame([null, 'GET /posts saved while checking. You asked to keep this before, and the owner said it must be fixed.'], [$gate[0]['key'], $gate[0]['text']]);

        $this->expectException(ValidationException::class);
        app(AnswerFindingProposals::class)->handle($run->featureRequest, AppBoundaries::CHANGED_WHILE_AUTHORIZING, true, $run->featureRequest->project->owner);
    }
}
