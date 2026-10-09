<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class LegalPageController extends Controller
{
    /**
     * Show the privacy or terms page, written in Markdown so the people who
     * run the platform can change it without touching code.
     */
    public function __invoke(string $page): Response
    {
        $markdown = (string) file_get_contents(resource_path("markdown/{$page}.md"));

        return Inertia::render('public/Legal', [
            'title' => Str::title($page),
            'html' => Str::markdown($markdown, ['html_input' => 'strip']),
        ]);
    }
}
