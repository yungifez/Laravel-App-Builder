<?php

namespace App\Enums;

enum WorkspaceStatus: string
{
    case Provisioning = 'provisioning';
    case Ready = 'ready';
    case Failed = 'failed';
    case Destroyed = 'destroyed';
}
