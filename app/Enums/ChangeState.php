<?php

namespace App\Enums;

/**
 * Where one of the owner's asks stands, in the words the owner uses. A
 * request and its follow-ups ("change this step") are one ask to them.
 */
enum ChangeState: string
{
    case Waiting = 'waiting';
    case Working = 'working';
    case Kept = 'kept';
    case Stopped = 'stopped';
    case Undone = 'undone';
}
