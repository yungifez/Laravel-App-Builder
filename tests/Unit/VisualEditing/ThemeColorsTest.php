<?php

namespace Tests\Unit\VisualEditing;

use App\VisualEditing\ThemeColors;
use PHPUnit\Framework\TestCase;

class ThemeColorsTest extends TestCase
{
    public function test_a_shadcn_theme_is_named_by_its_classes_and_held_by_its_own_variables()
    {
        // A path with "/*" in it is not a comment.
        $css = <<<'CSS'
        @source '../../storage/framework/views/*.php';

        @theme inline {
            --radius-lg: var(--radius);
            --color-primary: var(--primary);
            --color-sidebar: var(--sidebar-background);
        }

        /* The light look. */
        :root {
            --primary: hsl(0 0% 9%);
            --sidebar-background: oklch(0.985 0 0);
            --radius: 0.5rem;
        }
        CSS;

        $this->assertSame([
            ['name' => 'primary', 'variable' => 'primary', 'classes' => true],
            ['name' => 'sidebar', 'variable' => 'sidebar-background', 'classes' => true],
        ], ThemeColors::discover([$css]));
    }

    public function test_any_other_design_system_is_found_by_what_it_writes()
    {
        $tailwind = <<<'CSS'
        @import 'tailwindcss';

        @theme {
            --color-*: initial;
            --color-brand-500: #7c3aed;
            --color-ink: rgb(10 10 10);
            --font-display: 'Inter', sans-serif;
        }
        CSS;
        $plain = <<<'CSS'
        /* Our own tokens { not Tailwind } */
        html {
            --surface: #fafafa;
            --gap: 12px;
        }
        CSS;

        $this->assertSame([
            ['name' => 'brand-500', 'variable' => 'color-brand-500', 'classes' => true],
            ['name' => 'ink', 'variable' => 'color-ink', 'classes' => true],
            ['name' => 'surface', 'variable' => 'surface', 'classes' => false],
        ], ThemeColors::discover([$tailwind, $plain]));
    }

    public function test_a_value_that_is_not_a_colour_is_not_offered_or_written()
    {
        // A bare "hsl" triplet is wrapped in hsl() where the app uses it, so
        // a colour code written over it would break every part drawn in it.
        $css = ":root {\n    --primary: 222 47% 11%;\n    --ring: var(--primary);\n}\n";

        $this->assertSame([], ThemeColors::discover([$css]));
        $this->assertNull(ThemeColors::write($css, 'light', 'primary', '#ff0000'));
        $this->assertNull(ThemeColors::write($css, 'light', 'ring', '#ff0000'));
    }

    public function test_each_look_writes_its_own_colour()
    {
        $css = <<<'CSS'
        @theme {
            --color-brand: #7c3aed;
        }

        .dark {
            --color-brand: #a78bfa;
        }
        CSS;

        $this->assertSame(
            str_replace('#a78bfa', '#ffffff', $css),
            ThemeColors::write($css, 'dark', 'color-brand', '#ffffff'),
        );
        $this->assertSame(
            str_replace('#7c3aed', '#000000', $css),
            ThemeColors::write($css, 'light', 'color-brand', '#000000'),
        );
    }
}
