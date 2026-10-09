<?php

namespace App\Actions\Projects;

use App\Context\ProjectNotes;
use App\Models\Project;
use App\Projects\DesignDirection;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\File;

class ApplyDesignDirection
{
    /**
     * The stylesheet whose theme holds the app's colours, radius and font.
     */
    public const STYLESHEET = 'resources/css/app.css';

    /**
     * The build file that loads the app's font.
     */
    public const BUILD_CONFIG = 'vite.config.ts';

    /**
     * The note that holds the app's design contract.
     */
    public const NOTE = 'design.md';

    public function __construct(private ProjectRepository $repository, private ProjectNotes $notes) {}

    /**
     * Give a new app the look its owner picked: the colours, radius and font
     * go into the app's own theme as one commit, and the design contract
     * goes into its notes, where every later change to the interface reads
     * it. A file the app does not have is left alone.
     */
    public function handle(Project $project, DesignDirection $direction): void
    {
        $head = $this->repository->head($project);
        $files = [];

        $stylesheet = $this->repository->show($project, $head, self::STYLESHEET);

        if ($stylesheet !== null) {
            $themed = self::withTokens($stylesheet, ':root', [...$direction->light, 'radius' => $direction->radius]);
            $themed = self::withTokens($themed, '.dark', $direction->dark);
            $files[self::STYLESHEET] = self::withFont($themed, $direction->font);
        }

        $build = $this->repository->show($project, $head, self::BUILD_CONFIG);

        if ($build !== null) {
            $files[self::BUILD_CONFIG] = self::withFontLoaded($build, $direction);
        }

        $files = array_filter($files, fn (string $contents, string $path) => $contents !== ($path === self::STYLESHEET ? $stylesheet : $build), ARRAY_FILTER_USE_BOTH);

        if ($files !== []) {
            $owner = $project->owner;
            $this->repository->commitFiles($project, $head, $files, "Use the {$direction->name} look", ['name' => $owner->name, 'email' => $owner->email]);
        }

        $this->notes->put($project, config('builder.projects.branch'), [self::NOTE => self::contract($project, $direction)]);
    }

    /**
     * Set custom properties inside the first rule for a selector, such as
     * ":root", adding those it does not have yet.
     *
     * @param  array<string, string>  $tokens
     */
    public static function withTokens(string $css, string $selector, array $tokens): string
    {
        if (preg_match('/^'.preg_quote($selector, '/').'\s*\{(.*?)^\}/ms', $css, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $css;
        }

        $body = $match[1][0];

        foreach ($tokens as $name => $value) {
            $pattern = '/^(\s*--'.preg_quote($name, '/').'\s*:\s*)[^;]*;/m';

            $body = preg_match($pattern, $body) === 1
                ? (string) preg_replace($pattern, '${1}'.$value.';', $body, 1)
                : rtrim($body)."\n    --{$name}: {$value};\n";
        }

        return substr_replace($css, $body, $match[1][1], strlen($match[1][0]));
    }

    /**
     * Put the font first in every "--font-sans" family list, keeping the
     * fallbacks after it.
     */
    public static function withFont(string $css, string $font): string
    {
        return (string) preg_replace('/(--font-sans:\s*)([\'"]?)[^,\'";]+\2/', "\${1}'{$font}'", $css);
    }

    /**
     * Load the font and its weights where the build loads the current one
     * from Bunny Fonts.
     */
    public static function withFontLoaded(string $build, DesignDirection $direction): string
    {
        return (string) preg_replace(
            '/(bunny\(\s*)([\'"])[^\'"]+\2(\s*,\s*\{\s*weights:\s*\[)[^\]]*(\])/',
            "\${1}'{$direction->font}'\${3}".implode(', ', $direction->weights).'${4}',
            $build,
            1,
        );
    }

    /**
     * Write the design contract for the app from the shared template and
     * the look's own intent, tokens and forbidden patterns.
     */
    public static function contract(Project $project, DesignDirection $direction): string
    {
        $template = config('builder.projects.designs').'/contract.md';
        $bullets = fn (array $lines) => implode("\n", array_map(fn (string $line) => "- {$line}", $lines));

        $tokens = ['| Token | Light | Dark |', '| --- | --- | --- |'];

        foreach (array_keys($direction->light + $direction->dark) as $name) {
            $tokens[] = "| `{$name}` | `".($direction->light[$name] ?? '–').'` | `'.($direction->dark[$name] ?? '–').'` |';
        }

        return strtr(File::exists($template) ? File::get($template) : "# Design contract\n\n{{ feel }}\n\n{{ tokens }}\n\n{{ avoid }}\n", [
            '{{ app }}' => $project->name,
            '{{ name }}' => $direction->name,
            '{{ description }}' => $direction->description,
            '{{ feel }}' => $bullets($direction->feel),
            '{{ avoid }}' => $bullets($direction->avoid),
            '{{ font }}' => $direction->font,
            '{{ radius }}' => $direction->radius,
            '{{ tokens }}' => implode("\n", $tokens),
        ]);
    }
}
