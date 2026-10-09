<?php

namespace Tests\Unit\Projects;

use App\Actions\Projects\ApplyDesignDirection;
use App\Projects\DesignDirection;
use PHPUnit\Framework\TestCase;

class ApplyDesignDirectionTest extends TestCase
{
    protected const CSS = <<<'CSS'
        @theme inline {
            --font-sans: Instrument Sans, ui-sans-serif, system-ui, sans-serif;
        }

        :root {
            --background: hsl(0 0% 100%);
            --primary: hsl(0 0% 9%);
            --radius: 0.5rem;
        }

        .dark {
            --background: hsl(0 0% 3.9%);
            --primary: hsl(0 0% 98%);
        }

        @layer base {
            body { font-family: 'Instrument Sans', sans-serif; }
        }
        CSS;

    public function test_tokens_are_replaced_in_place_and_new_ones_are_added_to_the_same_rule()
    {
        $css = ApplyDesignDirection::withTokens(self::CSS, ':root', ['primary' => 'hsl(174 62% 24%)', 'radius' => '0.75rem', 'accent' => 'hsl(174 30% 92%)']);

        $this->assertStringContainsString("--primary: hsl(174 62% 24%);\n    --radius: 0.75rem;\n    --accent: hsl(174 30% 92%);\n}", $css);
        $this->assertStringContainsString('--background: hsl(0 0% 100%);', $css);
        // Dark mode keeps its own values.
        $this->assertStringContainsString("--primary: hsl(0 0% 98%);\n}", $css);
    }

    public function test_dark_tokens_change_only_the_dark_rule()
    {
        $css = ApplyDesignDirection::withTokens(self::CSS, '.dark', ['primary' => 'hsl(172 50% 52%)']);

        $this->assertStringContainsString('--primary: hsl(0 0% 9%);', $css);
        $this->assertStringContainsString('--primary: hsl(172 50% 52%);', $css);
    }

    public function test_a_stylesheet_without_the_rule_is_left_alone()
    {
        $this->assertSame('body {}', ApplyDesignDirection::withTokens('body {}', ':root', ['primary' => 'red']));
    }

    public function test_the_font_goes_first_in_every_sans_family_and_keeps_its_fallbacks()
    {
        $css = ApplyDesignDirection::withFont("--font-sans: Instrument Sans, ui-sans-serif;\n--font-sans: 'Figtree', sans-serif;", 'Nunito');

        $this->assertSame("--font-sans: 'Nunito', ui-sans-serif;\n--font-sans: 'Nunito', sans-serif;", $css);
    }

    public function test_the_build_loads_the_font_with_its_weights()
    {
        $build = "fonts: [bunny('Instrument Sans', { weights: [400, 500, 600] })],";

        $this->assertSame(
            "fonts: [bunny('Nunito', { weights: [400, 600, 700] })],",
            ApplyDesignDirection::withFontLoaded($build, $this->direction(font: 'Nunito', weights: [400, 600, 700])),
        );
    }

    /**
     * @param  list<int>  $weights
     */
    protected function direction(string $font = 'Nunito', array $weights = [400]): DesignDirection
    {
        return new DesignDirection('friendly', 'Friendly', 'Round and bright.', [], [], $font, $weights, '1rem', [], [], 1);
    }
}
