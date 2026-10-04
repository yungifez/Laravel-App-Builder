<?php

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

class ChangelogController extends Controller
{
    /**
     * Show what changed for owners, week by week, newest first.
     *
     * The list is written by hand in resources/changelog.json, so the page
     * needs no git history where the builder runs.
     */
    public function __invoke(): Response
    {
        /** @var array{weeks: list<array{starts_on: string, entries: list<array{title: string, body: string}>}>} $changelog */
        $changelog = File::json(resource_path('changelog.json'));

        return Inertia::render('public/Changelog', [
            'weeks' => collect($changelog['weeks'])
                ->sortByDesc('starts_on')
                ->map(fn (array $week) => [
                    'starts_on' => $week['starts_on'],
                    'label' => 'Week of '.Carbon::parse($week['starts_on'])->format('j F Y'),
                    'entries' => $week['entries'],
                ])
                ->values(),
        ]);
    }
}
