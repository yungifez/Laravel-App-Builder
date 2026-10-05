<?php

namespace App\Models;

use App\Enums\ExperimentStatus;
use App\Enums\FeatureRequestStatus;
use App\Features\CodeShortcuts;
use App\Models\Concerns\HasPublicId;
use Database\Factories\FeatureRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * An owner's request for a feature, or for a change to one step of a feature
 * generated earlier (a follow-up with a parent and a target step).
 *
 * @property int $id
 * @property string $uuid Names the row in links and requests
 * @property int $project_id
 * @property int|null $experiment_id The idea it was made in; null is the main app
 * @property int $user_id
 * @property int|null $parent_id
 * @property int|null $retry_of_id The stopped request this one tries again
 * @property string $prompt
 * @property array{file: string, line: int, column: int, tag: string, text: string|null, area: string|null, behavior?: string|null}|null $selection The element the owner pointed at in the preview
 * @property list<array{path: string, name: string}>|null $images Pictures the owner attached to show what they mean, on the request images disk
 * @property array{deployment_id?: int, preview_id?: int, problem?: string, errors: list<array{class: string|null, message: string, count: int, place?: string|null, trace?: list<string>}>}|null $live_errors The errors the published app raised, or the app on show while the owner tried it, when the ask is to fix them
 * @property array{deployment_id: int, checks: list<array{name: string, output: string}>}|null $failed_checks The checks that kept the app from going online, with what each said, when the ask is to fix them
 * @property array{of: int, tier: string, shortcuts: list<array{rule: string, path: string, line: int}>}|null $tidy The shortcuts a kept change took, when this is the background pass that fixes them, and whether the light or the full coder makes it
 * @property string|null $target_step
 * @property FeatureRequestStatus $status
 * @property string $generator
 * @property string|null $base_revision The project commit the change is built on; null builds on the project's source directory
 * @property string|null $solution_key
 * @property string|null $summary
 * @property string|null $patch The code change; notes are not part of it
 * @property string|null $design_base The commit on its design branch where the change's own code starts
 * @property array<string, array{before: string|null, after: string|null}>|null $note_changes The notes the change rewrote, by path, as they were and as it left them
 * @property list<array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>|null $steps
 * @property list<string>|null $acceptance Protected acceptance test files that apply to the change
 * @property string|null $error
 * @property list<array{provider: string, model: string|null, input_tokens: int, output_tokens: int, cost_usd: float|null, cost_source: string|null, at: string}>|null $decision_model_calls The decision model's calls about the request, with what each cost
 * @property string|null $commit_sha The project commit that holds the change once the owner accepted it
 * @property Carbon|null $accepted_at
 * @property string|null $revert_sha The project commit that undid the change
 * @property Carbon|null $reverted_at
 * @property Carbon|null $dismissed_at When the owner said the ask is no longer needed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['experiment_id', 'project_id', 'user_id', 'parent_id', 'retry_of_id', 'prompt', 'selection', 'images', 'live_errors', 'failed_checks', 'tidy', 'target_step', 'status', 'generator', 'solution_key', 'summary', 'patch', 'note_changes', 'steps', 'acceptance', 'error', 'decision_model_calls', 'base_revision', 'design_base', 'commit_sha', 'accepted_at', 'revert_sha', 'reverted_at', 'dismissed_at'])]
class FeatureRequest extends Model
{
    /**
     * Where a workspace keeps the patches of earlier changes while applying
     * them. It is removed before the workspace is used.
     */
    public const LINEAGE_DIRECTORY = '.patches-to-apply';

    /** @use HasFactory<FeatureRequestFactory> */
    use HasFactory;

