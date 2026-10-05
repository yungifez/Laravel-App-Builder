<?php

namespace App\Enums;

/**
 * Which of a coding agent's models takes a task (config/builder.php
 * "agents.adapters"): the light one for small, well-defined work, the usual
 * one, or the strong one for a repair the usual one could not finish.
 */
enum AgentTier: string
{
    case Light = 'light';
    case Usual = 'usual';
    case Strong = 'strong';
}
