<?php

namespace Tests\Unit;

use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeatureCoder;
use App\Ai\Agents\FeaturePlanner;
use App\Ai\Agents\NotesDrafter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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

        $this->assertDoesNotMatchRegularExpression('/platform|control plane|inspector|builder(?!\/)/i', $instructions);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function agents(): array
    {
        return [
            'planner' => [FeaturePlanner::class],
            'coder' => [FeatureCoder::class],
            'reviewer' => [ChangeReviewer::class],
            'notes' => [NotesDrafter::class],
        ];
    }
}
