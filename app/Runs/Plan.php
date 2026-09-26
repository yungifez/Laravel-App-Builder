<?php

namespace App\Runs;

use App\Runs\Exceptions\ConstructionFailed;
use Illuminate\Support\Facades\Validator;

/**
 * The saved intent a run builds against, also shown as the change brief:
 * how the request was understood, what the application does now, what the
 * change does, what must stay as it is, how to tell it is done, and the steps
 * the owner can later select and change.
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
     * @param  list<string>  $capabilities  The areas of the product (`.builder/capabilities`) the change is about
     * @param  list<array{area: string|null, statement: string}>  $preserve  What must stay as it is, by area
     * @param  array{text: string, why: string, options: list<string>, recommended: string|null}|null  $question  The one product question to ask the owner before building, if any
     */
    public function __construct(
        public string $summary,
        public array $acceptanceCriteria = [],
        public array $assumptions = [],
        public array $tasks = [],
        public array $steps = [],
        public array $acceptance = [],
        public ?string $solutionKey = null,
        public array $capabilities = [],
        public ?string $understoodAs = null,
        public ?string $currentBehavior = null,
        public array $preserve = [],
        public ?array $question = null,
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
            'capabilities' => ['sometimes', 'array', 'max:10'],
            'capabilities.*' => ['string', 'max:60'],
            'understood_as' => ['sometimes', 'nullable', 'string', 'max:300'],
            'current_behavior' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'preserve' => ['sometimes', 'array', 'max:20'],
            'preserve.*.area' => ['nullable', 'string', 'max:60'],
            'preserve.*.statement' => ['required', 'string', 'max:500'],
            'question' => ['sometimes', 'nullable', 'array'],
            'question.text' => ['required_with:question', 'string', 'max:300'],
            'question.why' => ['sometimes', 'nullable', 'string', 'max:500'],
            'question.options' => ['required_with:question', 'array', 'min:2', 'max:4'],
            'question.options.*' => ['required', 'string', 'max:120', 'distinct'],
            'question.recommended' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        if ($validator->fails()) {
            throw new ConstructionFailed(__('The planner returned an invalid plan: :errors', ['errors' => implode(' ', $validator->errors()->all())]));
        }

        /** @var array{summary: string, acceptance_criteria: array<int, string>, assumptions: array<int, string>, tasks: array<int, string>, steps: array<int, array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>, capabilities?: array<int, string>, understood_as?: string|null, current_behavior?: string|null, preserve?: array<int, array{area?: string|null, statement: string}>, question?: array{text: string, why?: string|null, options: array<int, string>, recommended?: string|null}|null} $valid */
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
            capabilities: array_values(array_unique($valid['capabilities'] ?? [])),
            understoodAs: $valid['understood_as'] ?? null,
            currentBehavior: $valid['current_behavior'] ?? null,
            preserve: array_values(array_map(self::preserveItem(...), $valid['preserve'] ?? [])),
            question: isset($valid['question']) ? self::question($valid['question']) : null,
        );
    }

    /**
     * Read the planner's question. A recommendation that is not one of the
     * options is dropped rather than shown as a choice the owner cannot make.
     *
     * @param  array{text: string, why?: string|null, options: array<int, string>, recommended?: string|null}  $question
     * @return array{text: string, why: string, options: list<string>, recommended: string|null}
     */
    protected static function question(array $question): array
    {
        $options = array_values(array_map('trim', $question['options']));
        $recommended = $question['recommended'] ?? null;

        return [
            'text' => trim($question['text']),
            'why' => trim($question['why'] ?? ''),
            'options' => $options,
            'recommended' => in_array($recommended, $options, true) ? $recommended : null,
        ];
    }

    /**
     * Read one "keep the same" item. Models sometimes write the area into
     * the statement ("…','area':'teams") instead of its own field; that
     * tail is moved back to where it belongs, so the owner never sees it.
     *
     * @param  array{area?: string|null, statement: string}  $item
     * @return array{area: string|null, statement: string}
     */
    protected static function preserveItem(array $item): array
    {
        $area = $item['area'] ?? null;
        $statement = $item['statement'];

        if (preg_match('/^(.*?)[\'"]\s*,\s*[\'"]area[\'"]\s*:\s*[\'"]?([a-z0-9][a-z0-9_-]*)[\'"]?\s*$/s', $statement, $matches) === 1) {
            $statement = trim($matches[1]);
            $area ??= $matches[2] === 'null' ? null : $matches[2];
        }

        return ['area' => $area, 'statement' => $statement];
    }

    /**
     * Restore a plan saved on a run.
     *
     * @param  array{summary: string, acceptance_criteria: list<string>, assumptions: list<string>, tasks: list<string>, steps: list<array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>, acceptance: list<string>, solution_key: string|null, capabilities?: list<string>, understood_as?: string|null, current_behavior?: string|null, preserve?: list<array{area: string|null, statement: string}>}  $data
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
            capabilities: $data['capabilities'] ?? [],
            understoodAs: $data['understood_as'] ?? null,
            currentBehavior: $data['current_behavior'] ?? null,
            preserve: array_map(self::preserveItem(...), $data['preserve'] ?? []),
        );
    }

    /**
     * Get the plan as stored on the run.
     *
     * @return array{summary: string, acceptance_criteria: list<string>, assumptions: list<string>, tasks: list<string>, steps: list<array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>, acceptance: list<string>, solution_key: string|null, capabilities: list<string>, understood_as: string|null, current_behavior: string|null, preserve: list<array{area: string|null, statement: string}>}
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
            'capabilities' => $this->capabilities,
            'understood_as' => $this->understoodAs,
            'current_behavior' => $this->currentBehavior,
            'preserve' => $this->preserve,
        ];
    }
}
