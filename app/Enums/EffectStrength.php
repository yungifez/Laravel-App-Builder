<?php

namespace App\Enums;

/**
 * How strongly an Effect suggests that one area of the product relates to
 * another. A hint, never a measured probability.
 */
enum EffectStrength: string
{
    case Strong = 'strong';
    case Possible = 'possible';
    case Historical = 'historical';
}
