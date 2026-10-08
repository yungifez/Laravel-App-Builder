<?php

namespace App\Http\Resources;

use App\Models\Run;
use App\Models\RunEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A construction run: its plan, context, review, budget and event log.
 *
 * @mixin Run
 */
class RunResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'driver' => $this->driver,
            'error' => $this->error,
            'workspace_revision' => $this->workspace_revision,
            'plan' => $this->plan === null ? null : [
                'summary' => $this->plan['summary'],
                'acceptance_criteria' => $this->plan['acceptance_criteria'],
                'assumptions' => $this->plan['assumptions'],
                'understood_as' => $this->plan['understood_as'] ?? null,
                'current_behavior' => $this->plan['current_behavior'] ?? null,
                'preserve' => array_column($this->plan['preserve'] ?? [], 'statement'),
            ],
            'context' => $this->context === null ? null : [
                'mode' => $this->context['mode'],
                'targets' => $this->context['targets'],
                'included' => $this->context['included'],
                'tokens' => array_sum(array_column($this->context['included'], 'tokens')),
                'problems' => $this->context['problems'],
            ],
            'review' => $this->review(),
            'repairs' => $this->repairs,
            'operations' => $this->operations()->count(),
            'budget' => [
                'operations' => (int) config('builder.construction.budgets.operations'),
                'minutes' => (int) config('builder.construction.budgets.minutes'),
                'repairs' => (int) config('builder.construction.budgets.repairs'),
            ],
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'events' => $this->events()->get()->map(fn (RunEvent $event) => [
                'sequence' => $event->sequence,
                'type' => $event->type,
                'data' => $event->data ?? [],
                'created_at' => $event->created_at?->toIso8601String(),
            ]),
        ];
    }

    /**
     * Get the run's review for the owner: the behaviour changes and where the
     * change landed by area, with the areas' names.
     *
     * @return array<string, mixed>|null
     */
    protected function review(): ?array
    {
        if ($this->review === null) {
            return null;
        }

        $names = array_column($this->context['outline'] ?? [], 'name', 'key');
        $classification = $this->review['classification'];
        $areas = fn (array $files) => array_map(
            fn (string $key) => ['key' => $key, 'name' => $names[$key] ?? $key, 'files' => $files[$key]],
            array_keys($files),
        );

        return [
            'summary' => $this->review['summary'],
            'changes' => array_map(fn (array $change) => [
                ...$change,
                'area_name' => $change['area'] === null ? null : ($names[$change['area']] ?? $change['area']),
            ], $this->review['changes']),
            'areas' => [
                'requested' => $areas($classification['requested']),
                'may_also_affect' => $areas($classification['may_also_affect']),
                'unexpected' => $areas($classification['unexpected']),
            ],
            'preserved' => array_map(fn (array $item) => [
                ...$item,
                'area_name' => $item['area'] === null ? null : ($names[$item['area']] ?? $item['area']),
            ], $this->review['preserved'] ?? []),
            'unclaimed' => $classification['unclaimed'],
            'context_updates' => $classification['context_updates'],
        ];
    }
}
