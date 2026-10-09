<?php

namespace App\Enums;

/**
 * Where a publish is. Sending the code and the app being online are
 * separate: the hosting platform can still fail to build or start it, so
 * "published" means the app answered its checks at its address afterwards.
 */
enum DeploymentStatus: string
{
    case Checking = 'checking';
    case Pushing = 'pushing';
    // Sent; waiting for the app's address to answer its checks.
    case Confirming = 'confirming';
    // Sent, but with no address to check, nobody knows if it is online.
    case Sent = 'sent';
    case Published = 'published';
    // Sent, but the app did not answer its checks in time.
    case NeedsAttention = 'needs_attention';
    case Failed = 'failed';

    /**
     * Determine if the deployment is still in progress.
     */
    public function active(): bool
    {
        return in_array($this, [self::Checking, self::Pushing, self::Confirming], true);
    }
}
