<?php

namespace App\Runs\Contracts;

use App\Models\Run;
use App\Runs\BuiltChange;
use App\Runs\ToolSession;

interface ConstructionDriver
{
    /**
     * Make the run's change in its workspace, using only the session's tools.
     */
    public function build(Run $run, ToolSession $tools): BuiltChange;
}
