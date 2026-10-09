<?php

use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Run;

/*
| Switching the side panel between Chat and Design moves nothing: the tabs
| keep their width, and the chat comes back where the owner left it.
*/

function longChat(): mixed
{
    $change = FeatureRequest::factory()->create(['prompt' => implode("\n", array_map(fn (int $line) => "Line {$line} of what I want.", range(1, 40)))]);
    Run::factory()->for($change)->create(['status' => RunStatus::Planning]);

    test()->actingAs($change->project->owner);

    return visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))->resize(1280, 600);
}

it('brings the chat back where the owner left it after Design', function () {
    $page = longChat();
    $page->script("document.querySelector('[data-test=change-thread] .overflow-y-auto').scrollTop = 300");

    $page->click('@panel-design')
        ->assertMissing('@change-thread')
        ->click('@panel-chat')
        ->assertPresent('@change-thread');

    expect((int) $page->script("document.querySelector('[data-test=change-thread] .overflow-y-auto').scrollTop"))->toBe(300);
});

it('keeps the tabs the same width on Design as on Chat', function () {
    $page = longChat();
    $width = fn () => (int) $page->script("document.querySelector('[role=tablist]').getBoundingClientRect().width");

    $onChat = $width();
    $page->click('@panel-design');

    expect($width())->toBe($onChat);
});

it('opens a chat never read before at its top', function () {
    $page = longChat();

    expect((int) $page->script("document.querySelector('[data-test=change-thread] .overflow-y-auto').scrollTop"))->toBe(0);
});