    use HasPublicId;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FeatureRequestStatus::class,
            'steps' => 'array',
            'note_changes' => 'array',
            'acceptance' => 'array',
            'decision_model_calls' => 'array',
            'selection' => 'array',
            'images' => 'array',
            'live_errors' => 'array',
            'failed_checks' => 'array',
            'tidy' => 'array',
            'accepted_at' => 'datetime',
            'reverted_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    /**
     * Get the request as the planner, coder and reviewer read it: the
     * owner's words and, when they started from the preview, the element
     * they pointed at, or from errors online, what those errors were.
     */
    public function instructions(): string
    {
        $count = count($this->images ?? []);
        $images = $count === 0 ? '' : "\n\nThe owner attached ".($count === 1 ? 'a picture that shows' : "{$count} pictures that show").' what they mean. Match what '.($count === 1 ? 'it shows' : 'they show').' unless the words say otherwise.';

        return $this->describedPrompt().$this->frontPage().$images;
    }

    /**
     * Whether this asks for the first version of an app started here. The
     * owner asked for it by saying what the app is for.
     */
    public function isFirstVersion(): bool
    {
        return $this->parent_id === null && str_starts_with($this->prompt, __('Make the first version:'));
    }

    /**
     * Ask the first version for its own front page. Without it, the first
     * build hides behind the login and the app still opens on the
     * template's welcome page. The owner never asked for it in these
     * words, so it is not part of what they see they asked.
     */
    protected function frontPage(): string
    {
        $sentence = __('Give it its own front page in place of the starter welcome page.');

        return $this->isFirstVersion() && ! str_contains($this->prompt, $sentence) ? "\n\n".$sentence : '';
    }

    /**
     * What an app should do while one kind of thing is down, for the coder
     * deciding whether an error the owner met then is right.
     *
     * @var array<string, string>
     */
    protected const OUTAGES = [
        'mail' => 'Usually the visitor\'s own action should still succeed (the record is saved), with the email queued, retried or reported, and the page should not fail; only a page whose whole purpose is the email may say it could not be sent.',
        'http' => 'If the page can work without that answer, it should, with a clear note; if it truly depends on it (such as a payment), it should stop before saving anything half done and tell the visitor to try again later, not show an error page.',
        'file' => 'The visitor should be told the file could not be saved and nothing else should be saved half done; the page should not fail with an error.',
        'cache' => 'The cache only makes things faster, so a page should normally still work by reading from the source, for example by catching the cache failure and falling back; only something that truly needs it, such as a lock, may refuse with a clear message.',
        'notification' => 'The visitor\'s own action should still succeed; the notice should be queued, retried or reported, not fail the page.',
    ];

    /**
     * Get the owner's words with where they started from: the element they
     * pointed at, or the errors online or while they tried the app.
     */
    protected function describedPrompt(): string
    {
        $selection = $this->selection;

        if ($this->live_errors !== null) {
            return $this->prompt."\n\n".$this->liveErrorInstructions($this->live_errors['errors'], isset($this->live_errors['preview_id']));
        }

        if ($this->failed_checks !== null) {
            return $this->prompt."\n\n".$this->failedCheckInstructions($this->failed_checks['checks']);
        }

        if ($this->tidy !== null) {
            return $this->prompt."\n\nFix only these, and change nothing else:\n".implode("\n", array_map(fn (array $shortcut) => '- '.CodeShortcuts::finding($shortcut), $this->tidy['shortcuts']));
        }

        if ($selection === null) {
            return $this->prompt;
        }

        $element = "`<{$selection['tag']}>` at {$selection['file']}:{$selection['line']}";
        $text = filled($selection['text'] ?? null) ? ' (it shows "'.str($selection['text'])->squish()->limit(120).'")' : '';
        $area = filled($selection['area'] ?? null) ? " It belongs to the area \"{$selection['area']}\"." : '';
        $behavior = filled($selection['behavior'] ?? null) ? " It calls the server action `{$selection['behavior']}`." : '';

        return "{$this->prompt}\n\nThe owner pointed at this element in the app: {$element}{$text}.{$area}{$behavior}";
    }

