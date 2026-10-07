<?php

namespace App\Mcp\Tools;

use App\Actions\Runs\WriteBrief;
use App\Enums\RunStatus;
use App\Models\Run;
use App\Runs\Plan;
use App\Runs\WorkerClaims;
use App\Runs\WorkerTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\URL;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_task')]
#[Description('Get the task: what to build, what must keep working, and how the change is checked. Ask again after a check, for the problems to fix.')]
class GetTask extends Tool
{
    public function __construct(
        protected WorkerTask $task,
        protected WriteBrief $writeBrief,
        protected WorkerClaims $claims,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $run = $this->task->run?->refresh();

        if ($run === null) {
            return Response::text(__('No change waits for you now. Ask again in a minute.'));
        }

        if ($run->status->finished()) {
            return Response::text(__('This change has ended, so there is nothing more to do on it. Call check_status to see how it ended.'));
        }

        $run->recordEvent('worker_query', ['tool' => 'get_task']);

        if ($run->plan === null) {
            return Response::text(__('The task is still being planned. Ask again in a minute.'));
        }

        // A change already handed in is being checked. Building it again
        // would be refused, and a new claim would cut off the session that
        // waits for the result.
        if (in_array($run->status, [RunStatus::Verifying, RunStatus::Reviewing], true)) {
            return Response::text($this->task->wholeApp
                ? __('No change waits for you now: the last one handed in is being checked. Ask again in a minute.')
                : __('Your change was handed in and is being checked. Call check_status for the result.'));
        }

        return Response::text(implode("\n\n", array_filter([
            $this->writeBrief->handle($run, Plan::fromArray($run->plan)),
            $this->handBack($run),
            // Names this change for the rest of the session, so work on a
            // change the owner stopped is never handed in to the next one.
            $this->task->wholeApp ? (string) __('Your task code is :code. Pass it as `task` to share_progress, try_change, submit_change and check_status.', ['code' => $this->claims->claim($run)]) : null,
        ])));
    }

    /**
     * Say where the change starts and how to hand it back. The starting
     * point is a commit of the owner's own repository, which the worker can
     * check out; our workspace's commits mean nothing outside it.
     */
    protected function handBack(Run $run): string
    {
        $featureRequest = $run->featureRequest;
        $base = $featureRequest->base_revision;
        $unkept = count($featureRequest->lineage()) > 1;

        // A tool that writes every change may have no copy of the app, so it
        // gets the code to start from, for a short while.
        // On a fix pass it keeps the folder with its first try, which the
        // problems to fix are about; a new copy would lose that work.
        $link = fn () => URL::temporarySignedRoute('worker-code.show', now()->addHour(), ['run' => $run]);
        $code = match (true) {
            ! $this->task->wholeApp => null,
            $run->feedback !== null => __('Keep working in the folder you made for this change, which holds your earlier attempt. Only if you lost it, get the code again at :url and make the whole change again.', ['url' => $link()]),
            default => __('Get the code to start from at :url (a zip, for the next hour). Unpack it into a new empty folder in your system\'s temporary folder, such as one `mktemp -d` makes, never into the folder you were started in, which may hold other code. Run `git init -q && git add -A && git -c user.name=start -c user.email=start@localhost -c commit.gpgsign=false commit -qm start` inside it and change files only there.', ['url' => $link()]),
        };

        // One change may be run in the owner's copy of the app, or headless
        // in an empty temporary folder: the folder it starts in says which.
        $where = $this->task->wholeApp ? null : __('If the folder you were started in is empty, get the code at :url (a zip, for the next hour), unpack it there, and run `git init -q && git add -A && git -c user.name=start -c user.email=start@localhost -c commit.gpgsign=false commit -qm start`. It already holds any earlier changes that are not kept yet, so change only what this task asks:attempt and diff against HEAD when you hand it back. Otherwise you are in a copy of the app: do as follows.', [
            'url' => $link(),
            'attempt' => $run->feedback === null ? ',' : __(', make the whole change again with the fixes below,'),
        ]);

        return implode("\n\n", array_filter([
            '## Hand the change back',
            $where,
            $code ?? ($base === null ? null : __('Start from commit :base of the app.', ['base' => $base])),
            // A chat, as in the Claude app, has no folder and runs nothing.
            __('If you cannot run commands or keep files on a computer, as in a chat, change the app here instead. list_files, search_files and read_file show its code with your change so far, and write_file makes or replaces a file. try_change and submit_change then use that change when you send them no patch.'),
            $unkept ? ($code !== null
                ? __('The code already holds earlier changes that are not kept yet. Change only what this task asks.')
                : __('Earlier changes that are not kept yet are applied under yours. Change only what this task asks.')) : null,
            __('The owner watches your work live. When you start a new part of the change, tell them in one plain sentence, in their words: pass it as `doing` with try_change, or call share_progress alongside your next command. Once per part is enough, not once per file.'),
            __('You need nothing installed to run the app: try_change runs php artisan, the tests and the other checks on your change on the app\'s own server, as the checks will.'),
            __('Once your change applies, open_preview gives you a link to try it in a browser, signed in as one of the app\'s people if you like.'),
            __('When you are done, call submit_change with the whole change as one patch, such as the output of `git add -N . && git diff --binary :base`, and a short summary. Then call check_status to see how the checks went.', ['base' => $code !== null || $base === null ? 'HEAD' : $base]),
        ]));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
