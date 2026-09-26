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