    /**
     * Describe the errors people hit in the published app, or the owner hit
     * while trying it. The owner only saw that something went wrong; the
     * details are for the builder.
     *
     * @param  list<array{class: string|null, message: string, count: int, place?: string|null, trace?: list<string>, during?: string|null}>  $errors
     */
    protected function liveErrorInstructions(array $errors, bool $tried = false): string
    {
        $lines = array_map(
            fn (array $error) => '- '.trim(($error['class'] ?? '').': '.str($this->redact($error['message']))->squish()->limit(300), ': ')
                .(filled($error['place'] ?? null) ? " (at {$error['place']})" : '')
                .' ('.($error['count'] === 1 ? 'once' : "{$error['count']} times").')'
                .(($error['trace'] ?? []) === [] ? '' : "\n  Through the app's code: ".implode(', ', $error['trace']))
                .(isset(self::OUTAGES[$error['during'] ?? '']) ? "\n  ".$this->outageInstructions($error['during']) : ''),
            $errors,
        );

        $intro = $tried
            ? 'The owner ran into this error while trying the app.'
            : 'People using the published app ran into these errors since its current version went online, most frequent first.';

        return "{$intro} Find why each happens and fix the cause, with a test that fails without the fix:\n".implode("\n", $lines);
    }

    /**
     * Describe the checks that kept the app from going online. They ran on
     * the main app's version the owner tried to publish.
     *
     * @param  list<array{name: string, output: string}>  $checks
     */
    protected function failedCheckInstructions(array $checks): string
    {
        $lines = array_map(
            fn (array $check) => "- {$check['name']} failed. Its output ends with:\n```\n".$this->redact($check['output'])."\n```",
            $checks,
        );

        return "These checks failed when the owner tried to put the app online, so it did not go online. Find why each fails and fix the cause in the app's code. Do not skip, weaken or delete a check or a test to make it pass:\n".implode("\n", $lines);
    }

    /**
     * Explain an error the owner met while they had made one thing fail on
     * purpose, to see how the app copes without it. The error is then not
     * a bug in that thing, and the answer is not to bring it back: it is
     * how the app should behave while it is gone.
     */
    protected function outageInstructions(string $kind): string
    {
        return "This happened while the owner had made {$this->outageName($kind)} fail on purpose, to see how the app copes without it. Nothing is wrong with {$this->outageName($kind)} itself: do not change its configuration or remove its use. Decide what the app should do while it is down. ".self::OUTAGES[$kind]
            .' Say in your summary which you chose and why, and test it with that service failing.';
    }

    /**
     * Name what was down on purpose.
     */
    protected function outageName(string $kind): string
    {
        return match ($kind) {
            'mail' => 'the mail server',
            'http' => 'outside services',
            'file' => 'file storage',
            'cache' => 'the cache',
            default => 'notification channels',
        };
    }

    /**
     * Remove what belongs to the people using the app before the message
     * goes to the model: quoted values (SQL bindings, input), email and IP
     * addresses, and anything that looks like a key or token.
     */
    protected function redact(string $message): string
    {
        return preg_replace([
            "/'(?:[^'\\\\]|\\\\.)*'/",
            '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/',
            '/\b\d{1,3}(?:\.\d{1,3}){3}\b/',
            '/\b(?=[\w-]*\d)(?=[\w-]*[a-zA-Z])[\w-]{24,}\b/',
        ], ["'?'", '[email]', '[ip]', '[secret]'], $message) ?? '';
    }

    /**
     * Keep the changes that belong where the owner is working: the open
     * idea's own, or in the main app, those made there or in ideas used
     * in it.
     *
     * @param  Builder<FeatureRequest>  $query
     */
    #[Scope]
    protected function inLine(Builder $query, Project $project): void
    {
        if ($project->experiment_id !== null) {
            $query->where('experiment_id', $project->experiment_id);

            return;
        }

        $query->where(fn (Builder $query) => $query
            ->whereNull('experiment_id')
            ->orWhereHas('experiment', fn (Builder $query) => $query->where('status', ExperimentStatus::Merged)));
    }

