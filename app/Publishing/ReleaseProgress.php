<?php

namespace App\Publishing;

enum ReleaseProgress: string
{
    // The host does not report on releases; the app's address decides.
    case Unknown = 'unknown';

    case Pending = 'pending';

    case Live = 'live';

    // The host could not build or start the new version. The old one is
    // still online.
    case Failed = 'failed';
}
