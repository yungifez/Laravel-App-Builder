<?php

namespace App\Context;

use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * A notes file split into the parts an owner edits: optional frontmatter,
 * a title, the text under the title (the introduction), and "## " sections.
 *
 * Editing a part keeps every other part byte for byte, so an owner's edit
 * never reformats what they did not touch.
 */
class NotesDocument
{
    /**
     * @param  string  $frontmatter  The frontmatter block with its fences, or ""
     * @param  string  $title  The "# " heading's text, or ""
     * @param  string  $introduction  The text before the first section
     * @param  list<array{heading: string, body: string}>  $sections
     */
    public function __construct(
        public string $frontmatter = '',
        public string $title = '',
        public string $introduction = '',
        public array $sections = [],
    ) {}

    /**
     * Split a notes file into its parts.
     */
    public static function parse(string $markdown): self
    {
        $markdown = str_replace("\r\n", "\n", $markdown);
        $frontmatter = '';

        if (preg_match('/\A---\n.*?\n---\n/s', $markdown, $match) === 1) {
            $frontmatter = $match[0];
            $markdown = substr($markdown, strlen($match[0]));
        }

        $title = '';

        if (preg_match('/\A\s*#\s+(.+)\n/', $markdown, $match) === 1) {
            $title = trim($match[1]);
            $markdown = substr($markdown, strlen($match[0]));
        }

        $parts = preg_split('/^##\s+(.+)$/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [''];
        $sections = [];

        for ($index = 1; $index < count($parts); $index += 2) {
            $sections[] = ['heading' => trim($parts[$index]), 'body' => trim($parts[$index + 1] ?? '')];
        }

        return new self($frontmatter, $title, trim($parts[0]), $sections);
    }

    /**
     * Get a section's text, or null when there is no such section. The
     * headings are compared without regard to case.
     */
    public function section(string $heading): ?string
    {
        foreach ($this->sections as $section) {
            if (Str::lower($section['heading']) === Str::lower($heading)) {
                return $section['body'];
            }
        }

        return null;
    }

    /**
     * Get a copy with a section's text replaced, or the section added at
     * the end. Empty text removes the section.
     */
    public function withSection(string $heading, string $body): self
    {
        $sections = [];
        $found = false;

        foreach ($this->sections as $section) {
            if (Str::lower($section['heading']) === Str::lower($heading)) {
                $found = true;

                if (trim($body) !== '') {
                    $sections[] = ['heading' => $section['heading'], 'body' => trim($body)];
                }

                continue;
            }

            $sections[] = $section;
        }

        if (! $found && trim($body) !== '') {
            $sections[] = ['heading' => $heading, 'body' => trim($body)];
        }

        return new self($this->frontmatter, $this->title, $this->introduction, $sections);
    }

    /**
     * Get a copy with the introduction replaced.
     */
    public function withIntroduction(string $introduction): self
    {
        return new self($this->frontmatter, $this->title, trim($introduction), $this->sections);
    }

    /**
     * Get a copy with the frontmatter's "summary" replaced, added, or removed
     * when empty. Only that field is rewritten; the other fields keep their
     * formatting.
     */
    public function withSummary(string $summary): self
    {
        $summary = trim((string) preg_replace('/\s+/', ' ', $summary));
        $line = $summary === '' ? '' : 'summary: '.self::yamlString($summary)."\n";
        $fields = $this->frontmatter === '' ? '' : substr($this->frontmatter, 4, -4);
        $pattern = '/^summary:.*\n(?:[ \t]+.*\n)*/m';

        if (preg_match($pattern, $fields) === 1) {
            $fields = (string) preg_replace($pattern, addcslashes($line, '\\$'), $fields, 1);
        } elseif (preg_match('/^capability:.*\n/m', $fields, $match, PREG_OFFSET_CAPTURE) === 1) {
            $end = $match[0][1] + strlen($match[0][0]);
            $fields = substr($fields, 0, $end).$line.substr($fields, $end);
        } else {
            $fields = $line.$fields;
        }

        $frontmatter = $fields === '' ? '' : "---\n{$fields}---\n";

        return new self($frontmatter, $this->title, $this->introduction, $this->sections);
    }

    /**
     * Write a string as a YAML value: plain when it reads back the same, as
     * people write it, and quoted otherwise.
     */
    protected static function yamlString(string $value): string
    {
        $plain = rescue(fn () => Yaml::parse("value: {$value}"), null, report: false);

        return is_array($plain) && ($plain['value'] ?? null) === $value ? $value : Yaml::dump($value);
    }

    /**
     * Get the list items of a section, one per bullet.
     *
     * @return list<string>
     */
    public function items(string $heading): array
    {
        $items = [];

        foreach (preg_split('/\R/', (string) $this->section($heading)) ?: [] as $line) {
            if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $bullet) === 1) {
                $items[] = trim($bullet[1]);
            } elseif (trim($line) !== '' && $items !== []) {
                $items[array_key_last($items)] .= ' '.trim($line);
            }
        }

        return $items;
    }

    /**
     * Write the document back as Markdown.
     */
    public function toMarkdown(): string
    {
        $blocks = [];

        if ($this->title !== '') {
            $blocks[] = "# {$this->title}";
        }

        if ($this->introduction !== '') {
            $blocks[] = $this->introduction;
        }

        foreach ($this->sections as $section) {
            $blocks[] = "## {$section['heading']}".($section['body'] === '' ? '' : "\n\n{$section['body']}");
        }

        return $this->frontmatter.($this->frontmatter !== '' && $blocks !== [] ? "\n" : '').implode("\n\n", $blocks)."\n";
    }
}
