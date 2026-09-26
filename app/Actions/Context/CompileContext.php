<?php

namespace App\Actions\Context;

use App\Context\Capability;
use App\Context\ContextPack;
use App\Context\Effect;
use App\Context\ProjectContext;
use App\Enums\ContextMode;

class CompileContext
{
    /**
     * Compile the project context for a change, deterministically.
     *
     * Selective (the product): the project notes, the files of the areas the
     * change is about with their Effects as hints, and an index of the other
     * areas the agent may read itself. Flat: every file. None: nothing. The
     * outline of every area is kept in all modes, to classify the change.
     *
     * @param  list<string>  $targets  The areas the change is about
     */
    public function handle(ProjectContext $context, array $targets, ?ContextMode $mode = null): ContextPack
    {
        $mode ??= ContextMode::from((string) config('builder.context.mode'));
        $targets = $context->known($targets);
        $sections = [];

        if ($mode !== ContextMode::None && filled($context->project)) {
            $sections[] = [ProjectContext::PROJECT_FILE, '## Project notes ('.ProjectContext::PROJECT_FILE.")\n\n{$context->project}"];
        }

        if ($mode === ContextMode::Flat) {
            foreach ($context->capabilities as $capability) {
                $sections[] = [(string) $capability->file, $this->area($context, $capability, withEffects: true)];
            }
        }

        if ($mode === ContextMode::Selective || $mode === ContextMode::SelectiveWithoutEffects) {
            foreach ($targets as $target) {
                $capability = $context->capabilities[$target];
                $sections[] = [(string) $capability->file, $this->area($context, $capability, withEffects: $mode === ContextMode::Selective)];
            }

            $others = array_diff_key($context->capabilities, array_flip($targets));

            if ($others !== []) {
                $sections[] = ['index', "## Other areas\n\nRead these files yourself only if the change turns out to involve them:\n".implode("\n", array_map(
                    fn (Capability $capability) => "- {$capability->name}".($capability->summary !== null ? ": {$capability->summary}" : '')." ({$capability->file})",
                    $others,
                ))];
            }
        }

        return new ContextPack(
            mode: $mode,
            targets: $targets,
            text: implode("\n\n", array_column($sections, 1)),
            included: array_map(fn (array $section) => ['file' => $section[0], 'tokens' => $this->tokens($section[1])], $sections),
            outline: $context->outline(),
            problems: $context->problems,
        );
    }

    /**
     * Render one area: its notes and, when asked, what it may also affect.
     */
    protected function area(ProjectContext $context, Capability $capability, bool $withEffects): string
    {
        $text = "## {$capability->name} ({$capability->file})\n\n".($capability->notes !== '' ? $capability->notes : ($capability->summary ?? ''));

        if ($withEffects && $capability->effects !== []) {
            $text .= "\n\nMay also affect (hints, not requirements; look only if they matter for this request):\n".implode("\n", array_map(
                fn (Effect $effect) => '- '.($context->capabilities[$effect->to]->name ?? $effect->to)." ({$effect->strength->value}): {$effect->reason}",
                $capability->effects,
            ));
        }

        return rtrim($text);
    }

    /**
     * Estimate a text's tokens (about four characters each).
     */
    protected function tokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
