<?php

use App\Actions\Projects\CreateProject;
use App\Models\Preview;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\Http;
use Pest\Browser\ServerManager;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;

uses(FakesWorkspaces::class, PreparesRuns::class);

/*
| One tap fills the form on show with example details, in a real browser:
| the builder page asks, and the script the gateway put in the app's page
| fills the fields. The app here is a page of plain HTML, as any app gives.
*/

it('fills the form on show with example details and a password nobody knows', function () {
    // The app's pages come through the preview gateway of this same server.
    $port = ServerManager::instance()->http()->port;
    config([
        'app.url' => "http://127.0.0.1:{$port}",
        'builder.preview.scheme' => 'http',
        'builder.preview.domain' => 'preview.localhost',
        'builder.preview.public_port' => $port,
    ]);

    $this->fakeWorkspaces();
    $owner = User::factory()->create();
    $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
    app(ProjectRepository::class)->import($project);
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    Preview::factory()->editable()->ready()->create(['project_id' => $project->id, 'workspace_id' => $workspace->id]);

    Http::fake(['http://127.0.0.1:20001/*' => Http::response(<<<'HTML'
        <html><body>
        <form role="search"><input name="q" placeholder="Search"></form>
        <form id="join">
            <label>Name <input name="name" autocomplete="name"></label>
            <label>Email <input type="email" name="email"></label>
            <label>Password <input type="password" name="password" autocomplete="new-password"></label>
            <label>Confirm password <input type="password" name="password_confirmation" autocomplete="new-password"></label>
            <label>Code word <input type="password" name="code_word"></label>
            <label>Card <input name="card" autocomplete="cc-number"></label>
            <label>Team <input name="team" value="Mine"></label>
            <label><input type="checkbox" name="terms" required> I agree to the terms</label>
            <button>Join</button>
        </form>
        <form id="sign-in">
            <label>Email <input type="email" name="login"></label>
            <label>Password <input type="password" name="current" autocomplete="current-password"></label>
        </form>
        </body></html>
        HTML, 200, ['Content-Type' => 'text/html'])]);

    $this->actingAs($owner);

    $page = visit(route('projects.show', $project));
    // Everything the app's page tells the builder page is kept, to read below.
    $page->script("window.heard = []; window.addEventListener('message', (event) => window.heard.push(JSON.stringify(event.data)))");

    $page->assertEnabled('@preview-fill')
        ->click('@preview-fill')
        ->assertSee('Filled 5 fields with examples')
        ->assertDisabled('@preview-fill');

    $first = '';
    $page->withinFrame('[data-test=preview-frame]', function ($app) use (&$first) {
        $app->assertValue('[name=name]', 'Ada Lovelace')
            ->assertValue('[name=email]', 'ada.lovelace@example.com')
            ->assertChecked('terms')
            // What the owner typed, a card, a search and a password that is not a new one stay as they were.
            ->assertValue('[name=team]', 'Mine')
            ->assertValue('[name=card]', '')
            ->assertValue('[name=q]', '')
            ->assertValue('[name=code_word]', '')
            // A sign-in is left alone: the builder signs the owner in another way.
            ->assertValue('[name=login]', '')
            ->assertValue('[name=current]', '');

        $first = $app->value('[name=password]');
        // The field that asks for the password again holds the same one.
        expect($app->value('[name=password_confirmation]'))->toBe($first);
    });

    expect($first)->toMatch('/^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{24,}$/');

    // The next fill makes another password, for another example person.
    $page->click('@preview-reload')
        // The page loads again, and says what it holds.
        ->wait(2)
        ->assertEnabled('@preview-fill')
        ->click('@preview-fill')
        ->assertDisabled('@preview-fill');

    $second = '';
    $page->withinFrame('[data-test=preview-frame]', function ($app) use (&$second) {
        $app->assertValue('[name=email]', 'grace.hopper@example.com');
        $second = $app->value('[name=password]');
    });

    expect($second)->toHaveLength(strlen($first))->not->toBe($first);

    // The builder page heard how many fields, and never a password.
    $heard = (string) $page->script('window.heard.join("\n")');
    expect($heard)->toContain('"type":"filled","fields":5')
        ->not->toContain($first)
        ->not->toContain($second);

    $page->assertNoJavaScriptErrors();
});
