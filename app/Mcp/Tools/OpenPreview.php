<?php

namespace App\Mcp\Tools;

use App\Actions\Previews\FindStoppedPreviews;
use App\Actions\Previews\GrantPreviewAccess;
use App\Actions\Previews\MakePreviewPerson;
use App\Actions\Previews\RequestPreview;
use App\Actions\Previews\SignInToPreview;
use App\Enums\PreviewStatus;
use App\Enums\RunStatus;
use App\Models\Preview;
use App\Models\Run;
use App\Runs\WorkerTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('open_preview')]
#[Description('Get a link that opens the app with your handed-back change in a browser, so you can try it as a person would. It can sign in as one of the app\'s people. The link works once, within a minute: open it in your browser tool straight away.')]
class OpenPreview extends Tool
{
    public function __construct(
        protected WorkerTask $task,
        protected GrantPreviewAccess $grantPreviewAccess,
        protected SignInToPreview $signInToPreview,
        protected MakePreviewPerson $makePreviewPerson,
        protected RequestPreview $requestPreview,
        protected FindStoppedPreviews $findStoppedPreviews,
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $input = $request->validate([
            'path' => ['nullable', 'string', 'max:500', 'regex:#^/(?![/\\\\])#'],
            'person' => ['nullable', 'string', 'max:100'],
        ]);

        $run = $this->task->run?->refresh();

        if ($run === null) {
            return Response::error(__('No change waits for you now. Call get_task first.'));
        }

        $run->recordEvent('worker_query', ['tool' => 'open_preview']);
        $preview = self::preview($run);

        if ($preview === null) {
            return Response::error(__('The app with your change is not running yet. It starts once your change applies: call check_status, then try again.'));
        }

        // A preview can stop on our side while it still counts as ready,
        // as when its machine restarts. A link to it would open nothing.
        if (! $this->findStoppedPreviews->answers($preview)) {
            $this->requestPreview->handle($preview->featureRequest);

            return Response::error(__('This is our fault: the app with your change had stopped on our side. It is starting again now. Call open_preview again in a minute.'));
        }

        $person = $input['person'] ?? null;
        $made = null;

        try {
            if ($person === 'new') {
                $made = $this->makePreviewPerson->in($preview);
                $person = $made['id'];
            }

            // Kept apart from the owner's own grant and sessions, so the
            // tool never closes the app where the owner has it open.
            $url = $this->grantPreviewAccess->handle(
                $preview,
                $input['path'] ?? null,
                filled($person) ? $this->signInToPreview->cookie($preview, $person) : null,
                shared: true,
            );
        } catch (ValidationException $exception) {
            return Response::error(str_contains($exception->getMessage(), 'could not sign them in')
                ? __('Nobody with that email or id can be signed in to the running app. Pass "new" as person to make a test person, or leave person out and sign up in the browser.')
                : $exception->getMessage());
        }

        return Response::text(implode("\n\n", array_filter([
            __('Open this link now, once, in your browser tool. It works for one minute:'),
            $url,
            $made === null ? null : __('You are signed in as a test person the app made: :who.', ['who' => $made['email'] ?? $made['name'] ?? $made['id']]),
            __('It shows the change as it last applied. The app keeps your browser signed in to it after that.'),
        ])));
    }

    /**
     * Get the running app that shows the run's change, once the change has
     * applied.
     */
    public static function preview(Run $run): ?Preview
    {
        if (! in_array($run->status, [RunStatus::Implementing, RunStatus::Verifying, RunStatus::Reviewing], true)) {
            return null;
        }

        return Preview::query()
            ->where('feature_request_id', $run->feature_request_id)
            ->where('status', PreviewStatus::Ready)
            ->latest('id')
            ->first();
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('The page to open, such as /classes. The front page when left out.'),
            'person' => $schema->string()->description('One of the app\'s people to be signed in as: their email address, such as one its seeders make, their id, or "new" to make a test person. Left out, nobody is signed in.'),
        ];
    }
}
