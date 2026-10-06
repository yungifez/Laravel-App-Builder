<?php

namespace App\Runs;

use App\Runs\Exceptions\ConstructionFailed;
use App\Scaffolding\FieldType;
use App\Scaffolding\Scaffold;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The saved intent a run builds against, also shown as the change brief:
 * how the request was understood, what the application does now, what the
 * change does, what must stay as it is, how to tell it is done, and the steps
 * the owner can later select and change.
 *
 * The protected acceptance suites are chosen by the platform, never by the
 * model that writes the plan.
 *
 * @phpstan-import-type Record from Scaffold
 */
final readonly class Plan
{
    /**
     * @param  list<string>  $acceptanceCriteria
     * @param  list<Assumption>  $assumptions  What was decided where the request was silent
     * @param  list<string>  $tasks
     * @param  list<array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>  $steps
     * @param  list<string>  $acceptance  Protected acceptance test files that apply to the change
     * @param  list<string>  $capabilities  The areas of the product (capability notes) the change is about
     * @param  list<array{area: string|null, statement: string}>  $preserve  What must stay as it is, by area
     * @param  array{text: string, why: string, options: list<string>, recommended: string|null, touches?: list<string>, reversible?: bool, easier_after_seeing?: bool}|null  $question  The one product question to ask the owner before building, if any, with what a wrong guess would touch
     * @param  string|null  $commitSubject  How the app's own developer would name the commit; the owner's words never reach the repository
     * @param  string|null  $answer  The reply when the owner only asked about the app, so nothing is built
     * @param  list<string>  $next  What the owner might ask for next, in their words, offered as one-tap follow-ups
     * @param  string|null  $goal  How the change serves the goal the owner wrote in the notes, if it does
     * @param  list<Record>  $dataShape  The records the change stores, stated once so every part agrees (§9)
     * @param  list<array{criterion: int, kind: string, says: string|null, none: string|null}>  $cases  How each criterion is tried: the main way, another way and a refusal, or why one cannot apply. Criteria are numbered from 1.
     * @param  list<array{item: int, file: string, name: string}>  $writtenTests  The test written before the change for each item of verifyItems(), numbered from 1
     * @param  array<string, string>  $writtenFiles  The files those tests are in, by path: written into the workspace before the coder starts and put back after it
     * @param  bool  $newRecords  Whether the planner said the change stores a new kind of record, so the shape is asked for; not saved, the shape is
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
        public ?string $commitSubject = null,
        public ?string $answer = null,
        public array $next = [],
        public ?string $goal = null,
        public array $dataShape = [],
        public array $cases = [],
        public array $writtenTests = [],
        public array $writtenFiles = [],
        public bool $newRecords = false,
    ) {}

    /**
     * The ways each criterion is tried, in order. A test for each one that
     * applies shows the criterion holds on more than its easiest path.
     */
    public const CASES = ['base', 'alternate', 'exception'];

    /**
     * Get what the change's tests must check, numbered from 1: one item for
     * each case of each criterion that applies.
     *
     * @return list<array{criterion: string, kind: string, text: string}>
     */
    public function verifyItems(): array
    {
        $items = [];

        foreach ($this->cases as $case) {
            $criterion = $this->acceptanceCriteria[$case['criterion'] - 1] ?? null;

            if ($criterion !== null && $case['says'] !== null) {
                $items[] = ['criterion' => $criterion, 'kind' => $case['kind'], 'text' => "{$criterion} ({$case['kind']} case: {$case['says']})"];
            }
        }

        return $items;
    }

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
        // A reply to a question builds nothing, so it needs no tasks,
        // criteria or steps.
        $build = blank($data['answer'] ?? null) ? ['required', 'array', 'min:1'] : ['present', 'array'];

        $validator = Validator::make($data, [
            'summary' => ['required', 'string', 'max:2000'],
            'answer' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'acceptance_criteria' => [...$build, 'max:30'],
            'acceptance_criteria.*' => ['required', 'string', 'max:1000'],
            'assumptions' => ['present', 'array', 'max:30'],
            'assumptions.*' => ['required', 'string', 'max:1000'],
            'tasks' => [...$build, 'max:30'],
            'tasks.*' => ['required', 'string', 'max:2000'],
            'steps' => [...$build, 'max:20'],
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
            'question.touches' => ['sometimes', 'array', 'max:10'],
            'question.touches.*' => ['string', 'max:40'],
            'question.reversible' => ['sometimes', 'boolean'],
            'question.easier_after_seeing' => ['sometimes', 'boolean'],
            'commit_subject' => ['sometimes', 'nullable', 'string', 'max:100'],
            'next' => ['sometimes', 'array', 'max:10'],
            'next.*' => ['string', 'max:500'],
            'goal' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            throw new ConstructionFailed(__('The planner returned an invalid plan: :errors', ['errors' => implode(' ', $validator->errors()->all())]));
        }

        /** @var array{summary: string, acceptance_criteria: array<int, string>, assumptions: array<int, string>, tasks: array<int, string>, steps: array<int, array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>, capabilities?: array<int, string>, understood_as?: string|null, current_behavior?: string|null, preserve?: array<int, array{area?: string|null, statement: string}>, question?: array{text: string, why?: string|null, options: array<int, string>, recommended?: string|null, touches?: array<int, string>, reversible?: bool, easier_after_seeing?: bool}|null, commit_subject?: string|null, answer?: string|null, next?: array<int, string>, goal?: string|null} $valid */
        $valid = $validator->validated();

        return new self(
            summary: $valid['summary'],
            acceptanceCriteria: array_values($valid['acceptance_criteria']),
            // The planner says nothing about what a guess touches, so each
            // is worth a glance until code can tell.
            assumptions: array_values(array_map(fn (string $text) => new Assumption(trim($text), reversible: false), $valid['assumptions'])),
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
            commitSubject: self::commitSubject($valid['commit_subject'] ?? null),
            answer: filled($valid['answer'] ?? null) ? trim($valid['answer']) : null,
            next: self::next($valid['next'] ?? []),
            goal: filled($valid['goal'] ?? null) ? trim($valid['goal']) : null,
            cases: self::cases($data['cases'] ?? null, count($valid['acceptance_criteria'])),
            newRecords: ($data['new_records'] ?? false) === true,
        );
    }

    /**
     * Read how each criterion is tried. Every criterion has a main way;
     * another way and a refusal each say what is tried or why it cannot
     * apply, so a missing case is a decision someone can read, never a
     * silence.
     *
     * @return list<array{criterion: int, kind: string, says: string|null, none: string|null}>
     *
     * @throws ConstructionFailed
     */
    public static function cases(mixed $cases, int $criteria): array
    {
        $validator = Validator::make(['cases' => $cases], [
            'cases' => ['present', 'array', "size:{$criteria}"],
            'cases.*.base' => ['required', 'string', 'max:500'],
            ...collect(['alternate', 'exception'])->flatMap(fn (string $kind) => [
                "cases.*.{$kind}" => ['nullable', 'string', 'max:500', "required_without:cases.*.no_{$kind}"],
                "cases.*.no_{$kind}" => ['nullable', 'string', 'max:500'],
            ])->all(),
        ], [
            'cases.size' => 'Give the cases of each acceptance criterion, in the same order.',
        ]);

        if ($validator->fails()) {
            throw new ConstructionFailed(__('The planner returned an invalid plan: :errors', ['errors' => implode(' ', $validator->errors()->all())]));
        }

        $read = [];

        foreach (array_values($validator->validated()['cases']) as $index => $case) {
            foreach (self::CASES as $kind) {
                $says = filled($case[$kind] ?? null) ? trim($case[$kind]) : null;

                $read[] = [
                    'criterion' => $index + 1,
                    'kind' => $kind,
                    'says' => $says,
                    'none' => $says === null ? trim((string) $case["no_{$kind}"]) : null,
                ];
            }
        }

        return $read;
    }

    /**
     * Read the records the change stores. A shape that does not hold
     * together is dropped, not refused: the coding agent then writes those
     * parts itself, as it would without a shape.
     *
     * @return list<Record>
     */
    public static function dataShape(mixed $shape): array
    {
        $name = 'regex:/^[A-Z][A-Za-z0-9]{0,59}$/';
        $validator = Validator::make(['records' => $shape], [
            'records' => ['array', 'max:10'],
            'records.*.name' => ['required', 'string', $name, 'distinct'],
            'records.*.fields' => ['required', 'array', 'min:1', 'max:40'],
            'records.*.fields.*.name' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,59}$/', 'not_in:id,created_at,updated_at'],
            'records.*.fields.*.type' => ['required', Rule::enum(FieldType::class)->only(FieldType::planned())],
            'records.*.fields.*.required' => ['required', 'boolean'],
            'records.*.fields.*.choices' => ['present', 'array', 'max:20'],
            'records.*.fields.*.choices.*' => ['string', 'regex:/^[a-z0-9_]{1,60}$/'],
            'records.*.fields.*.of' => ['present', 'nullable', 'string', $name],
            'records.*.label' => ['sometimes', 'nullable', 'string', 'max:80'],
            'records.*.fields.*.label' => ['sometimes', 'nullable', 'string', 'max:80'],
            'records.*.access' => ['sometimes', 'nullable', 'array:'.implode(',', Scaffold::ACTIONS)],
            ...collect(Scaffold::ACTIONS)->mapWithKeys(fn (string $action) => [
                "records.*.access.{$action}" => ['required_with:records.*.access', Rule::in(Scaffold::WHO)],
            ])->all(),
        ]);

        if ($validator->fails()) {
            return [];
        }

        /** @var list<Record> $records */
        $records = array_values($validator->validated()['records']);
        $read = [];

        foreach ($records as $record) {
            $fields = [];

            foreach ($record['fields'] as $field) {
                $field['of'] = filled($field['of'] ?? null) ? $field['of'] : null;
                $type = FieldType::from($field['type']);
                $choices = array_values(array_unique($field['choices']));

                if (($type === FieldType::Choice && count($choices) < 2) || ($type === FieldType::BelongsTo && $field['of'] === null)) {
                    return [];
                }

                $fields[FieldType::attribute($field)] = [
                    'name' => $field['name'],
                    'type' => $type->value,
                    'required' => (bool) $field['required'],
                    'choices' => $type === FieldType::Choice ? $choices : [],
                    'of' => $type === FieldType::BelongsTo ? $field['of'] : null,
                    ...self::label($field['label'] ?? null),
                ];
            }

            // Two fields stored in one column cannot both be meant.
            if (count($fields) !== count($record['fields'])) {
                return [];
            }

            $access = $record['access'] ?? null;

            // Only the person who made a record can be its creator, so the
            // record must say who made it.
            if ($access !== null && in_array('creator', $access, true) && Scaffold::creator(array_values($fields)) === null) {
                return [];
            }

            $read[] = [
                'name' => $record['name'],
                'fields' => array_values($fields),
                'access' => $access === null ? null : array_map(strval(...), array_intersect_key($access, array_flip(Scaffold::ACTIONS))),
                ...self::label($record['label'] ?? null),
            ];
        }

        return $read;
    }

    /**
     * Keep what the owner calls a record or field, when the planner said.
     *
     * @return array{label?: string}
     */
    protected static function label(?string $label): array
    {
        $label = Str::squish((string) $label);

        return $label === '' ? [] : ['label' => $label];
    }

    /**
     * Keep up to three distinct, short follow-up ideas. An idea too long to
     * read at a glance is dropped rather than cut mid-sentence.
     *
     * @param  array<int, string>  $ideas
     * @return list<string>
     */
    protected static function next(array $ideas): array
    {
        $ideas = array_map(Str::squish(...), $ideas);
        $ideas = array_filter($ideas, fn (string $idea) => $idea !== '' && mb_strlen($idea) <= 120);

        return array_slice(array_values(array_unique($ideas)), 0, 3);
    }

    /**
     * Read the planner's question. A recommendation that is not one of the
     * options is dropped rather than shown as a choice the owner cannot make.
     * What a wrong guess would touch is kept only when the planner said.
     *
     * @param  array{text: string, why?: string|null, options: array<int, string>, recommended?: string|null, touches?: array<int, string>, reversible?: bool, easier_after_seeing?: bool}  $question
     * @return array{text: string, why: string, options: list<string>, recommended: string|null, touches?: list<string>, reversible?: bool, easier_after_seeing?: bool}
     */
    protected static function question(array $question): array
    {
        $options = array_values(array_map('trim', $question['options']));
        $recommended = $question['recommended'] ?? null;

        $read = [
            'text' => trim($question['text']),
            'why' => trim($question['why'] ?? ''),
            'options' => $options,
            'recommended' => in_array($recommended, $options, true) ? $recommended : null,
        ];

        if (isset($question['touches'])) {
            $read['touches'] = array_values($question['touches']);
        }

        if (isset($question['reversible'])) {
            $read['reversible'] = (bool) $question['reversible'];
        }

        if (isset($question['easier_after_seeing'])) {
            $read['easier_after_seeing'] = (bool) $question['easier_after_seeing'];
        }

        return $read;
    }

    /**
     * Determine if the question must wait for the owner (§7): a wrong guess
     * would touch one of the given consequences and is hard to take back,
     * and seeing the change first would not make it easier to judge. A
     * question without a recommendation, or that the planner did not tag,
     * is always asked, since there is nothing safe to build on.
     *
     * @param  list<string>  $consequences  What a wrong guess must touch to be worth asking about
     */
    public function asksOwner(array $consequences): bool
    {
        $question = $this->question;

        if ($question === null) {
            return false;
        }

        if ($question['recommended'] === null || ! isset($question['touches'], $question['reversible'], $question['easier_after_seeing'])) {
            return true;
        }

        return array_intersect($question['touches'], $consequences) !== []
            && ! $question['reversible']
            && ! $question['easier_after_seeing'];
    }

    /**
     * Keep the tests written for the plan before the change is built.
     *
     * @param  array<string, string>  $files
     * @param  list<array{item: int, file: string, name: string}>  $tests
     */
    public function withWrittenTests(array $files, array $tests): self
    {
        return new self(...[...get_object_vars($this), 'writtenFiles' => $files, 'writtenTests' => $tests]);
    }

    /**
     * Keep the records the change stores, as the owner answered about them.
     *
     * @param  list<Record>  $dataShape
     */
    public function withDataShape(array $dataShape): self
    {
        return new self(...[...get_object_vars($this), 'dataShape' => $dataShape]);
    }

    /**
     * Get what was decided for the owner, as text.
     *
     * @return list<string>
     */
    public function assumptionTexts(): array
    {
        return array_map(fn (Assumption $assumption) => $assumption->text, $this->assumptions);
    }

    /**
     * Build on the recommended option instead of asking, and list the choice
     * with the other decisions, where the owner reviews it with the change.
     */
    public function decidedOnRecommendation(): self
    {
        if ($this->question === null || $this->question['recommended'] === null) {
            return $this;
        }

        return new self(...[
            ...get_object_vars($this),
            'question' => null,
            'assumptions' => [
                ...$this->assumptions,
                new Assumption(
                    text: __(':question I went with: :option.', ['question' => $this->question['text'], 'option' => rtrim($this->question['recommended'], '.')]),
                    touches: Assumption::touches($this->question['touches'] ?? []),
                    reversible: $this->question['reversible'] ?? true,
                    easierAfterSeeing: $this->question['easier_after_seeing'] ?? false,
                ),
            ],
        ]);
    }

    /**
     * Keep the commit subject to one plain line.
     */
    protected static function commitSubject(?string $subject): ?string
    {
        $subject = rtrim(Str::squish((string) $subject), '.');

        return $subject === '' ? null : Str::limit($subject, 72, '');
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
        $area = filled($item['area'] ?? null) ? $item['area'] : null;
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
     * @param  array{summary: string, acceptance_criteria: list<string>, assumptions: list<array{text: string, touches: list<string>, reversible: bool, easier_after_seeing: bool}>, tasks: list<string>, steps: list<array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>, acceptance: list<string>, solution_key: string|null, capabilities?: list<string>, understood_as?: string|null, current_behavior?: string|null, preserve?: list<array{area: string|null, statement: string}>, commit_subject?: string|null, answer?: string|null, next?: list<string>, goal?: string|null, data_shape?: list<Record>, cases: list<array{criterion: int, kind: string, says: string|null, none: string|null}>, written_tests: list<array{item: int, file: string, name: string}>, written_files: array<string, string>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            summary: $data['summary'],
            acceptanceCriteria: $data['acceptance_criteria'],
            assumptions: array_map(Assumption::fromArray(...), $data['assumptions']),
            tasks: $data['tasks'],
            steps: $data['steps'],
            acceptance: $data['acceptance'],
            solutionKey: $data['solution_key'],
            capabilities: $data['capabilities'] ?? [],
            understoodAs: $data['understood_as'] ?? null,
            currentBehavior: $data['current_behavior'] ?? null,
            preserve: array_map(self::preserveItem(...), $data['preserve'] ?? []),
            commitSubject: $data['commit_subject'] ?? null,
            answer: $data['answer'] ?? null,
            next: $data['next'] ?? [],
            goal: $data['goal'] ?? null,
            dataShape: $data['data_shape'] ?? [],
            cases: $data['cases'],
            writtenTests: $data['written_tests'],
            writtenFiles: $data['written_files'],
        );
    }

    /**
     * Get the plan as stored on the run.
     *
     * @return array{summary: string, acceptance_criteria: list<string>, assumptions: list<array{text: string, touches: list<string>, reversible: bool, easier_after_seeing: bool}>, tasks: list<string>, steps: list<array{key: string, kind: string, label: string, file: string, symbol: string, detail: string}>, acceptance: list<string>, solution_key: string|null, capabilities: list<string>, understood_as: string|null, current_behavior: string|null, preserve: list<array{area: string|null, statement: string}>, commit_subject: string|null, answer: string|null, next: list<string>, goal: string|null, data_shape: list<Record>, cases: list<array{criterion: int, kind: string, says: string|null, none: string|null}>, written_tests: list<array{item: int, file: string, name: string}>, written_files: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'acceptance_criteria' => $this->acceptanceCriteria,
            'assumptions' => array_map(fn (Assumption $assumption) => $assumption->toArray(), $this->assumptions),
            'tasks' => $this->tasks,
            'steps' => $this->steps,
            'acceptance' => $this->acceptance,
            'solution_key' => $this->solutionKey,
            'capabilities' => $this->capabilities,
            'understood_as' => $this->understoodAs,
            'current_behavior' => $this->currentBehavior,
            'preserve' => $this->preserve,
            'commit_subject' => $this->commitSubject,
            'answer' => $this->answer,
            'next' => $this->next,
            'goal' => $this->goal,
            'data_shape' => $this->dataShape,
            'cases' => $this->cases,
            'written_tests' => $this->writtenTests,
            'written_files' => $this->writtenFiles,
        ];
    }
}
