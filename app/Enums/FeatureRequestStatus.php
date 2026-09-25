<?php

namespace App\Enums;

enum FeatureRequestStatus: string
{
    case Generating = 'generating';
    case Generated = 'generated';
    case Failed = 'failed';
}
