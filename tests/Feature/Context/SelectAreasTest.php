<?php

namespace Tests\Feature\Context;

use App\Actions\Context\CompileContext;
use App\Actions\Context\SelectAreas;
use App\Context\Capability;
use App\Context\ProjectContext;
use App\Enums\ContextMode;
use App\Models\FeatureRequest;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelectAreasTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_request_names_areas_by_their_words(): void
    {
        $chosen = app(SelectAreas::class)->handle($this->context(), FeatureRequest::factory()->create([
            'prompt' => 'When someone books a meeting room, send the invoice and a reminder.',
        ]));

        // Two words each ("room", "book"; "send", "invoice"): a tie goes by key.
        $this->assertSame(['billing' => SelectAreas::NAMED, 'room-bookings' => SelectAreas::NAMED], $chosen);

        $this->assertSame(['room-bookings' => SelectAreas::NAMED], app(SelectAreas::class)->handle($this->context(), FeatureRequest::factory()->create([
            'prompt' => 'Show booked rooms on a calendar.',
        ])), 'one area named, and "profile" is not');
    }

    public function test_what_the_owner_pointed_at_comes_before_the_words(): void
    {
        $parent = FeatureRequest::factory()->create(['steps' => [['key' => 'invoice', 'kind' => 'code', 'label' => 'Invoice', 'file' => 'app/Billing/Invoice.php', 'symbol' => 'Invoice', 'detail' => '']]]);
        $request = FeatureRequest::factory()->for($parent->project)->create([
            'parent_id' => $parent->id,
            'target_step' => 'invoice',
            'prompt' => 'Also for rooms.',
            'selection' => ['file' => 'resources/js/pages/Profile.vue', 'line' => 3, 'column' => 1, 'tag' => 'h1', 'text' => null, 'area' => 'whatever the page said'],
        ]);

        $chosen = app(SelectAreas::class)->handle($this->context(), $request);

        $this->assertSame(['billing' => SelectAreas::STEP, 'profile' => SelectAreas::PICKED], $chosen);
    }

    public function test_a_follow_up_keeps_the_areas_its_parent_was_given_and_read(): void
    {
        $parent = FeatureRequest::factory()->create();
        $run = Run::factory()->for($parent)->create(['context' => ['targets' => ['room-bookings']]]);
        $run->recordEvent('areas_read', ['areas' => ['billing' => 'app/Billing/Invoice.php']]);
        $request = FeatureRequest::factory()->for($parent->project)->create(['parent_id' => $parent->id, 'prompt' => 'Make it nicer.']);

        $chosen = app(SelectAreas::class)->handle($this->context(), $request);

        $this->assertSame(['room-bookings' => SelectAreas::FOLLOW_UP, 'billing' => SelectAreas::FOLLOW_UP], $chosen);
    }

    public function test_reading_another_areas_notes_or_code_counts_as_asking_for_it(): void
    {
        $read = app(SelectAreas::class)->read($this->context(), ['room-bookings'], [
            'app/Models/Room.php',
            '.product-notes/capabilities/profile.md',
            'app/Billing/Invoice.php',
            'app/Billing/Tax.php',
            'README.md',
        ]);

        $this->assertSame(['profile' => '.product-notes/capabilities/profile.md', 'billing' => 'app/Billing/Invoice.php'], $read);
    }

    public function test_each_chosen_area_lists_its_code_and_says_how_much_more_there_is(): void
    {
        $files = ['app/Models/Room.php', 'tests/Feature/RoomTest.php', 'app/Billing/Invoice.php'];

        for ($i = 1; $i <= 32; $i++) {
            $files[] = sprintf('app/Rooms/Part%02d.php', $i);
        }

        $pack = app(CompileContext::class)->handle($this->context(), ['room-bookings'], ContextMode::Selective, $files);

        $this->assertStringContainsString("Code in this area:\n- app/Models/Room.php\n- app/Rooms/Part01.php", $pack->text);
        $this->assertStringContainsString("- app/Rooms/Part29.php\n- and 3 more files", $pack->text);
        $this->assertStringNotContainsString('- tests/Feature/RoomTest.php', explode('Existing tests', $pack->text)[0], 'tests are listed apart');
        $this->assertStringNotContainsString('app/Billing/Invoice.php', $pack->text);
    }

    protected function context(): ProjectContext
    {
        return new ProjectContext(
            project: 'Meeting rooms for a company.',
            capabilities: [
                'room-bookings' => Capability::fromMarkdown('capabilities/room-bookings.md', "---\ncapability: room-bookings\nsummary: People book rooms.\npaths: [app/Models/Room.php, app/Rooms/*, tests/Feature/RoomTest.php]\n---\n# Room bookings\n"),
                'billing' => Capability::fromMarkdown('capabilities/billing.md', "---\ncapability: billing\npaths: [app/Billing/*]\nbehaviors:\n    - key: send-invoice\n      name: Send an invoice\n---\n# Billing\n"),
                'profile' => Capability::fromMarkdown('capabilities/profile.md', "---\ncapability: profile\npaths: [resources/js/pages/Profile.vue]\n---\n# Profile\n"),
            ],
        );
    }
}
