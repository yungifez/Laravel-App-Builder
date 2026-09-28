<?php

namespace Tests\Unit\VisualEditing;

use App\VisualEditing\TailwindClasses;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TailwindClassesTest extends TestCase
{
    public function test_it_reads_properties_per_device_in_pixels_and_words()
    {
        $values = TailwindClasses::read('flex flex-col items-center gap-4 p-3.75 md:flex-row lg:grid-cols-3 w-1/2 rounded-lg border hover:p-8 text-sm');

        $this->assertSame([
            'layout' => 'flex',
            'direction' => 'down',
            'align' => 'center',
            'gap' => 16,
            'width' => '50%',
            'radius' => 'lg',
            'border' => 1,
            'text_size' => 'sm',
            'padding_x' => 15,
            'padding_y' => 15,
        ], $values['base']);
        $this->assertSame(['direction' => 'across'], $values['md']);
        $this->assertSame(['columns' => 3], $values['lg']);
    }

    public function test_sides_that_differ_read_as_mixed()
    {
        $values = TailwindClasses::read('pt-2 pb-4 px-[15px] -mx-2');

        $this->assertSame(['padding_x' => 15, 'padding_y' => 'mixed', 'margin_x' => -8], $values['base']);
    }

    public function test_effective_values_come_from_the_nearest_smaller_device()
    {
        $effective = TailwindClasses::effective('p-4 md:p-6 flex');

        $this->assertSame(['value' => 'flex', 'from' => 'base'], $effective['lg']['layout']);
        $this->assertSame(['value' => 16, 'from' => 'base'], $effective['base']['padding_x']);
        $this->assertSame(['value' => 24, 'from' => 'md'], $effective['lg']['padding_x']);
    }

    public function test_spacing_uses_theme_relative_utilities()
    {
        $this->assertSame('3.75', TailwindClasses::spacing(15));
        $this->assertSame('4', TailwindClasses::spacing(16));
        $this->assertSame('px', TailwindClasses::spacing(1));
        $this->assertSame('0', TailwindClasses::spacing(0));
        $this->assertSame('[12.5px]', TailwindClasses::spacing(12.5));
    }

    public function test_writing_replaces_only_the_property_at_that_device_in_place()
    {
        $this->assertSame(
            'flex gap-6 items-center md:gap-2 hover:gap-8',
            TailwindClasses::write('flex gap-4 items-center md:gap-2 hover:gap-8', 'base', ['gap' => 24]),
        );
        $this->assertSame(
            'flex flex-col md:flex-row lg:grid-cols-4',
            TailwindClasses::write('flex flex-col md:flex-row lg:grid-cols-3', 'lg', ['columns' => 4]),
        );
        $this->assertSame(
            'rounded-md text-sm md:w-3/4',
            TailwindClasses::write('rounded-md text-sm', 'md', ['width' => '75%']),
        );
    }

    public function test_padding_is_written_in_the_shortest_form()
    {
        $this->assertSame('p-3.75', TailwindClasses::write('px-4 py-3.75', 'base', ['padding_x' => 15]));
        $this->assertSame('px-2 py-4', TailwindClasses::write('p-4', 'base', ['padding_x' => 8]));
        $this->assertSame('text-sm px-2 pt-1 pb-3', TailwindClasses::write('text-sm pt-1 pb-3', 'base', ['padding_x' => 8]));
        $this->assertSame('mx-auto my-4', TailwindClasses::write('m-4', 'base', ['margin_x' => 'auto']));
        $this->assertSame('text-sm', TailwindClasses::write('p-4 text-sm', 'base', ['padding_x' => null, 'padding_y' => null]));
    }

    public function test_keywords_widths_borders_and_corners_are_written_as_utilities()
    {
        $this->assertSame('grid', TailwindClasses::write('flex', 'base', ['layout' => 'grid']));
        $this->assertSame('w-full', TailwindClasses::write('w-1/2', 'base', ['width' => '100%']));
        $this->assertSame('w-[73%]', TailwindClasses::write('', 'base', ['width' => '73%']));
        $this->assertSame('w-60', TailwindClasses::write('', 'base', ['width' => 240]));
        $this->assertSame('border-2', TailwindClasses::write('border', 'base', ['border' => 2]));
        $this->assertSame('rounded-sm', TailwindClasses::write('rounded', 'base', ['radius' => 'sm']));
        $this->assertSame('flex-wrap justify-between', TailwindClasses::write('flex-nowrap', 'base', ['wrap' => 'wrap', 'justify' => 'between']));
    }

    public function test_corners_rounded_one_side_at_a_time_read_as_mixed_and_are_replaced()
    {
        $classes = 'absolute rounded-t-lg rounded-sm lg:rounded-t-none lg:rounded-r-lg';

        $this->assertSame(['radius' => 'mixed'], TailwindClasses::read($classes)['base']);
        $this->assertSame(['radius' => 'mixed'], TailwindClasses::read($classes)['lg']);
        $this->assertSame('lg', TailwindClasses::read('rounded-lg')['base']['radius']);
        $this->assertSame('absolute rounded-full lg:rounded-t-none lg:rounded-r-lg', TailwindClasses::write($classes, 'base', ['radius' => 'full']));
    }

    public function test_text_shadow_and_max_width_are_read_and_written_as_utilities()
    {
        $this->assertSame(
            ['max_width' => '2xl', 'shadow' => 'sm', 'text_size' => 'lg', 'text_weight' => 'semibold', 'text_align' => 'left'],
            TailwindClasses::read('max-w-2xl shadow text-lg font-semibold text-left')['base'],
        );
        $this->assertSame(
            'max-w-prose shadow-md text-xl font-bold text-left',
            TailwindClasses::write('max-w-2xl shadow text-lg font-semibold text-left', 'base', [
                'max_width' => 'prose', 'shadow' => 'md', 'text_size' => 'xl', 'text_weight' => 'bold',
            ]),
        );
    }

    public function test_slant_lines_on_words_and_line_spacing_are_read_and_written_as_utilities()
    {
        $this->assertSame(
            ['font_style' => 'italic', 'text_decoration' => 'underline', 'line_height' => 'relaxed'],
            TailwindClasses::read('italic underline underline-offset-4 leading-relaxed')['base'],
        );
        $this->assertSame(
            ['font_style' => 'normal', 'text_decoration' => 'none', 'line_height' => 'none'],
            TailwindClasses::read('md:not-italic md:no-underline md:leading-none')['md'],
        );
        $this->assertSame(
            'not-italic line-through underline-offset-4 leading-tight',
            TailwindClasses::write('italic underline underline-offset-4 leading-relaxed', 'base', [
                'font_style' => 'normal', 'text_decoration' => 'line-through', 'line_height' => 'tight',
            ]),
        );
        $this->assertSame('underline-offset-4', TailwindClasses::write('underline underline-offset-4', 'base', ['text_decoration' => null]));
    }

    public function test_letter_spacing_is_read_and_written_and_spacing_off_the_steps_is_replaced()
    {
        $this->assertSame(['letter_spacing' => 'wide'], TailwindClasses::read('tracking-wide')['base']);
        $this->assertSame(
            ['line_height' => 'custom', 'letter_spacing' => 'custom'],
            TailwindClasses::read('leading-6 tracking-[0.2em]')['base'],
        );
        $this->assertSame(['line_height' => 'custom'], TailwindClasses::read('md:leading-[1.1]')['md']);

        // Choosing a step replaces the part's own spacing, so the two never fight.
        $this->assertSame(
            'text-sm leading-loose tracking-tight',
            TailwindClasses::write('text-sm leading-6 tracking-[0.2em]', 'base', ['line_height' => 'loose', 'letter_spacing' => 'tight']),
        );
    }

    public function test_height_turn_move_and_see_through_are_read_in_pixels_degrees_and_percent()
    {
        $this->assertSame(
            ['height' => 160, 'rotate' => -12, 'translate_x' => '-50%', 'translate_y' => 8, 'opacity' => 75],
            TailwindClasses::read('h-40 -rotate-12 -translate-x-1/2 translate-y-2 opacity-75')['base'],
        );
        $this->assertSame(
            ['height' => 'screen', 'rotate' => 7.5, 'translate_x' => -13, 'opacity' => 37.5],
            TailwindClasses::read('md:h-screen md:rotate-[7.5deg] md:-translate-x-[13px] md:opacity-[37.5%]')['md'],
        );

        // Classes that only share a prefix are not these properties.
        $this->assertSame([], TailwindClasses::read('rotate-x-45 gap-x-4 h-lh')['base']);
    }

    public function test_values_off_the_scale_are_written_as_arbitrary_values()
    {
        $this->assertSame('h-40 rotate-45 -translate-y-4 opacity-50', TailwindClasses::write('', 'base', [
            'height' => 160, 'rotate' => 45, 'translate_y' => -16, 'opacity' => 50,
        ]));
        $this->assertSame('-rotate-[7.5deg] translate-x-[12.5px] opacity-[37.5%] h-[33%]', TailwindClasses::write('', 'base', [
            'rotate' => -7.5, 'translate_x' => 12.5, 'opacity' => 37.5, 'height' => '33%',
        ]));
        $this->assertSame('translate-x-4 md:rotate-0', TailwindClasses::write('translate-x-4 md:rotate-3', 'md', ['rotate' => 0]));
        $this->assertSame('-translate-x-1/2 md:rotate-0', TailwindClasses::write('translate-x-4 md:rotate-0', 'base', ['translate_x' => '-50%']));
        $this->assertSame('text-center', TailwindClasses::write('text-left', 'base', ['text_align' => 'center']));
    }

    public function test_measures_refuse_values_they_cannot_take()
    {
        foreach ([
            ['opacity' => 120],
            ['opacity' => -5],
            ['height' => -16],
            ['rotate' => 'lots'],
            ['text_align' => 'middle'],
        ] as $changes) {
            try {
                TailwindClasses::write('', 'base', $changes);
                $this->fail('The write was not refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * The colours of a shadcn theme, as ThemeColors finds them.
     */
    private const SHADCN = ['background', 'foreground', 'card', 'muted', 'muted-foreground', 'primary', 'primary-foreground', 'accent', 'destructive', 'border', 'input'];

    public function test_colours_are_the_apps_own_and_any_other_colour_reads_as_custom()
    {
        $this->assertSame(
            ['text_color' => 'muted-foreground', 'background' => 'card'],
            TailwindClasses::read('text-muted-foreground bg-card bg-cover', self::SHADCN)['base'],
        );
        $this->assertSame(['text_color' => 'custom', 'background' => 'custom'], TailwindClasses::read('text-gray-600 bg-primary/10', self::SHADCN)['base']);
        $this->assertSame(['background' => 'custom'], TailwindClasses::read('md:bg-[#ff0000]', self::SHADCN)['md']);

        // Choosing a token replaces the custom colour, so the two never fight.
        $this->assertSame('bg-muted text-center', TailwindClasses::write('bg-primary/10 text-center', 'base', ['background' => 'muted'], self::SHADCN));

        $this->expectException(InvalidArgumentException::class);
        TailwindClasses::write('', 'base', ['text_color' => 'red-500'], self::SHADCN);
    }

    public function test_a_border_colour_is_a_theme_token_apart_from_the_border_width()
    {
        $this->assertSame(['border' => 1, 'border_color' => 'input'], TailwindClasses::read('border border-input', self::SHADCN)['base']);
        $this->assertSame(['border' => 2, 'border_color' => 'custom'], TailwindClasses::read('border-2 border-black', self::SHADCN)['base']);
        $this->assertSame(['border_color' => 'custom'], TailwindClasses::read('md:border-[#eeeeec]', self::SHADCN)['md']);

        // Choosing a colour keeps the width, and replaces the custom colour.
        $this->assertSame('border border-primary', TailwindClasses::write('border border-black', 'base', ['border_color' => 'primary'], self::SHADCN));
        $this->assertSame('border-4 border-primary', TailwindClasses::write('border border-primary', 'base', ['border' => 4], self::SHADCN));
    }

    public function test_a_theme_colour_takes_away_the_parts_own_dark_mode_colour()
    {
        // The dark-mode colour would hide the chosen one; hover and other
        // properties stay.
        $this->assertSame(
            'border border-black dark:bg-[#eeeeec] dark:hover:border-white lg:border-destructive',
            TailwindClasses::write('border border-black dark:border-[#eeeeec] dark:bg-[#eeeeec] dark:hover:border-white', 'lg', ['border_color' => 'destructive'], self::SHADCN),
        );
        $this->assertSame('text-primary', TailwindClasses::write('text-white dark:text-[#1C1C1A]', 'base', ['text_color' => 'primary'], self::SHADCN));

        // Removing a colour leaves the dark-mode one alone.
        $this->assertSame('dark:bg-black', TailwindClasses::write('bg-muted dark:bg-black', 'base', ['background' => null], self::SHADCN));
    }

    public function test_colours_follow_whatever_the_app_names_them()
    {
        $colors = ['brand-500', 'ink'];

        $this->assertSame(
            ['text_color' => 'ink', 'background' => 'brand-500'],
            TailwindClasses::read('text-ink bg-brand-500 bg-cover', $colors)['base'],
        );
        // Without that design system, `bg-primary` is not a colour it knows.
        $this->assertSame(['background' => 'custom'], TailwindClasses::read('bg-brand-500/20 bg-primary', $colors)['base']);
        $this->assertSame('text-lg text-ink', TailwindClasses::write('text-lg text-white', 'base', ['text_color' => 'ink'], $colors));

        $this->expectException(InvalidArgumentException::class);
        TailwindClasses::write('', 'base', ['background' => 'primary'], $colors);
    }

    public function test_it_refuses_unknown_devices_properties_and_values()
    {
        foreach ([
            fn () => TailwindClasses::write('', 'sm', ['gap' => 4]),
            fn () => TailwindClasses::write('', 'base', ['color' => 'red']),
            fn () => TailwindClasses::write('', 'base', ['direction' => 'sideways']),
            fn () => TailwindClasses::write('', 'base', ['columns' => 40]),
        ] as $write) {
            try {
                $write();
                $this->fail('The write was not refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
