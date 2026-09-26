<?php

namespace App\Operations;

/**
 * How recorded model calls are priced. A cost is "reported" when the
 * provider's SDK said what the call cost, "estimated" when we priced its
 * tokens from config/builder.php, and missing when neither was possible. A
 * missing cost is unknown, never zero.
 */
class ModelCalls
{
    /**
     * SQL for a model_call event's cost source, including events recorded
     * before the source was: the coding agent's calls (they carry an
     * "adapter") were always reported, and priced SDK calls were estimated.
     */
    public const SOURCE_SQL = "case when (data->>'cost_usd') is null then null when (data->>'cost_source') is not null then data->>'cost_source' when (data->>'adapter') is not null then 'reported' else 'estimated' end";

    /**
     * Get a recorded call's cost source.
     *
     * @param  array<string, mixed>  $data
     */
    public static function source(array $data): ?string
    {
        if (! is_numeric($data['cost_usd'] ?? null)) {
            return null;
        }

        if (is_string($data['cost_source'] ?? null)) {
            return $data['cost_source'];
        }

        return isset($data['adapter']) ? 'reported' : 'estimated';
    }
}
