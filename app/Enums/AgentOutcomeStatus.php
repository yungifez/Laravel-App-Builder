<?php

namespace App\Enums;

/**
 * How one coding agent attempt ended.
 */
enum AgentOutcomeStatus: string
{
    /** The agent finished the task. */
    case Completed = 'completed';

    /** The provider could not serve the task (down, overloaded, rate-limited, out of quota or refusing the credentials). */
    case ProviderUnavailable = 'provider_unavailable';

    /** The agent ran and failed, or used up its turns or budget. */
    case Failed = 'failed';
}
