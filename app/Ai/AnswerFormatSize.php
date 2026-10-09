<?php

namespace App\Ai;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\ObjectSchema;

/**
 * Measure an agent's answer format the way the AI service limits it. The
 * service compiles the format into a grammar and refuses every request
 * when it is too large, so a format that grows past the limit stops the
 * agent in production. A test holds each agent under the ceiling instead.
 */
class AnswerFormatSize
{
    /**
     * The largest size Anthropic accepted on 2026-10-05, measured with real
     * planner calls. The planner at 58 was accepted; every variant at 60
     * or more was refused ("The compiled grammar is too large"), whether
     * the new parts were objects, lists or plain texts. Fixed choices and
     * "or nothing" barely changed the outcome. Measure again before
     * raising it.
     */
    public const CEILING = 58;

    /**
     * Get the size of an agent's answer format: each field of an object,
     * at any depth, and each object.
     *
     * @param  class-string<HasStructuredOutput>|HasStructuredOutput  $agent
     */
    public static function of(string|HasStructuredOutput $agent): int
    {
        $agent = is_string($agent) ? app($agent) : $agent;

        return self::measure((new ObjectSchema($agent->schema(new JsonSchemaTypeFactory)))->toSchema());
    }

    /**
     * Say which agents' formats are over the ceiling, and by how much.
     *
     * @param  list<class-string<HasStructuredOutput>>  $agents
     * @return list<string>
     */
    public static function tooLarge(array $agents): array
    {
        $over = [];

        foreach ($agents as $agent) {
            $size = self::of($agent);

            if ($size > self::CEILING) {
                $over[] = sprintf('%s answers in a format of size %d; the AI service refuses more than %d.', class_basename($agent), $size, self::CEILING);
            }
        }

        return $over;
    }

    /**
     * @param  array<mixed>  $node
     */
    protected static function measure(array $node): int
    {
        $size = 0;

        if (in_array('object', (array) ($node['type'] ?? []), true)) {
            $size++;

            foreach ((array) ($node['properties'] ?? []) as $property) {
                $size += 1 + (is_array($property) ? self::measure($property) : 0);
            }
        }

        if (is_array($node['items'] ?? null)) {
            $size += self::measure($node['items']);
        }

        return $size;
    }
}
