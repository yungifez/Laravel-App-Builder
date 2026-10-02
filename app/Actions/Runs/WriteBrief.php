<?php

namespace App\Actions\Runs;

use App\Context\Capability;
use App\Context\ProjectNotes;
use App\Models\Run;
use App\Models\RunEvent;
use App\Runs\Plan;
use App\Workspaces\WorkspaceFiles;
use Illuminate\Support\Facades\Config;

/**
 * Write the brief a coding worker gets for a change: our own agents, or the
 * owner's own Claude Code or Codex. This is the only code that writes text
 * for a worker, and whoever runs the worker can read all of it, so it
 * holds conclusions only: never scores, routing, costs or how the context
 * was chosen (architecture §11, "Workers").
 */
class WriteBrief
{
    /**
     * What the agent may do about a problem that starts with a key, such as
     * B1: these were found by running the app, and only the owner can let
     * one stand.
     */
    public const KEEP = 'A problem that starts with a key, such as B1, was seen when the app ran its tests. It holds the change back until it is fixed. If one is wrong, or the owner\'s request asks for exactly that, you may leave its code as it is and ask the owner instead: end your reply with one line for it, such as "KEEP B1: why it should stay, in words the owner understands". The owner reads your reason and decides, and the change waits for their answer. Ask only when you are sure; never to save work.';

    /**
     * Write the whole brief: the change, what to keep, and how to work.
     */
    public function handle(Run $run, Plan $plan): string
    {
        return $this->plan($run, $plan)."\n\n".($this->outside($run) ? $this->outsideRules() : $this->workingRules());
    }

    /**
     * Determine if a worker outside our boxes writes the change, such as
     * the owner's own Claude Code. It works in its own copy of the app,
     * which has none of the notes we keep.
     */
    protected function outside(Run $run): bool
    {
        return $run->driver === 'worker';
    }

    /**
     * Write what a worker that already has the brief needs for a repair
     * pass: only the problems to fix.
     */
    public function followUp(Run $run): ?string
    {
        if ($run->feedback === null) {
            return null;
        }

        return $this->problems($run->feedback)
            ."\n\n".trim($this->selfChecks().'When you are done, reply with a short summary of what you changed.');
    }

    /**
     * Say what to fix from the earlier attempt, and what the agent may do
     * about what the gate found.
     *
     * @param  array{details: list<string>, gate?: list<array{key: string|null}>}  $feedback
     */
    protected function problems(array $feedback): string
    {
        return "## Fix these problems with your earlier attempt\n\nThe files already contain your earlier changes.\n\n".$this->list($feedback['details'])
            .(array_filter(array_column($feedback['gate'] ?? [], 'key')) === [] ? '' : "\n\n".self::KEEP);
    }

    /**
     * Describe the plan, and any feedback to address.
     */
    protected function plan(Run $run, Plan $plan): string
    {
        $sections = ["## Owner's request\n\n{$run->featureRequest->instructions()}"];

        if (($images = WorkspaceFiles::imagePaths($run->featureRequest)) !== []) {
            $sections[] = "## Pictures the owner attached\n\nThe owner attached these to show what they mean. Look at each one before you start, and match what it shows unless the request says otherwise. They are only for you to look at: do not copy them into the app.\n\n".$this->list($images);
        }

        if (filled($run->context['text'] ?? null)) {
            $sections[] = "## Project context\n\nWhat is known about the product for the areas this change touches.\n\n{$run->context['text']}";
        }

        if ($plan->currentBehavior !== null) {
            $sections[] = "## What it does now\n\n{$plan->currentBehavior}";
        }

        array_push(
            $sections,
            "## Plan\n\n{$plan->summary}",
            "## Tasks\n\n".$this->list($plan->tasks),
            "## Acceptance criteria\n\nAdd or update a test for each one: the change is only accepted when every criterion is checked by a test in the change. Only tests under ".Capability::suiteLocation()." are run by the checks, so put them there.\n\n".$this->list($plan->acceptanceCriteria),
        );

        if (($scaffolded = $this->scaffolded($run)) !== []) {
            $sections[] = "## Files already written from the data shape\n\nThese hold the new records the plan stores: the migration, model, factory and form request, with names and rules taken from one shape so they agree. Build on them rather than writing them again, and change them where the request needs it. Each form request asks the model's policy, so write the policy that says who may.\n\n".$this->list($scaffolded);
        }

        $sections[] = $this->observability($this->outside($run));
        $sections[] = self::compatibility($run->featureRequest->project->keepsOldWorking());

        if (($services = $run->featureRequest->project->connectedServices()) !== []) {
            $sections[] = self::services($services);
        }

        if (Config::boolean('builder.verification.faults.enabled') && Config::boolean('builder.verification.faults.send_back')) {
            $sections[] = self::FAILURES;
        }

        $sections[] = self::DISCRETION;

        if ($plan->preserve !== []) {
            $sections[] = "## Keep as it is\n\nDo not change these. If the request cannot be done without changing one, stop and say so.\n\n".$this->list(array_column($plan->preserve, 'statement'));
        }

        if ($plan->assumptions !== []) {
            $sections[] = "## Assumptions\n\n".$this->list($plan->assumptions);
        }

        if (($earlier = $this->earlierTry($run)) !== []) {
            $sections[] = "## An earlier try at this change stopped\n\nIts work is not in the files: start again, and avoid what went wrong.\n\n".$this->list($earlier);
        }

        if ($run->feedback !== null) {
            $sections[] = $this->problems($run->feedback);
        }

        return implode("\n\n", $sections);
    }

