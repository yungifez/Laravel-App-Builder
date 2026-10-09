<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\FeaturePlanner;
use App\Ai\AnswerFormatSize;
use App\Ai\StructuredAgents;
use Tests\Fixtures\LargeAgents\NestedWriter;
use Tests\TestCase;

/**
 * A format the AI service would refuse fails here, not for owners: on
 * 2026-10-05 one more field in the planner's format stopped every plan.
 */
class AnswerFormatSizeTest extends TestCase
{
    public function test_the_planner_format_is_under_the_ceiling(): void
    {
        $this->assertLessThanOrEqual(AnswerFormatSize::CEILING, AnswerFormatSize::of(FeaturePlanner::class));
    }

    public function test_every_agent_format_is_under_the_ceiling(): void
    {
        $agents = StructuredAgents::in([app_path('Ai/Agents')]);

        $this->assertNotEmpty($agents);
        $this->assertSame([], AnswerFormatSize::tooLarge($agents));
    }

    public function test_one_more_nested_object_than_the_ceiling_names_the_agent(): void
    {
        $this->assertSame(AnswerFormatSize::CEILING + 2, AnswerFormatSize::of(NestedWriter::class));
        $this->assertSame(
            [sprintf('NestedWriter answers in a format of size %d; the AI service refuses more than %d.', AnswerFormatSize::CEILING + 2, AnswerFormatSize::CEILING)],
            AnswerFormatSize::tooLarge([NestedWriter::class, FeaturePlanner::class]),
        );
    }
}
