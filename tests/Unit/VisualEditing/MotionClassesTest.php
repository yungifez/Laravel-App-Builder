<?php

namespace Tests\Unit\VisualEditing;

use App\VisualEditing\MotionClasses;
use InvalidArgumentException;
use Tests\TestCase;

class MotionClassesTest extends TestCase
{
    protected const STILL = ['entrance' => 'none', 'speed' => 'normal', 'wait' => 'none', 'hover' => 'none', 'loop' => 'none'];

    public function test_it_reads_the_laravel_welcome_logo_as_a_slow_slide_after_a_wait()
    {
        $motion = MotionClasses::read('opacity-100 transition-all delay-300 duration-750 starting:opacity-0 motion-safe:starting:-translate-x-[51px]');

        $this->assertSame('slide', $motion['entrance']);
        $this->assertSame('slow', $motion['speed']);
        $this->assertSame('long', $motion['wait']);
        $this->assertTrue($motion['moves']);
        $this->assertFalse($motion['custom']);
        $this->assertSame('Slides in from the left, slowly, after a wait.', $motion['words']);
    }

    public function test_a_still_part_does_not_move()
    {
        $motion = MotionClasses::read('flex gap-4 transition-colors duration-200 hover:bg-muted');

        $this->assertFalse($motion['moves']);
        $this->assertNull($motion['words']);
        $this->assertSame('none', $motion['entrance']);
    }

    public function test_it_reads_pointer_and_lasting_motion()
    {
        $motion = MotionClasses::read('motion-safe:hover:-translate-y-0.5 hover:shadow-md motion-safe:animate-pulse');

        $this->assertSame('lift', $motion['hover']);
        $this->assertSame('pulse', $motion['loop']);
        $this->assertSame('Lifts when the pointer is on it. Keeps pulsing.', $motion['words']);
    }

    public function test_motion_beyond_the_choices_is_custom()
    {
        $this->assertTrue(MotionClasses::read('animate-[wiggle_1s_ease-in-out_infinite]')['custom']);
        $this->assertTrue(MotionClasses::read('md:starting:opacity-0')['custom']);
        $this->assertTrue(MotionClasses::read('starting:opacity-0 starting:scale-50 starting:rotate-12')['custom']);
        $this->assertSame('It also moves in a way of its own.', MotionClasses::read('animate-[wiggle_1s]')['words']);
    }

    public function test_it_writes_an_entrance_in_place_of_the_old_one_and_keeps_other_classes()
    {
        $classes = MotionClasses::write(
            'mb-4 opacity-100 transition-all delay-300 duration-750 starting:opacity-0 motion-safe:starting:-translate-x-[51px]',
            ['entrance' => 'rise', 'speed' => 'quick', 'wait' => 'none', 'hover' => 'none', 'loop' => 'none'],
        );

        $this->assertSame('mb-4 opacity-100 transition-all duration-300 ease-out starting:opacity-0 motion-safe:starting:translate-y-4', $classes);
        $this->assertSame('rise', MotionClasses::read($classes)['entrance']);
        $this->assertSame('quick', MotionClasses::read($classes)['speed']);
    }

    public function test_every_choice_reads_back_as_written()
    {
        foreach (MotionClasses::ENTRANCES as $entrance) {
            foreach (MotionClasses::HOVERS as $hover) {
                foreach (MotionClasses::LOOPS as $loop) {
                    $motion = ['entrance' => $entrance, 'speed' => 'slow', 'wait' => 'short', 'hover' => $hover, 'loop' => $loop];
                    $read = MotionClasses::read(MotionClasses::write('p-4', $motion));

                    $this->assertSame([$entrance, $hover, $loop], [$read['entrance'], $read['hover'], $read['loop']]);
                    $this->assertFalse($read['custom']);
                }
            }
        }
    }

    public function test_stopping_all_motion_keeps_a_colour_easing()
    {
        $this->assertSame(
            'p-4 transition-colors duration-200',
            MotionClasses::write('p-4 transition-colors duration-200 motion-safe:animate-spin', self::STILL),
        );
        $this->assertSame('p-4', MotionClasses::write('p-4 transition-all duration-500 ease-out starting:opacity-0', self::STILL));
    }

    public function test_it_suggests_motion_that_suits_the_part()
    {
        $this->assertSame(['hover' => 'lift'], MotionClasses::suggested('button'));
        $this->assertSame(['entrance' => 'rise'], MotionClasses::suggested('h1'));
        $this->assertSame([], MotionClasses::suggested('span'));
    }

    public function test_it_refuses_a_choice_it_does_not_offer()
    {
        $this->expectException(InvalidArgumentException::class);

        MotionClasses::write('p-4', [...self::STILL, 'loop' => 'wobble']);
    }
}
