<?php

namespace App\Http\Controllers;

use App\Actions\Runs\CancelRun;
use App\Models\Run;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class RunCancellationController extends Controller
{
    /**
     * Ask a construction run to stop.
     */
    public function store(Run $run, CancelRun $cancelRun): RedirectResponse
    {
        Gate::authorize('requestFeatures', $run->featureRequest->project);

        $cancelRun->handle($run);

        return back();
    }
}
