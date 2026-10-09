<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TechnicalDetailsUpdateRequest;
use Illuminate\Http\RedirectResponse;

class TechnicalDetailsController extends Controller
{
    /**
     * Show or hide how changes are made: the plan, the code and the tests.
     */
    public function __invoke(TechnicalDetailsUpdateRequest $request): RedirectResponse
    {
        $request->user()->update(['technical_details' => $request->boolean('technical_details')]);

        return back();
    }
}