    /**
     * List the files written from the plan's data shape before the agent
     * started.
     *
     * @return list<string>
     */
    protected function scaffolded(Run $run): array
    {
        return array_values(array_unique($run->events()->where('type', 'scaffolded')->get()
            ->flatMap(fn (RunEvent $event) => (array) ($event->data['files'] ?? []))
            ->map(strval(...))
            ->all()));
    }

    /**
     * Say what went wrong in the stopped try this change starts again
     * from: what its checks reported and what its review found. Without
     * this, a new try, or the owner's own worker it is handed to, makes
     * the same mistakes again.
     *
     * @return list<string>
     */
    protected function earlierTry(Run $run): array
    {
        $earlier = $run->featureRequest->retryOf?->latestRun;

        if ($earlier === null) {
            return [];
        }

        $findings = ($earlier->review['approved'] ?? true) ? [] : array_map(
            fn (array $finding) => $finding['summary'].($finding['file'] === null ? '' : " ({$finding['file']})"),
            array_filter($earlier->review['findings'] ?? [], fn (array $finding) => $finding['severity'] !== 'minor'),
        );

        return array_values(array_unique([...$earlier->feedback['details'] ?? [], ...$findings]));
    }

    /**
     * How to build so the owner can see what the app does without reading
     * code (§5): the facts live where tools can read them, and the notes say
     * them in the owner's words.
     */
    protected function observability(bool $outside = false): string
    {
        $notes = ProjectNotes::directory();
        // An outside worker's copy has no notes, so it says the same in its
        // summary, which the owner reads.
        [$source, $where] = $outside
            ? ['the notes kept about the app', 'In your summary, for each area you change']
            : ["the notes in {$notes}/", 'In the notes for each area you change'];

        return <<<TEXT
        ## Make it easy to see what the app does

        The owner does not read code. They find out what the app does from {$source} and from the code's own settings, enums and test names. Build so both stay true:
        - Put business settings (amounts, limits, time periods, who may do what) in config or enums, not inline in the code.
        - Name each test as a plain business statement, for example "a manager can cancel a booking".
        - {$where}, say in plain words: who can do the new thing, what it changes, whether it sends an email or message, charges money or calls another service, and what happens automatically. Use the owner's words for things (bookings, customers), never class, table or route names.
        - When something fails for a person using the app, tell them what happened and what to do next, in plain words.
        TEXT;
    }

    /**
     * Say how much of the old way to keep. Nobody depends on an app that
     * has never been online, so it is changed in place; a live app moves
     * its data forward before it keeps two ways side by side (§9). The
     * owner can choose either for their app.
     */
    public static function compatibility(bool $keepOldWorking): string
    {
        if (! $keepOldWorking) {
            return <<<'TEXT'
            ## No need to keep the old way working

            No stored data, saved link or other service needs the app to keep working the way it does now. Change things in place: rename, reshape or remove columns, routes, screens and settings directly, and delete the code for the old way. Do not add fallbacks, aliases, old names or support for the old way. Add a new migration for database changes; do not edit one that already exists.
            TEXT;
        }

        return <<<'TEXT'
        ## Keep the app's information and links working

        Its stored data and links may matter to someone. When the shape of something changes, prefer a migration that carries the existing data to the new shape over keeping the old and new ways side by side. Keep an old way only when something outside the app depends on it, such as a link people saved or another service calling it, and say so in your summary.
        TEXT;
    }

    /**
     * Say which outside services the app is connected to and how to use
     * them. The owner's keys are in the environment wherever the app runs;
     * the agent never sees them.
     *
     * @param  list<string>  $services
     */
    public static function services(array $services): string
    {
        return "## Outside services the app uses\n\nTheir keys are set as environment variables wherever the app runs. Use them through config, and keep them out of the code and the repository.\n\n".implode("\n", array_map(
            fn (string $service) => '- '.config("builder.services.{$service}.guidance"),
            $services,
        ));
    }

