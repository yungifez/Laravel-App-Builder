<?php

namespace App\Enums;

/**
 * Which project context a run's agents receive. "selective" is the product;
 * the others are the comparison conditions for the context experiments.
 */
enum ContextMode: string
{
    /** Only the repository and the request. */
    case None = 'none';

    /** Every context file, flat, as one project notes document. */
    case Flat = 'flat';

    /** The project notes and the files of the areas the change is about, with their Effects. */
    case Selective = 'selective';

    /** As selective, without the Effects. */
    case SelectiveWithoutEffects = 'selective_without_effects';
}
