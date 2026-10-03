<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DeveloperApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's request to answer owners' questions as one of our developers
 * (architecture §29.3). Approved, they answer under "Questions for
 * developers" without seeing the rest of operations.
 *
 * @property int $id
 * @property int $user_id
 * @property string $about What they have built and know, in their words
 * @property string|null $link Where we can see their work
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $declined_at Also set when an operator takes access away
 * @property int|null $decided_by The operator who approved or declined
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'about', 'link', 'approved_at', 'declined_at', 'decided_by'])]
class DeveloperApplication extends Model
{
    /** @use HasFactory<DeveloperApplicationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    /**
     * Get the person who applied.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the operator who decided.
     *
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Determine if the person may answer questions now.
     */
    public function approved(): bool
    {
        return $this->approved_at !== null && $this->declined_at === null;
    }

    /**
     * Determine if the application waits for an operator.
     */
    public function waiting(): bool
    {
        return $this->approved_at === null && $this->declined_at === null;
    }
}
