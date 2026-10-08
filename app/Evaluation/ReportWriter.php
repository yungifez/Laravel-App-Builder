<?php

namespace App\Evaluation;

/**
 * Writes the owner-facing report each arm produces, as Markdown. The three
 * formats are what the arms really give an owner; none of them names the
 * system that produced it.
 */
class ReportWriter
{
    /**
     * The pipeline's report: how it understood the request, what changes, what
     * it checked and how it knows, and the decisions it made.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>|null  $review
     * @param  list<array{name: string, outcome: string, stage?: string}>  $checks
     */
    public function pipeline(string $request, array $plan, ?array $review, string $verificationStatus, array $checks): string
    {
        $lines = ['# Change report', '', "**You asked:** {$request}", ''];

        if (filled($plan['understood_as'] ?? null)) {
            $lines[] = "**Understood as:** {$plan['understood_as']}";
            $lines[] = '';
        }

        if (filled($plan['summary'] ?? null)) {
            array_push($lines, '## The change', '', (string) $plan['summary'], '');
        }

        if ($review !== null) {
            $changes = is_array($review['changes'] ?? null) ? $review['changes'] : [];

            if ($changes !== []) {
                array_push($lines, '## What behaves differently', '');

                foreach ($changes as $change) {
                    $area = $change['area_name'] ?? $change['area'] ?? null;
                    $lines[] = '- '.($area !== null ? "**{$area}:** " : '')."{$change['behavior']}. Before: {$change['before']} Now: {$change['now']}";
                }

                $lines[] = '';
            }

            $unexpected = array_keys(is_array($review['classification']['unexpected'] ?? null) ? $review['classification']['unexpected'] : []);

            if ($unexpected !== []) {
                array_push($lines, '## Changed where you did not ask', '', '- '.implode("\n- ", $unexpected), '');
            }

            $preserved = is_array($review['preserved'] ?? null) ? $review['preserved'] : [];

            if ($preserved !== []) {
                array_push($lines, '## Should stay the same', '');

                foreach ($preserved as $item) {
                    $lines[] = '- '.$item['statement'].' — '.match ($item['evidence']) {
                        'verified' => 'checked: tests for this area ran and passed',
                        'untouched' => 'not changed: nothing in this area was edited',
                        default => 'not checked',
                    };
                }

                $lines[] = '';
            }

            $findings = is_array($review['findings'] ?? null) ? $review['findings'] : [];

            if ($findings !== []) {
                array_push($lines, '## Problems found in review', '');

                foreach ($findings as $finding) {
                    $lines[] = "- ({$finding['severity']}) {$finding['summary']}";
                }

                $lines[] = '';
            }
        }

        array_push($lines, "## Checks: {$verificationStatus}", '');

        // Only the checks themselves, as the other reports show: applying the
        // change and the workspace setup are not checks.
        foreach (array_filter($checks, fn (array $check) => in_array($check['stage'] ?? 'checks', ['checks', 'acceptance'], true)) as $check) {
            $lines[] = "- {$check['name']}: {$check['outcome']}";
        }

        $assumptions = is_array($plan['assumptions'] ?? null) ? $plan['assumptions'] : [];

        if ($assumptions !== []) {
            array_push($lines, '', '## Decisions made for you', '', '- '.implode("\n- ", $assumptions));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The plain agent's report: its own summary, then the checks' raw output,
     * the way a developer would see them.
     *
     * @param  list<array{name: string, outcome: string, output: string}>  $checks
     */
    public function plain(string $summary, array $checks): string
    {
        $lines = ['# Change report', '', trim($summary) !== '' ? trim($summary) : '(The agent wrote no summary.)', '', '## Check output', ''];

        foreach ($checks as $check) {
            $lines[] = "```\n$ {$check['name']} ({$check['outcome']})\n".mb_substr($check['output'], -1500)."\n```";
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The same run and evidence as the plain agent's, in a fixed structure.
     *
     * @param  list<string>  $files
     * @param  list<array{name: string, outcome: string, output: string}>  $checks
     */
    public function structured(string $request, string $summary, array $files, array $checks): string
    {
        $failing = array_values(array_filter($checks, fn (array $check) => $check['outcome'] !== 'passed'));

        $lines = [
            '# Change report',
            '',
            "**You asked:** {$request}",
            '',
            '## Result',
            '',
            $failing === [] ? 'All checks passed.' : count($failing).' of '.count($checks).' checks did not pass: '.implode(', ', array_column($failing, 'name')).'.',
            '',
            '## What was done',
            '',
            trim($summary) !== '' ? trim($summary) : '(No description.)',
            '',
            '## Files changed',
            '',
            $files === [] ? '(none)' : '- '.implode("\n- ", $files),
            '',
            '## Checks',
            '',
        ];

        foreach ($checks as $check) {
            $counts = $check['name'] === 'Tests' ? Evidence::counts($check['output']) : ['tests' => null, 'failures' => null];
            $lines[] = "- {$check['name']}: {$check['outcome']}".($counts['tests'] !== null ? " ({$counts['tests']} tests, {$counts['failures']} failing)" : '');
        }

        return implode("\n", $lines)."\n";
    }
}
