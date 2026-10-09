<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DetailLevelUpdateRequest;
use Illuminate\Http\RedirectResponse;

class DetailLevelController extends Controller
{
    /**
     * Remember how much detail the person wants to see, from what they open.
     */
    public function __invoke(DetailLevelUpdateRequest $request): RedirectResponse
    {
        $request->user()->update(['detail_level' => $request->integer('detail_level')]);

        return back();
    }
}
