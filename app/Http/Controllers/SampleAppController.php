<?php

namespace App\Http\Controllers;

use App\VisualEditing\SampleDesign;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class SampleAppController extends Controller
{
    /**
     * Show the visitor's copy of the sample page in the designer's frame,
     * with the same point-and-edit overlay an app's preview gets.
     */
    public function __invoke(Request $request, SampleDesign $sample): Response
    {
        return response()->view('sample-app', [
            'page' => $sample->page(),
            'stylesheet' => $sample->stylesheet(),
            'overlay' => File::get((string) config('builder.preview.overlay')),
            // The designer page and this frame share the address the visitor used.
            'origin' => $request->getSchemeAndHttpHost(),
        ]);
    }
}
