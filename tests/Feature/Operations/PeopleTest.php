<?php

namespace Tests\Feature\Operations;

use App\Models\ContactMessage;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PeopleTest extends TestCase
{
    use RefreshDatabase;

    protected User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['operations.operators' => ['ops@example.com'], 'billing.plans.free.monthly_usd' => 5]);
        $this->operator = User::factory()->create(['email' => 'ops@example.com', 'name' => 'Ops']);
    }

    public function test_only_operators_see_people_and_messages()
    {
        $owner = User::factory()->create();
        $message = ContactMessage::factory()->create();

        foreach ([route('operations.people.index'), route('operations.people.show', $owner), route('operations.messages.index')] as $url) {
            $this->actingAs($owner)->get($url)->assertForbidden();
        }

        $this->actingAs($owner)->put(route('operations.messages.update', $message), ['handled' => true])->assertForbidden();
    }

    public function test_an_operator_finds_people_by_name_or_email_with_their_plan_and_use()
    {
        $ada = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
        User::factory()->create(['name' => 'Grace Hopper']);
        $project = Project::factory()->for($ada, 'owner')->create();
        Run::factory()->for(FeatureRequest::factory()->for($project))->create()
            ->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'codex', 'cost_usd' => 4]);

        $this->actingAs($this->operator)
            ->get(route('operations.people.index', ['search' => 'ada@']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/People')
                ->where('totals.people', 3)
                ->has('people.data', 1)
                ->where('people.data.0.name', 'Ada Lovelace')
                ->where('people.data.0.apps', 1)
                ->where('people.data.0.plan', 'Free')
                ->where('people.data.0.percent', 80));

        $this->actingAs($this->operator)
            ->get(route('operations.people.index', ['search' => 'Ops']))
            ->assertInertia(fn (Assert $page) => $page->where('people.data.0.percent', null));
    }

    public function test_a_person_shows_their_apps_and_what_they_wrote()
    {
        $ada = User::factory()->create(['email' => 'ada@example.com']);
        $project = Project::factory()->for($ada, 'owner')->create(['name' => 'Bookings']);
        FeatureRequest::factory()->for($project)->count(2)->create();
        ContactMessage::factory()->create(['email' => 'ada@example.com', 'message' => 'How do plans work?']);
        ContactMessage::factory()->create(['email' => 'someone@example.com']);

        $this->actingAs($this->operator)
            ->get(route('operations.people.show', $ada))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/Person')
                ->where('person.plan', 'Free')
                ->where('apps.0.name', 'Bookings')
                ->where('apps.0.changes', 2)
                ->has('messages', 1)
                ->where('messages.0.message', 'How do plans work?'));
    }

    public function test_messages_list_the_unhandled_first_and_can_be_marked_handled()
    {
        $handled = ContactMessage::factory()->create(['handled_at' => now()]);
        $waiting = ContactMessage::factory()->create();

        $this->actingAs($this->operator)
            ->get(route('operations.messages.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/Messages')
                ->where('messages.data.0.id', $waiting->id)
                ->where('messages.data.1.id', $handled->id));

        $this->actingAs($this->operator)
            ->get(route('operations.attention'))
            ->assertInertia(fn (Assert $page) => $page->where('newMessages', 1));

        $this->actingAs($this->operator)->put(route('operations.messages.update', $waiting), ['handled' => true])->assertRedirect();
        $this->actingAs($this->operator)->put(route('operations.messages.update', $handled), ['handled' => false])->assertRedirect();

        $this->assertNotNull($waiting->refresh()->handled_at);
        $this->assertNull($handled->refresh()->handled_at);
    }
}
