<?php

namespace Tests\Feature\VisualEditing;

use App\VisualEditing\SampleDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Anyone can try the designer on a sample page, without an app or an
 * account. Edits use the same rewriting as an app's and live only in the
 * visitor's session.
 */
class SampleDesignTest extends TestCase
{
    use RefreshDatabase;

    // The sample's "Cancel" button, where the sample writes it.
    protected const BUTTON = 'resources/views/bookings.blade.php:18:1';

    protected const CLASSES = 'rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground';

    public function test_the_sample_opens_for_anyone_and_stays_out_of_search_and_other_sites(): void
    {
        $this->get(route('sample-design.show'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
            ->assertInertia(fn (Assert $page) => $page
                ->component('try/Designer')
                ->where('preview.status', 'ready')
                ->where('edits', [])
                ->where('colors', fn ($colors) => collect($colors)->contains('name', 'primary')));
    }

    public function test_the_sample_page_stamps_each_part_where_it_is_written_and_carries_the_overlay(): void
    {
        $this->get(route('sample-design.app'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<button data-builder-source="'.self::BUTTON.'"', false)
            ->assertSee('<main data-builder-source="resources/views/bookings.blade.php:1:1"', false)
            ->assertSee('data-builder-origin=', false);
    }

    public function test_a_picked_part_is_described_as_in_an_app(): void
    {
        $this->get(route('sample-design.show', ['target' => self::BUTTON]), $this->partial('element'))
            ->assertOk()
            ->assertJsonPath('props.element.tag', 'button')
            ->assertJsonPath('props.element.editable', true)
            ->assertJsonPath('props.element.classes', self::CLASSES);
    }

    public function test_a_change_to_the_look_rewrites_the_classes_and_undo_and_redo_go_back_and_forth(): void
    {
        $this->get(route('sample-design.show'));

        $this->post(route('sample-design.edits.store'), [
            'target' => self::BUTTON,
            'expected' => self::CLASSES,
            'device' => 'base',
            'changes' => ['radius' => 'full'],
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString('rounded-full', $this->button());

        $edit = $this->edits()[0];
        $this->assertSame('look', $edit['kind']);

        $this->post(route('sample-design.edits.reversion.store', $edit['id']))->assertSessionHasNoErrors();
        $this->assertSame(self::CLASSES, $this->button());

        $this->delete(route('sample-design.edits.reversion.destroy', $edit['id']))->assertSessionHasNoErrors();
        $this->assertStringContainsString('rounded-full', $this->button());
    }

    public function test_a_look_while_focused_pressed_or_turned_off_is_written_with_its_state(): void
    {
        $this->get(route('sample-design.show'));

        $this->post(route('sample-design.edits.store'), [
            'target' => self::BUTTON,
            'expected' => self::CLASSES,
            'device' => 'base',
            'changes' => ['focus_border_color' => 'primary', 'active_background' => 'accent', 'disabled_opacity' => 50],
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::CLASSES.' focus-visible:border-primary active:bg-accent disabled:opacity-50', $this->button());

        $this->post(route('sample-design.edits.store'), [
            'target' => self::BUTTON,
            'expected' => $this->button(),
            'device' => 'base',
            'changes' => ['disabled_opacity' => 101],
        ])->assertSessionHasErrors();

        $this->assertStringContainsString('disabled:opacity-50', $this->button());
    }

    public function test_motion_and_new_words_change_the_sample(): void
    {
        $this->get(route('sample-design.show'));

        $this->post(route('sample-design.motions.store'), [
            'target' => self::BUTTON,
            'expected' => self::CLASSES,
            'motion' => ['entrance' => 'rise', 'speed' => 'normal', 'wait' => 'none', 'hover' => 'none', 'loop' => 'none'],
        ])->assertSessionHasNoErrors();

        $this->post(route('sample-design.texts.store'), [
            'target' => self::BUTTON,
            'before' => 'Cancel',
            'text' => 'Leave <b>class</b>',
        ])->assertSessionHasNoErrors();

        $page = $this->get(route('sample-design.app'))->getContent();

        $this->assertStringContainsString('starting:translate-y-4', (string) $page);
        // Words are words: markup a visitor types shows as text.
        $this->assertStringContainsString('Leave &lt;b&gt;class&lt;/b&gt;</button>', (string) $page);
    }

    public function test_a_part_or_value_the_sample_cannot_take_is_refused(): void
    {
        $this->get(route('sample-design.show'));

        $this->post(route('sample-design.edits.store'), [
            'target' => 'resources/views/other.blade.php:1:1',
            'expected' => '',
            'device' => 'base',
            'changes' => ['radius' => 'full'],
        ])->assertSessionHasErrors('edit');

        $this->post(route('sample-design.edits.store'), [
            'target' => self::BUTTON,
            'expected' => self::CLASSES,
            'device' => 'base',
            'changes' => ['nonsense' => 'x'],
        ])->assertSessionHasErrors('changes');

        $this->assertSame(self::CLASSES, $this->button());
    }

    public function test_each_visit_and_start_again_begin_from_the_sample_as_it_ships(): void
    {
        $this->get(route('sample-design.show'));
        $this->post(route('sample-design.edits.store'), [
            'target' => self::BUTTON,
            'expected' => self::CLASSES,
            'device' => 'base',
            'changes' => ['radius' => 'full'],
        ]);

        $this->delete(route('sample-design.destroy'))->assertRedirect();
        $this->assertSame(self::CLASSES, $this->button());
        $this->assertSame([], $this->edits());

        $this->post(route('sample-design.edits.store'), [
            'target' => self::BUTTON,
            'expected' => self::CLASSES,
            'device' => 'base',
            'changes' => ['radius' => 'full'],
        ]);
        $this->get(route('sample-design.show'));

        $this->assertSame(self::CLASSES, $this->button());
    }

    /**
     * @return array<string, string>
     */
    protected function partial(string $prop): array
    {
        $version = (string) $this->get(route('sample-design.show'))->viewData('page')['version'];

        return ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'try/Designer', 'X-Inertia-Partial-Data' => $prop];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function edits(): array
    {
        return app(SampleDesign::class)->edits();
    }

    protected function button(): string
    {
        /** @var TestResponse<Response> $response */
        $response = $this->get(route('sample-design.app'));

        preg_match('/<button data-builder-source="[^"]*" class="([^"]*)"/', (string) $response->getContent(), $match);

        return $match[1] ?? '';
    }
}