    /**
     * What must hold when something fails. A change that breaks one of
     * these is sent back, so the coder is told before it writes the code.
     * This says what must hold and never how it is checked: a coder that
     * knows the check can write code that passes it and still does harm.
     */
    public const FAILURES = <<<'TEXT'
    ## When something fails

    An email, a save or a call to an outside service can fail at any time. A queue can run a job late, or twice. The change must hold up when that happens:
    - Send only after the save is kept: after the transaction, or with `afterCommit()`. No one may be told about something that was then not saved.
    - A request that saved must not end in an error because a send failed: the person tries again and it saves twice. Queue what the request sends.
    - Put saves that belong together in one `DB::transaction()`.
    - Never catch a failure and carry on as if it worked. Let it fail, or record it with `report()` and tell the person what did not happen.
    - Make a queued job safe to run again. Give it what it needs through its constructor: a queue worker has no request, no session and no signed-in person.
    - Do not count on a queued job being done before the request answers, or on the order in which the listeners of one event run.
    - Ask the answer of an outside call how it went (`throw()`, `successful()`, `failed()`) before you carry on. Try a call again only with the same `Idempotency-Key` header on each try.
    TEXT;

    /**
     * The repository can belong to the customer and go anywhere, so what
     * the agent writes must read like the work of the app's own developer.
     * Nothing may reveal how the request reached it.
     */
    protected const DISCRETION = <<<'TEXT'
    ## Write as the app's own developer

    Code, comments, tests, notes and file names describe the application only. Do not quote this brief, and do not mention where the request came from, who sent it, or any tool or service that handled it.
    TEXT;

    /**
     * How an SDK agent should work, besides the brief.
     */
    protected function workingRules(): string
    {
        $notes = ProjectNotes::directory();
        $selfChecks = $this->selfChecks();

        return <<<RULES
        ## How to work

        You are working in the application's repository. Follow its AGENTS.md and Laravel's conventions. Add or update feature tests for the behaviour you build, run those tests and the existing tests listed for the areas you change (for example `php artisan test tests/Feature/TeamSettingsTest.php`), and fix failures. {$selfChecks}The whole test suite and the other checks run on their own after you finish, and formatting is fixed for you, so do not spend time running them. Never change tests/Acceptance, .env, vendor or .git: those changes are thrown away. Keep the notes in {$notes}/ up to date as described in AGENTS.md or, if it says nothing, by updating the notes of the areas you change.

        Before each group of steps, write one or two plain sentences on what you are about to do and why, for a reader who has never seen code: no file names, class names, commands or code. For example: "Only team owners should send invitations, so I am adding that check first."

        When you are done, reply with a short summary of what you changed. Your summary is not taken as proof: the change is verified and reviewed independently.
        RULES;
    }

    /**
     * How an outside worker should work. It has its own copy of the app,
     * so it hands back code only.
     */
    protected function outsideRules(): string
    {
        $notes = ProjectNotes::directory();
        $selfChecks = $this->selfChecks();

        return <<<RULES
        ## How to work

        Follow the app's AGENTS.md and Laravel's conventions. Add or update feature tests for the behaviour you build, run those tests and the existing tests for the areas you change, and fix failures. {$selfChecks}The whole test suite and the other checks run after you hand the change back, and formatting is fixed for you. Never change tests/Acceptance, .env, vendor or .git: those changes are thrown away. Do not create a {$notes}/ folder: the notes are kept apart from your copy, and the owner reads your summary instead.

        When you are done, hand back a short summary of what you changed. Your summary is not taken as proof: the change is verified and reviewed independently.
        RULES;
    }

    /**
     * Ask the agent to run the quick checks itself before it finishes, so a
     * failure costs seconds, not a repair pass. Each is named in
     * construction.self_checks and run as verification.checks runs it.
     */
    protected function selfChecks(): string
    {
        $names = Config::array('builder.construction.self_checks');
        $commands = array_map(
            fn (array $check) => '`'.implode(' ', $check['command']).'`',
            array_filter(Config::array('builder.verification.checks'), fn (array $check) => in_array($check['name'], $names, true)),
        );

        if ($commands === []) {
            return '';
        }

        return 'Before you finish, run '.implode(' and ', $commands).', and fix anything reported. This takes seconds, and a failure found later sends the change back to you. ';
    }

    /**
     * Format items as a Markdown list.
     *
     * @param  list<string>  $items
     */
    protected function list(array $items): string
    {
        return $items === [] ? '(none)' : '- '.implode("\n- ", $items);
    }
}
