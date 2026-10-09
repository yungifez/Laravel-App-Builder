<?php

namespace Tests\Unit\VisualEditing;

use App\VisualEditing\TailwindTheme;
use PHPUnit\Framework\TestCase;

class TailwindThemeTest extends TestCase
{
    public function test_it_reads_the_names_each_scale_has_in_the_theme()
    {
        $css = <<<'CSS'
            @import "tailwindcss";

            /* --text-commented: 1rem; */
            @theme inline {
                --color-*: initial;
                --color-primary: var(--primary);
                --text-hero: 4.5rem;
                --text-hero--line-height: 1.1;
                --font-display: "Satoshi", sans-serif;
                --font-weight-heavy: 850;
                --shadow-soft: 0 1px 2px black;
                --inset-shadow-deep: inset 0 2px 4px black;
                --animate-wiggle: wiggle 1s infinite;

                @keyframes wiggle {
                    0%, 100% { --text-not-a-size: 1px; transform: rotate(-3deg); }
                }
                --radius-card: 1.25rem;
            }

            :root {
                --text-outside: 2rem;
            }
            CSS;

        $this->assertSame([
            'text' => ['hero'],
            'font' => ['display'],
            'font-weight' => ['heavy'],
            'shadow' => ['soft'],
            'inset-shadow' => ['deep'],
            'animate' => ['wiggle'],
            'radius' => ['card'],
        ], TailwindTheme::discover([$css]));
    }

    public function test_names_from_several_stylesheets_are_gathered_once()
    {
        $this->assertSame(
            ['text' => ['hero', 'tiny']],
            TailwindTheme::discover(['@theme { --text-hero: 4rem; }', '@theme { --text-hero: 5rem; --text-tiny: 0.6rem }', 'a { color: red }']),
        );
    }
}
