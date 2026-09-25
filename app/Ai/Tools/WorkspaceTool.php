<?php

namespace App\Ai\Tools;

use App\Runs\Exceptions\BudgetExhausted;
use App\Runs\Exceptions\LeaseLost;
use App\Runs\Exceptions\RunCancelled;
use App\Runs\ToolSession;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Exposes one of the run's server-side tools to a model.
 *
 * The model's arguments go to the tool executor unchanged, which checks
 * them; nothing here trusts them. Each call is journaled under the model's
 * tool call id. The result goes back to the model as JSON, including
 * refusals, so it can correct itself.
 */
abstract class WorkspaceTool implements Tool
{
    public function __construct(
        protected ToolSession $session,
        protected string $operationPrefix,
    ) {}

    /**
     * Get the name of the workspace tool, as configured in builder.construction.tools.
     */
    abstract public function name(): string;

    /**
     * Determine if calls change the workspace and must name the expected revision.
     */
    protected function mutates(): bool
    {
        return false;
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->session->halted()) {
            return $this->stopped();
        }

        $arguments = $request->all();
        $expectedRevision = null;

        if ($this->mutates()) {
            $expectedRevision = is_numeric($arguments['expected_revision'] ?? null) ? (int) $arguments['expected_revision'] : null;
            unset($arguments['expected_revision']);
        }

        try {
            $result = $this->session->call(
                $this->operationPrefix.':'.($request->toolCallId() ?? Str::uuid()->toString()),
                $this->name(),
                $arguments,
                $expectedRevision,
            );
        } catch (LeaseLost|RunCancelled|BudgetExhausted $exception) {
            $this->session->halt($exception);

            return $this->stopped();
        }

        return (string) json_encode(array_filter([
            'status' => $result->status->value,
            'result' => $result->result === [] ? null : $result->result,
            'error' => $result->error,
            'workspace_revision' => $result->revision,
        ], fn ($value) => $value !== null), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Tell the model the run has stopped.
     */
    protected function stopped(): string
    {
        return (string) json_encode([
            'status' => 'stopped',
            'error' => __('The run has stopped. Do not call any more tools.'),
        ]);
    }
}
