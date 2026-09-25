<?php

namespace App\Runs;

use App\Runs\Exceptions\ConstructionFailed;
use Illuminate\Support\Facades\Validator;

/**
 * The saved intent a run builds against: what the change does, how to tell
 * it is done, and the steps the owner can later select and change.
 *
 * The protected acceptance suites are chosen by the platform, never by the
 * model that writes the plan.
 */
final readonly class Plan
{
    /**
     * @param  list<string>  $acceptanceCriteria
     * @param  list<string>  $assumptions
     * @param  list<string>  $tasks
     * @param  list<array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>  $steps
     * @param  list<string>  $acceptance  Protected acceptance test files that apply to the change
     */
    public function __construct(
        public string $summary,
        public array $acceptanceCriteria = [],
        public array $assumptions = [],
        public array $tasks = [],
        public array $steps = [],
        public array $acceptance = [],
        public ?string $solutionKey = null,
    ) {}

    /**
     * Build a plan from model output, refusing output that does not match the plan's shape.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $acceptance  The protected suites the platform selected
     * @param  string|null  $solutionKey  The platform's classification of the request
     *
     * @throws ConstructionFailed
     */
    public static function fromModelOutput(array $data, array $acceptance, ?string $solutionKey = null): self
    {
        $validator = Validator::make($data, [
            'summary' => ['required', 'string', 'max:2000'],
            'acceptance_criteria' => ['required', 'array', 'min:1', 'max:30'],
            'acceptance_criteria.*' => ['required', 'string', 'max:1000'],
            'assumptions' => ['present', 'array', 'max:30'],
            'assumptions.*' => ['string', 'max:1000'],
            'tasks' => ['required', 'array', 'min:1', 'max:30'],
            'tasks.*' => ['required', 'string', 'max:2000'],
            'steps' => ['required', 'array', 'min:1', 'max:20'],
            'steps.*.key' => ['required', 'string', 'regex:/^[a-z0-9-]+$/', 'max:60', 'distinct'],
            'steps.*.kind' => ['required', 'string', 'max:40'],
            'steps.*.label' => ['required', 'string', 'max:200'],
            'steps.*.file' => ['required', 'string', 'max:500'],
            'steps.*.symbol' => ['required', 'string', 'max:300'],
            'steps.*.detail' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            throw new ConstructionFailed(__('The planner returned an invalid plan: :errors', ['errors' => implode(' ', $validator->errors()->all())]));
        }

        /** @var array{summary: string, acceptance_criteria: array<int, string>, assumptions: array<int, string>, tasks: array<int, string>, steps: array<int, array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>} $valid */
        $valid = $validator->validated();

        return new self(
            summary: $valid['summary'],
            acceptanceCriteria: array_values($valid['acceptance_criteria']),
            assumptions: array_values($valid['assumptions']),
            tasks: array_values($valid['tasks']),
            steps: array_values(array_map(fn (array $step) => [
                'key' => $step['key'],
                'kind' => $step['kind'],
                'label' => $step['label'],
                'file' => $step['file'],
                'symbol' => $step['symbol'],
                'detail' => $step['detail'],
            ], $valid['steps'])),
            acceptance: $acceptance,
            solutionKey: $solutionKey,
        );
    }

    /**
     * Restore a plan saved on a run.
     *
     * @param  array{summary: string, acceptance_criteria: list<string>, assumptions: list<string>, tasks: list<string>, steps: list<array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>, acceptance: list<string>, solution_key: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            summary: $data['summary'],
            acceptanceCriteria: $data['acceptance_criteria'],
            assumptions: $data['assumptions'],
            tasks: $data['tasks'],
            steps: $data['steps'],
            acceptance: $data['acceptance'],
            solutionKey: $data['solution_key'],
        );
    }

    /**
     * Get the plan as stored on the run.
     *
     * @return array{summary: string, acceptance_criteria: list<string>, assumptions: list<string>, tasks: list<string>, steps: list<array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>, acceptance: list<string>, solution_key: string|null}
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'acceptance_criteria' => $this->acceptanceCriteria,
            'assumptions' => $this->assumptions,
            'tasks' => $this->tasks,
            'steps' => $this->steps,
            'acceptance' => $this->acceptance,
            'solution_key' => $this->solutionKey,
        ];
    }
}
