<?php

namespace App\Enums;

enum NotesDraftStatus: string
{
    case Drafting = 'drafting';
    case Ready = 'ready';
    case Failed = 'failed';
}
