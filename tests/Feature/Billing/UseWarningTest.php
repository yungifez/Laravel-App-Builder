<?php

namespace Tests\Feature\Billing;

use App\Enums\RunStatus;
use App\Events\RunStatusChanged;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Notifications\UseRunningOut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Tests\TestCase;

class UseWarningTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.plans.free.monthly_usd' => 10, 'operations.operators' => ['ops@example.com']]);
        $this->travelTo(now()->setDate(2026, 10, 10));
        $this->owner = User::factory()->create(['created_at' => now()->setDate(2026, 8, 4)]);
        $this->project = Project::factory()->for($this->owner, 'owner')->create();
    }

    public function test_the_owner_hears_once_at_most_of_their_use_and_once_when_it_is_all_used()
    {
        $this->finishChangeCosting(5);
        $this->assertCount(0, $this->warnings());

        $this->finishChangeCosting(3.5);
        $this->assertCount(1, $this->warnings());
        $this->assertSame(80, $this->warnings()->first()->data['level']);
        $this->assertStringContainsString('4 November', $this->warnings()->first()->data['body']);

        // Another change under the full amount says nothing new.
        $this->finishChangeCosting(0.5);
        $this->assertCount(1, $this->warnings());

        $this->finishChangeCosting(2);
        $this->assertCount(2, $this->warnings());
        $this->assertSame(100, $this->warnings()->sortByDesc('data.level')->first()->data['level']);

        $this->finishChangeCosting(1);
        $this->assertCount(2, $this->warnings());

        // The bell opens their plan.
        $this->actingAs($this->owner)
            ->get(route('notifications.show', $this->warnings()->first()->id))
            ->assertRedirect(route('billing.edit'));
    }

    public function test_a_new_month_warns_again()
    {
        $this->finishChangeCosting(9);
        $this->assertCount(1, $this->warnings());

        $this->travelTo(now()->setDate(2026, 11, 6));
        $this->finishChangeCosting(9);
        $this->assertCount(2, $this->warnings());
    }

    public function test_an_operator_is_never_warned()
    {
        $this->owner->forceFill(['email' => 'ops@example.com'])->save();

        $this->finishChangeCosting(50);
        $this->assertCount(0, $this->warnings());
    }

    protected function finishChangeCosting(float $usd): void
    {
        $run = Run::factory()->for(FeatureRequest::factory()->for($this->project)->for($this->owner, 'user'))->create();
        $run->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'codex', 'cost_usd' => $usd]);

        event(new RunStatusChanged($run, RunStatus::Reviewing, RunStatus::Completed));
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    protected function warnings()
    {
        return $this->owner->notifications()->where('type', UseRunningOut::class)->get();
    }
}
