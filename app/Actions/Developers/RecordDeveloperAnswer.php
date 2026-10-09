<?php

namespace App\Actions\Developers;

use App\Models\DeveloperReview;
use App\Models\User;
use App\Notifications\DeveloperAnswered;
use Illuminate\Validation\ValidationException;

/**
 * Keep what our developer answered. They can change it until the owner
 * keeps their guidance; after that it is part of the app's record and
 * stays as the owner kept it.
 */
class RecordDeveloperAnswer
{
    /**
     * Record the answer. Findings and guidance are one point per line.
     *
     * @throws ValidationException when the owner already kept the guidance.
     */
    public function handle(DeveloperReview $review, User $developer, string $summary, string $findings, string $guidance): DeveloperReview
    {
        if ($review->guidance_kept_at !== null || $review->withdrawn_at !== null) {
            throw ValidationException::withMessages(['summary' => __('The owner already kept the guidance or took the question back, so the answer can no longer change.')]);
        }

        // Told once, when the first answer arrives; an edit is not news.
        $first = $review->answered_at === null;

        $review->update([
            'answered_by' => $developer->id,
            'answer' => [
                'summary' => trim($summary),
                'findings' => self::lines($findings),
                'guidance' => self::lines($guidance),
            ],
            'answered_at' => $review->answered_at ?? now(),
        ]);

        if ($first) {
            $review->project->owner->notify(new DeveloperAnswered($review));
        }

        return $review;
    }

    /**
     * Split text into its points, one per line, without list marks.
     *
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        return array_values(array_filter(array_map(
            fn (string $line) => trim((string) preg_replace('/^\s*(?:[-*•]|\d+[.)])\s+/', '', $line)),
            preg_split('/\R/', $text) ?: [],
        ), fn (string $line) => $line !== ''));
    }
}
