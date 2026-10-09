<?php

namespace App\Http\Controllers\Operations;

use App\Actions\Operations\DescribeChangeHistory;
use App\Actions\Operations\ListChanges;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListOperationsChangesRequest;
use App\Models\FeatureRequest;
use Inertia\Inertia;
use Inertia\Response;

class ChangeController extends Controller
{
    /**
     * List every owner's changes, filtered.
     */
    public function index(ListOperationsChangesRequest $request, ListChanges $listChanges): Response
    {
        return Inertia::render('operations/Changes', [
            'changes' => $listChanges->handle($request->filters()),
            'filters' => (object) $request->filters(),
            'options' => $listChanges->options(),
        ]);
    }

    /**
     * Show one change's history.
     */
    public function show(FeatureRequest $featureRequest, DescribeChangeHistory $describeChangeHistory): Response
    {
        return Inertia::render('operations/Change', [
            'history' => $describeChangeHistory->handle($featureRequest),
        ]);
    }
}
