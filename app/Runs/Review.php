<?php

namespace App\Runs;

use App\Runs\Exceptions\ConstructionFailed;
use Illuminate\Support\Facades\Validator;

/**
 * A reviewer's verdict on a verified change, with findings tied to evidence,
 * and the change described as behaviour the owner would notice.
 */
final readonly class Review
{
    /**
     * @param  list<array{severity: string, summary: string, file: string|null}>  $findings
     * @param  list<array{area: string|null, behavior: string, before: string, now: string}>  $changes
     */
    public function __construct(
        public bool $approved,
        public string $summary,
        public array $findings = [],
        public array $changes = [],
    ) {}

    /**
     * Build a review from model output. A review is only approving when the
     * reviewer approves and reports no blocking findings.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ConstructionFailed
     */
    public static function fromModelOutput(array $data): self
    {
        $validator = Validator::make($data, [
            'approved' => ['required', 'boolean'],
            'summary' => ['required', 'string', 'max:2000'],
            'findings' => ['present', 'array', 'max:30'],
            'findings.*.severity' => ['required', 'string', 'in:blocking,minor'],
            'findings.*.summary' => ['required', 'string', 'max:2000'],
            'findings.*.file' => ['nullable', 'string', 'max:500'],
            'changes' => ['sometimes', 'array', 'max:30'],
            'changes.*.area' => ['nullable', 'string', 'max:60'],
            'changes.*.behavior' => ['required', 'string', 'max:200'],
            'changes.*.before' => ['required', 'string', 'max:1000'],
            'changes.*.now' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            throw new ConstructionFailed(__('The reviewer returned an invalid review: :errors', ['errors' => implode(' ', $validator->errors()->all())]));
        }

        /** @var array{approved: bool, summary: string, findings: array<int, array{severity: string, summary: string, file?: string|null}>, changes?: array<int, array{area?: string|null, behavior: string, before: string, now: string}>} $valid */
        $valid = $validator->validated();

        $findings = array_values(array_map(fn (array $finding) => [
            'severity' => $finding['severity'],
            'summary' => $finding['summary'],
            'file' => $finding['file'] ?? null,
        ], $valid['findings']));

        $blocking = array_filter($findings, fn (array $finding) => $finding['severity'] === 'blocking');

        $changes = array_values(array_map(fn (array $change) => [
            'area' => $change['area'] ?? null,
            'behavior' => $change['behavior'],
            'before' => $change['before'],
            'now' => $change['now'],
        ], $valid['changes'] ?? []));

        return new self((bool) $valid['approved'] && $blocking === [], $valid['summary'], $findings, $changes);
    }

    /**
     * Get the findings that must be fixed before the change can be accepted.
     *
     * @return list<array{severity: string, summary: string, file: string|null}>
     */
    public function blockingFindings(): array
    {
        return array_values(array_filter($this->findings, fn (array $finding) => $finding['severity'] === 'blocking'));
    }
}
