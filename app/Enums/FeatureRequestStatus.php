<?php

namespace App\Enums;

enum FeatureRequestStatus: string
{
    case Generating = 'generating';
    case Generated = 'generated';

    // The owner only asked about the app: nothing was built.
    case Answered = 'answered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
