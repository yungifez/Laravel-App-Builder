<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The last time one of our agents' answer formats was tried against its
 * model (ai:check-formats). A format the AI service refuses fails every
 * call to that agent, and fakes in tests cannot show it, so a refused one
 * is kept here until a later check accepts it.
 *
 * @property int $id
 * @property string $agent The agent's class
 * @property string|null $role The model tier it runs on, or null when it names none
 * @property bool $accepted
 * @property string|null $reason Why it failed: request_refused, providers_unavailable, out_of_credit, no_tier or ours
 * @property array{status?: int, type?: string|null}|null $service_error What the AI service said, never what we asked
 * @property string|null $message
 * @property CarbonImmutable $checked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['agent', 'role', 'accepted', 'reason', 'service_error', 'message', 'checked_at'])]
class AnswerFormatCheck extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted' => 'boolean',
            'service_error' => 'array',
            'checked_at' => 'datetime',
        ];
    }
}