    /**
     * Get the idea the change was made in, if not the main app.
     *
     * @return BelongsTo<Experiment, $this>
     */
    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    /**
     * Get the branch the owner designs the change on while it waits to be
     * kept: its base, the changes it follows up on, then its own code and
     * the owner's design edits. Named by number only, as ideas are.
     */
    public function designBranch(): string
    {
        return "changes/{$this->id}";
    }

    /**
     * Get the branch the change lives on now, or null when its idea was
     * thrown away.
     */
    public function branch(): ?string
    {
        return Experiment::branchOf($this->experiment);
    }

    /**
     * Get the project the request is for.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the person who asked for the change.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the request this one follows up on.
     *
     * @return BelongsTo<FeatureRequest, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Get the stopped request this one tries again.
     *
     * @return BelongsTo<self, $this>
     */
    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    /**
     * Get the follow-up requests made on this one.
     *
     * @return HasMany<FeatureRequest, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Get the boundary findings the owner said the change makes on purpose.
     *
     * @return HasMany<AcceptedFinding, $this>
     */
    public function acceptedFindings(): HasMany
    {
        return $this->hasMany(AcceptedFinding::class);
    }

    /**
     * Get the agent's cases that a finding of the change should stand.
     *
     * @return HasMany<FindingProposal, $this>
     */
    public function findingProposals(): HasMany
    {
        return $this->hasMany(FindingProposal::class);
    }

    /**
     * Get the verification runs for the request's change.
     *
     * @return HasMany<Verification, $this>
     */
    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class);
    }

    /**
     * Get the construction runs for the request.
     *
     * @return HasMany<Run, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * Get the decisions made about the request before it was built.
     *
     * @return HasMany<Decision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class);
    }

    /**
     * Get the publishes that contain this change.
     *
     * @return BelongsToMany<Deployment, $this>
     */
    public function deployments(): BelongsToMany
    {
        return $this->belongsToMany(Deployment::class);
    }

    /**
     * Get the request's most recent construction run.
     *
     * @return HasOne<Run, $this>
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(Run::class)->latestOfMany();
    }

    /**
     * Get the request's previews.
     *
     * @return HasMany<Preview, $this>
     */
    public function previews(): HasMany
    {
        return $this->hasMany(Preview::class);
    }

    /**
     * Get this request and the requests it follows up on that are not part of
     * its base revision, oldest first, so their patches can be applied in
     * order on top of it. A request made after its parent was accepted is
     * based on the commit that holds the parent, so the lineage stops there.
     * An answer in between changed nothing, so it is passed over.
     *
     * @return list<FeatureRequest>
     */
    public function lineage(): array
    {
        $lineage = [$this];
        $current = $this;

        while ($current->parent_id !== null) {
            $current = $current->parent()->firstOrFail();

            if ($current->status === FeatureRequestStatus::Answered) {
                continue;
            }

            if ($current->base_revision !== $this->base_revision) {
                break;
            }

            array_unshift($lineage, $current);
        }

        return $lineage;
    }

    /**
     * Determine whether the owner accepted the change into the project and
     * has not undone it.
     */
    public function isAccepted(): bool
    {
        return $this->commit_sha !== null && $this->reverted_at === null;
    }

    /**
     * Say what a change made in the background does, in place of words the
     * owner never wrote. Null for a change the owner asked for.
     */
    public function background(): ?string
    {
        if ($this->tidy === null) {
            return null;
        }

        return trans_choice($this->commit_sha !== null
            ? "I tidied up one thing in your app's code in the background.|I tidied up :count things in your app's code in the background."
            : "Tidying up one thing in your app's code in the background.|Tidying up :count things in your app's code in the background.", count($this->tidy['shortcuts']));
    }

    /**
     * Find one of the generated change's steps by key.
     *
     * @return array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}|null
     */
    public function step(string $key): ?array
    {
        return collect($this->steps ?? [])->firstWhere('key', $key);
    }
}
