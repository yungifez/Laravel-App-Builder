<?php

namespace Tests\Fixtures\LargeAgents;

use App\Ai\AnswerFormatSize;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * An agent whose format is exactly at the ceiling, plus one more nested
 * object: the smallest step the AI service would refuse.
 */
class NestedWriter implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Write nested notes.';
    }

    public function schema(JsonSchema $schema): array
    {
        // The root object and each text count one each, so these are the
        // ceiling; the nested object then adds its field and itself.
        $fields = [];

        for ($field = 1; $field < AnswerFormatSize::CEILING; $field++) {
            $fields["note_{$field}"] = $schema->string()->required();
        }

        return [...$fields, 'more' => $schema->object([])->withoutAdditionalProperties()->required()];
    }
}
