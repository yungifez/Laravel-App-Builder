<?php

namespace App\Runs\Contracts;

use App\Models\Run;
use App\Runs\Plan;
use App\Runs\PlanningContext;
use App\Runs\Review;
use App\Runs\ReviewEvidence;
use App\Runs\ToolSession;

interface ConstructionDriver
{
    /**
     * Turn the request into a plan to build against.
     */
    public function plan(Run $run, PlanningContext $context): Plan;

    /**
     * Make the planned change in the workspace, using only the session's
     * tools. When the run has feedback, address it. Returns the driver's own
     * account of what it did, which is logged but never trusted.
     */
    public function build(Run $run, Plan $plan, ToolSession $tools): string;

    /**
     * Judge the verified change from the platform's evidence.
     */
    public function review(Run $run, ReviewEvidence $evidence): Review;

    /**
     * Determine if the driver can repair a change that failed verification or review.
     */
    public function canRepair(): bool;
}
