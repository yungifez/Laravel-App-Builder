<?php

namespace App\Runs;

use App\Runs\Exceptions\ConstructionFailed;
use Illuminate\Support\Facades\Validator;

/**
 * A reviewer's verdict on a verified change, with findings tied to evidence.
 */
final readonly class Review
{
    /**
     * @param  list<array{severity: string, summary: string, file: string|null}>  $findings
     */
    public function __construct(
        public bool $approved,
        public string $summary,
        public array $findings = [],
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
        ]);

        if ($validator->fails()) {
            throw new ConstructionFailed(__('The reviewer returned an invalid review: :errors', ['errors' => implode(' ', $validator->errors()->all())]));
        }

        /** @var array{approved: bool, summary: string, findings: array<int, array{severity: string, summary: string, file?: string|null}>} $valid */
        $valid = $validator->validated();

        $findings = array_values(array_map(fn (array $finding) => [
            'severity' => $finding['severity'],
            'summary' => $finding['summary'],
            'file' => $finding['file'] ?? null,
        ], $valid['findings']));

        $blocking = array_filter($findings, fn (array $finding) => $finding['severity'] === 'blocking');

        return new self((bool) $valid['approved'] && $blocking === [], $valid['summary'], $findings);
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
