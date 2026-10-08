<?php

namespace App\Console\Commands;

use App\Evaluation\Suite;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('eval:audit {files* : Agent transcripts (JSONL) or patches to audit} {--forbid=* : Path fragments no tool call may touch (defaults to the hidden material)} {--canary= : The suite\'s canary (defaults to the suite\'s canary.txt)} {--allow=* : Paths a tool call may name although they sit under a forbidden one, for example the agent\'s own hand-off request}')]
#[Description('Check that agents never saw hidden material: no transcript or patch may contain the suite\'s canary, and no tool call may name a forbidden path')]
class AuditEvaluation extends Command
{
    /**
     * Path fragments of hidden material that coding and review agents may not touch.
     *
     * @var list<string>
     */
    protected const HIDDEN = [
        'fixtures/evaluation', 'fixtures/reference-solutions', 'fixtures/acceptance', 'tests/Hidden', 'storage/app/evaluation',
        // Agent transcripts, the orchestrator's included.
        '.jsonl', '.claude/projects',
    ];

    /**
     * Tools that only report back to the orchestrator. Mentioning a path in a
     * report is not access, so their arguments are not audited.
     *
     * @var list<string>
     */
    protected const REPORTING = ['SubagentHandback', 'SendMessage'];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $canary = $this->option('canary') ?: trim((string) File::get(Suite::fromConfig()->directory.'/canary.txt'));

        /** @var list<string> $forbidden */
        $forbidden = $this->option('forbid') ?: array_values(array_filter([
            ...self::HIDDEN,
            config('evaluation.handoff.path'),
            config('evaluation.results'),
            ...config('evaluation.audit.forbidden'),
        ], fn ($fragment) => is_string($fragment) && $fragment !== ''));

        /** @var list<string> $allowed */
        $allowed = $this->option('allow');

        /** @var list<string> $files */
        $files = $this->argument('files');
        $rows = [];
        $clean = true;

        foreach ($files as $file) {
            $contents = File::get($file);
            $canaryHits = substr_count($contents, $canary);
            $pathHits = [];

            $inputs = $this->toolInputs($contents);

            foreach ($inputs as $input) {
                $input = str_replace($allowed, '', $input);

                foreach ($forbidden as $fragment) {
                    if (str_contains($input, $fragment)) {
                        $pathHits[$fragment] = ($pathHits[$fragment] ?? 0) + 1;
                    }
                }
            }

            $clean = $clean && $canaryHits === 0 && $pathHits === [];
            $rows[] = [basename($file), count($inputs), $canaryHits, $pathHits === [] ? 'none' : implode(', ', array_map(fn (string $fragment, int $count) => "{$fragment} ×{$count}", array_keys($pathHits), $pathHits))];
        }

        $this->table(['File', 'Tool calls', 'Canary hits', 'Forbidden paths in tool calls'], $rows);
        $clean ? $this->info('Clean: no hidden material seen.') : $this->error('Hidden material was seen; mark the affected runs as contaminated.');

        return $clean ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Get the arguments of every tool call in a transcript, as JSON. Only
     * these count: paths mentioned in instructions or context are not access.
     *
     * @return list<string>
     */
    protected function toolInputs(string $transcript): array
    {
        $inputs = [];

        foreach (explode("\n", $transcript) as $line) {
            $entry = json_decode($line, true);

            if (is_array($entry)) {
                $this->collect($entry, $inputs);
            }
        }

        return $inputs;
    }

    /**
     * Collect tool-call arguments from a decoded transcript entry.
     *
     * @param  array<mixed>  $node
     * @param  list<string>  $inputs
     */
    protected function collect(array $node, array &$inputs): void
    {
        if (($node['type'] ?? null) === 'tool_use' && is_array($node['input'] ?? null) && ! in_array($node['name'] ?? null, self::REPORTING, true)) {
            $inputs[] = (string) json_encode($node['input'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return;
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $this->collect($child, $inputs);
            }
        }
    }
}
