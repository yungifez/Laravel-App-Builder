<?php

namespace Tests\Feature\Runs;

use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeaturePlanner;
use App\Ai\Agents\NotesDrafter;
use App\Ai\Agents\NotesKeeper;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

class AgentInstructionsTest extends TestCase
{
    /**
     * The models write into repositories customers can own, so they must
     * not learn that anything besides the app and its developer exists.
     *
     * @param  class-string  $agent
     */
    #[DataProvider('agents')]
    public function test_instructions_never_mention_what_sits_behind_the_app(string $agent)
    {
        $instructions = (string) (new ReflectionClass($agent))->newInstanceWithoutConstructor()->instructions();

        $this->assertDoesNotMatchRegularExpression('/platform|control plane|inspector|builder/i', $instructions);
    }

    public function test_the_reviewer_holds_every_change_to_the_guidance_the_owner_kept()
    {
        $instructions = (string) (new ChangeReviewer)->instructions();

        $this->assertStringContainsString('goes against a point under "Engineering direction"', $instructions);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function agents(): array
    {
        return [
            'planner' => [FeaturePlanner::class],
            'reviewer' => [ChangeReviewer::class],
            'notes' => [NotesDrafter::class],
            'notes keeper' => [NotesKeeper::class],
        ];
    }
}
