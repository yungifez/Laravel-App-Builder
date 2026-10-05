<?php

namespace App\Ai\Attributes;

use App\Enums\ModelRole;
use Attribute;

/**
 * The model tier an agent runs on. ai:check-formats tries each agent's
 * answer format on this tier's providers.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Tier
{
    public function __construct(public ModelRole $role) {}
}
