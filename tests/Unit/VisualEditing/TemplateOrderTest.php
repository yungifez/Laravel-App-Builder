<?php

namespace Tests\Unit\VisualEditing;

use App\VisualEditing\TemplateElement;
use App\VisualEditing\TemplateOrder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TemplateOrderTest extends TestCase
{
    protected const TEMPLATE = <<<'VUE'
        <template>
            <!-- <p>not an element</p> -->
            <section class="grid gap-4">
                <h1 class="text-xl">Plans</h1>
                <p v-if="busy">Loading {{ count < 2 ? 'one' : 'many' }}</p>
                <p v-else>Pick one</p>
                <img src="/a.png">
                <Button
                    class="w-full"
                    @click="save"
                >Save</Button>
            </section>
            <footer><a href="/">Home</a> <a href="/help">Help</a></footer>
        </template>

        <script setup lang="ts">
        const a = 1 < 2;
        </script>
        VUE;

    public function test_it_reads_every_element_with_its_parent_and_extent()
    {
        $elements = TemplateOrder::elements(self::TEMPLATE);
        $tags = array_map(fn (array $element) => TemplateElement::atOffset(self::TEMPLATE, $element['start'])->tag, $elements);

        $this->assertSame(['template', 'section', 'h1', 'p', 'p', 'img', 'Button', 'footer', 'a', 'a'], $tags);
        $this->assertSame(1, $elements[2]['parent']);
        $this->assertSame(1, $elements[6]['parent']);
        $this->assertSame(0, $elements[7]['parent']);
        $this->assertStringEndsWith('>Save</Button>', substr(self::TEMPLATE, $elements[6]['start'], $elements[6]['end'] - $elements[6]['start']));
        $this->assertStringEndsWith('</section>', substr(self::TEMPLATE, $elements[1]['start'], $elements[1]['end'] - $elements[1]['start']));
    }

    public function test_a_component_named_like_a_void_element_keeps_its_contents()
    {
        $template = <<<'VUE'
            <template>
                <nav>
                    <link rel="icon" href="/a.png">
                    <Link href="/login">Log in</Link>
                    <Link href="/register">Register</Link>
                </nav>
            </template>
            VUE;

        $elements = TemplateOrder::elements($template);

        $this->assertSame('<Link href="/login">Log in</Link>', substr($template, $elements[3]['start'], $elements[3]['end'] - $elements[3]['start']));

        $moved = TemplateOrder::move($template, $elements[3]['start'], $elements[4]['start'], 'after');

        $this->assertStringContainsString(<<<'VUE'
                    <Link href="/register">Register</Link>
                    <Link href="/login">Log in</Link>
                </nav>
            VUE, $moved['contents']);
    }

    public function test_comments_stay_with_the_element_they_wrap()
    {
        $template = <<<'VUE'
            <template>
                <nav>
                    <a href="/login">Log in</a>
                    <!-- @registration -->
                    <a href="/register">Register</a>
                    <!-- @end-registration -->
                </nav>
            </template>
            VUE;

        $elements = TemplateOrder::elements($template);

        $after = TemplateOrder::move($template, $elements[2]['start'], $elements[3]['start'], 'after');

        $this->assertStringContainsString(<<<'VUE'
                <nav>
                    <!-- @registration -->
                    <a href="/register">Register</a>
                    <!-- @end-registration -->
                    <a href="/login">Log in</a>
                </nav>
            VUE, $after['contents']);

        $moved = TemplateOrder::move($template, $elements[3]['start'], $elements[2]['start'], 'before');

        $this->assertStringContainsString(<<<'VUE'
                <nav>
                    <!-- @registration -->
                    <a href="/register">Register</a>
                    <!-- @end-registration -->
                    <a href="/login">Log in</a>
                </nav>
            VUE, $moved['contents']);
        $this->assertSame([4, 9], TemplateOrder::position($moved['contents'], $moved['offset']));
    }

    public function test_an_element_moves_with_its_lines_before_or_after_a_sibling()
    {
        $button = $this->offset(8, 9);

        $moved = TemplateOrder::move(self::TEMPLATE, $button, $this->offset(4, 9), 'before');

        $this->assertStringContainsString(<<<'VUE'
                <section class="grid gap-4">
                    <Button
                        class="w-full"
                        @click="save"
                    >Save</Button>
                    <h1 class="text-xl">Plans</h1>
            VUE, $moved['contents']);
        $this->assertSame([4, 9], TemplateOrder::position($moved['contents'], $moved['offset']));

        $moved = TemplateOrder::move(self::TEMPLATE, $this->offset(4, 9), $this->offset(7, 9), 'after');

        $this->assertStringContainsString("<img src=\"/a.png\">\n        <h1 class=\"text-xl\">Plans</h1>\n        <Button", $moved['contents']);
        $this->assertSame([7, 9], TemplateOrder::position($moved['contents'], $moved['offset']));
    }

    public function test_elements_on_one_line_swap_in_place()
    {
        $moved = TemplateOrder::move(self::TEMPLATE, $this->offset(13, 34), $this->offset(13, 13), 'before');

        $this->assertStringContainsString('<footer><a href="/help">Help</a><a href="/">Home</a> </footer>', $moved['contents']);
    }

    public function test_moves_that_are_not_between_siblings_or_would_split_a_v_if_are_refused()
    {
        foreach ([
            fn () => TemplateOrder::move(self::TEMPLATE, $this->offset(4, 9), $this->offset(13, 13), 'before'),
            fn () => TemplateOrder::move(self::TEMPLATE, $this->offset(6, 9), $this->offset(4, 9), 'before'),
            fn () => TemplateOrder::move(self::TEMPLATE, $this->offset(5, 9), $this->offset(8, 9), 'after'),
            fn () => TemplateOrder::move(self::TEMPLATE, $this->offset(8, 9), $this->offset(5, 9), 'after'),
            fn () => TemplateOrder::move(self::TEMPLATE, $this->offset(4, 9), $this->offset(4, 9), 'after'),
        ] as $index => $move) {
            try {
                $move();
                $this->fail("Move {$index} was not refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_copy_goes_on_its_own_lines_right_after_the_element()
    {
        $copied = TemplateOrder::duplicate(self::TEMPLATE, $this->offset(8, 9));

        $this->assertStringContainsString(<<<'VUE'
                    >Save</Button>
                    <Button
                        class="w-full"
                        @click="save"
                    >Save</Button>
                </section>
            VUE, $copied['contents']);
        $this->assertSame([12, 9], TemplateOrder::position($copied['contents'], $copied['offset']));
    }

    public function test_a_copy_on_a_shared_line_keeps_the_space_before_it()
    {
        $copied = TemplateOrder::duplicate(self::TEMPLATE, $this->offset(13, 34));

        $this->assertStringContainsString('<a href="/">Home</a> <a href="/help">Help</a> <a href="/help">Help</a></footer>', $copied['contents']);
        $this->assertSame('<a href="/help">', substr($copied['contents'], $copied['offset'], 16));
    }

    public function test_a_new_part_goes_on_its_own_line_after_the_element_with_its_indent()
    {
        $added = TemplateOrder::insertAfter(self::TEMPLATE, $this->offset(8, 9), '<p>New text</p>');

        $this->assertStringContainsString(<<<'VUE'
                    >Save</Button>
                    <p>New text</p>
                </section>
            VUE, $added['contents']);
        $this->assertSame([12, 9], TemplateOrder::position($added['contents'], $added['offset']));
    }

    public function test_a_new_part_on_a_shared_line_goes_after_the_element_on_that_line()
    {
        $added = TemplateOrder::insertAfter(self::TEMPLATE, $this->offset(13, 34), '<p>New text</p>');

        $this->assertStringContainsString('<a href="/help">Help</a> <p>New text</p></footer>', $added['contents']);
        $this->assertSame('<p>New text</p>', substr($added['contents'], $added['offset'], 15));
    }

    public function test_a_removed_element_takes_its_lines_and_leaves_its_parent_selected()
    {
        $removed = TemplateOrder::remove(self::TEMPLATE, $this->offset(8, 9));

        $this->assertStringContainsString("<img src=\"/a.png\">\n    </section>", $removed['contents']);
        $this->assertStringNotContainsString('Save', $removed['contents']);
        $this->assertSame([3, 5], TemplateOrder::position($removed['contents'], $removed['offset']));
    }

    public function test_copies_and_removals_that_would_break_a_v_if_or_the_template_are_refused()
    {
        foreach ([
            fn () => TemplateOrder::duplicate(self::TEMPLATE, $this->offset(5, 9)),
            fn () => TemplateOrder::duplicate(self::TEMPLATE, $this->offset(6, 9)),
            fn () => TemplateOrder::remove(self::TEMPLATE, $this->offset(5, 9)),
            fn () => TemplateOrder::remove(self::TEMPLATE, $this->offset(6, 9)),
            fn () => TemplateOrder::remove(self::TEMPLATE, $this->offset(1, 1)),
        ] as $index => $change) {
            try {
                $change();
                $this->fail("Change {$index} was not refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    protected function offset(int $line, int $column): int
    {
        return (int) TemplateElement::offset(self::TEMPLATE, $line, $column);
    }

    public function test_a_blade_view_is_read_whole_and_parts_move_only_within_their_block()
    {
        $blade = <<<'BLADE'
            {{-- <p>not an element</p> --}}
            <div>
                <h1>{{ $title }}</h1>
                @foreach ($students as $student)
                    <p>{{ $student->name }}</p>
                    <span>{!! $student->note !!}</span>
                @endforeach
                @if ($students->isEmpty())
                    <em>None</em>
                @else
                    <strong>Some</strong>
                @endif
                <a href="mailto:office@school.test">Mail</a>
                <script>if (a < b) { document.write('<i>') }</script>
                @php $tag = '<b>'; @endphp
            </div>
            <footer>End</footer>
            BLADE;
        $at = fn (int $line, int $column) => (int) TemplateElement::offset($blade, $line, $column);

        // Both roots, every element, and nothing from comments, PHP or scripts.
        $this->assertSame(['div', 'h1', 'p', 'span', 'em', 'strong', 'a', 'script', 'footer'], array_map(fn (array $element) => preg_match('/^<([\w.:-]+)/', $element['head'], $tag) === 1 ? $tag[1] : null, TemplateOrder::elements($blade)));

        // Within one pass of the loop, parts change places.
        $moved = TemplateOrder::move($blade, $at(6, 9), $at(5, 9), 'before');
        $this->assertStringContainsString("@foreach (\$students as \$student)\n        <span>{!! \$student->note !!}</span>\n        <p>", $moved['contents']);

        // Nothing moves into or out of a list or a condition, or between its branches.
        foreach ([[$at(3, 5), $at(5, 9)], [$at(13, 5), $at(9, 9)], [$at(9, 9), $at(11, 9)]] as [$from, $to]) {
            try {
                TemplateOrder::move($blade, $from, $to, 'after');
                $this->fail('A part moved across a Blade block.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // Around a block, siblings of the same block still move.
        $this->assertStringContainsString("<a href=\"mailto:office@school.test\">Mail</a>\n    <h1>", TemplateOrder::move($blade, $at(3, 5), $at(13, 5), 'after')['contents']);
    }
}
