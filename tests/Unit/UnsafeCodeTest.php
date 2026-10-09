<?php

namespace Tests\Unit;

use App\Features\UnsafeCode;
use Tests\TestCase;

class UnsafeCodeTest extends TestCase
{
    /**
     * Make a patch that adds the given lines to a file after two lines it
     * already had.
     *
     * @param  list<string>  $lines
     */
    protected function adding(string $path, array $lines): string
    {
        return implode("\n", [
            "diff --git a/{$path} b/{$path}",
            "--- a/{$path}",
            "+++ b/{$path}",
            '@@ -1,2 +1,'.(2 + count($lines)).' @@',
            ' first',
            ' second',
            ...array_map(fn (string $line) => '+'.$line, $lines),
        ]);
    }

    public function test_it_finds_each_mistake_on_the_lines_a_change_adds()
    {
        $patch = implode("\n", [
            $this->adding('resources/views/team.blade.php', ['<p>{{ $team->name }}</p>', '<p>{!! $team->description !!}</p>']),
            $this->adding('resources/js/pages/Team.vue', ['<p v-html="team.description" />']),
            $this->adding('resources/views/livewire/team.blade.php', ['<p x-html="description"></p>']),
            $this->adding('resources/js/pages/Team.tsx', ['<p dangerouslySetInnerHTML={{ __html: team.description }} />']),
            $this->adding('src/routes/Team.svelte', ['<p>{@html team.description}</p>']),
            $this->adding('app/Models/Team.php', ['    protected $guarded = [];']),
            $this->adding('app/Http/Controllers/TeamController.php', [
                '$teams = Team::whereRaw("name = \'$name\'")->get();',
                '$sorted = Team::orderByRaw(\'name \'.$direction)->get();',
            ]),
            implode("\n", ['diff --git a/.env b/.env', 'new file mode 100644', '--- /dev/null', '+++ b/.env', '@@ -0,0 +1 @@', '+APP_KEY=base64:secret']),
        ]);

        $this->assertSame([
            ['rule' => 'unescaped_output', 'path' => 'resources/views/team.blade.php', 'line' => 4],
            ['rule' => 'raw_html', 'path' => 'resources/js/pages/Team.vue', 'line' => 3],
            // Every frontend's way to show HTML as it is.
            ['rule' => 'raw_html', 'path' => 'resources/views/livewire/team.blade.php', 'line' => 3],
            ['rule' => 'raw_html', 'path' => 'resources/js/pages/Team.tsx', 'line' => 3],
            ['rule' => 'raw_html', 'path' => 'src/routes/Team.svelte', 'line' => 3],
            ['rule' => 'open_fields', 'path' => 'app/Models/Team.php', 'line' => 3],
            // A file with the same mistake twice is named once, at its first.
            ['rule' => 'raw_query', 'path' => 'app/Http/Controllers/TeamController.php', 'line' => 3],
            ['rule' => 'secret_settings', 'path' => '.env', 'line' => 1],
        ], UnsafeCode::found($patch));
    }

    public function test_safe_ways_and_lines_the_app_already_had_are_not_mistakes()
    {
        $patch = implode("\n", [
            // The app's own line is context, not added.
            implode("\n", ['diff --git a/resources/js/Qr.vue b/resources/js/Qr.vue', '--- a/resources/js/Qr.vue', '+++ b/resources/js/Qr.vue', '@@ -1,2 +1,3 @@', ' <div v-html="qrCodeSvg" />', '-<p>old</p>', '+<p>{{ label }}</p>', ' end']),
            $this->adding('app/Http/Controllers/TeamController.php', [
                '$teams = Team::whereRaw(\'name = ?\', [$name])->get();',
                '$count = DB::raw(\'count(*)\');',
                // A reason a person can read lets the line through.
                '$rows = DB::select($report->sql); // safe: the SQL is fixed in config, not from people',
            ]),
            $this->adding('resources/views/page.blade.php', ['{{-- safe: the markdown is ours, written in the repo --}}', '{!! $page->html !!}']),
            $this->adding('.env.example', ['MAIL_FROM=']),
            // Tests may build what they like.
            $this->adding('tests/Feature/TeamTest.php', ['DB::statement("drop table $table");']),
        ]);

        $this->assertSame([], UnsafeCode::found($patch));
    }

    public function test_a_finding_says_where_what_and_how_to_fix_it()
    {
        $this->assertSame(
            'Line 4 of resources/views/team.blade.php shows text on a page without escaping it ({!! !!}), so people could put their own code on the page. Use {{ }}, which escapes it. If it is safe as it is, say why in a comment on that line or the line above.',
            UnsafeCode::finding(['rule' => 'unescaped_output', 'path' => 'resources/views/team.blade.php', 'line' => 4]),
        );
    }

    public function test_a_secret_key_written_into_the_code_is_found_whatever_its_comment_says()
    {
        $stripe = 'sk_live_'.str_repeat('a1B2', 6);
        $patch = implode("\n", [
            $this->adding('app/Services/Billing.php', ['    // safe: only used in tests', "    \$key = '{$stripe}';"]),
            $this->adding('config/services.php', ["        'key' => env('STRIPE_SECRET'),", "        'test' => 'sk_test_".str_repeat('x', 24)."',"]),
            $this->adding('storage/keys/deploy', ['-----BEGIN OPENSSH PRIVATE KEY-----']),
        ]);

        $this->assertSame([
            ['rule' => 'secret_in_code', 'path' => 'app/Services/Billing.php', 'line' => 4],
            ['rule' => 'secret_in_code', 'path' => 'storage/keys/deploy', 'line' => 3],
        ], UnsafeCode::found($patch));
        $this->assertSame('Line 4 of app/Services/Billing.php writes a secret key into the code, where anyone with the code can read it and use it. Read it from a setting instead: config() in the code, env() in a file under config/, and the setting name with no value in .env.example.', UnsafeCode::finding(UnsafeCode::found($patch)[0]));
        $this->assertFalse(UnsafeCode::scans($this->adding('storage/keys/deploy', ['nothing'])));
    }

    public function test_a_secret_setting_sent_to_the_browser_is_found()
    {
        $patch = implode("\n", [
            $this->adding('.env.example', ['VITE_STRIPE_SECRET="${STRIPE_SECRET}"']),
            $this->adding('resources/js/lib/maps.ts', ['const token = import.meta.env.VITE_MAPBOX_ACCESS_TOKEN;']),
            $this->adding('app/Http/Middleware/HandleInertiaRequests.php', ["            'mailgun' => config('services.mailgun.secret'),"]),
            $this->adding('resources/views/checkout.blade.php', ["<div data-key=\"{{ env('PADDLE_API_KEY') }}\"></div>"]),
            $this->adding('app/Http/Controllers/BillingController.php', ["        return Inertia::render('Billing', [", "            'key' => config('app.key'),", '        ]);']),
        ]);

        $this->assertSame([
            ['rule' => 'secret_to_browser', 'path' => '.env.example', 'line' => 3],
            ['rule' => 'secret_to_browser', 'path' => 'resources/js/lib/maps.ts', 'line' => 3],
            ['rule' => 'secret_to_page', 'path' => 'app/Http/Middleware/HandleInertiaRequests.php', 'line' => 3],
            ['rule' => 'secret_to_page', 'path' => 'resources/views/checkout.blade.php', 'line' => 3],
            ['rule' => 'secret_to_props', 'path' => 'app/Http/Controllers/BillingController.php', 'line' => 4],
        ], UnsafeCode::found($patch));

        // A prop added to a page the app already renders, through the helper.
        $existing = implode("\n", ['diff --git a/app/Http/Controllers/TeamController.php b/app/Http/Controllers/TeamController.php', '--- a/app/Http/Controllers/TeamController.php', '+++ b/app/Http/Controllers/TeamController.php', '@@ -10,2 +10,3 @@', "        return inertia('Team', [", "+            'token' => env('SLACK_BOT_TOKEN'),", '        ]);']);
        $this->assertSame([['rule' => 'secret_to_props', 'path' => 'app/Http/Controllers/TeamController.php', 'line' => 11]], UnsafeCode::found($existing));
        $this->assertSame('Line 3 of .env.example gives a secret setting a VITE_ name, so its value is built into the JavaScript every visitor downloads. Keep the secret on the server without the VITE_ prefix, and let the page reach what it needs through a route of the app.', UnsafeCode::finding(UnsafeCode::found($patch)[0]));
    }

    public function test_settings_made_for_browsers_and_secrets_kept_on_the_server_are_not_found()
    {
        $patch = implode("\n", [
            $this->adding('.env.example', ['VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"', 'VITE_STRIPE_KEY="${STRIPE_KEY}"', 'VITE_ALGOLIA_SEARCH_KEY=', 'VITE_KEYBOARD_LAYOUT=qwerty', 'STRIPE_SECRET=']),
            $this->adding('app/Http/Middleware/HandleInertiaRequests.php', ["            'stripeKey' => config('services.stripe.key'),", "            'reverb' => env('REVERB_APP_KEY'),"]),
            // Passed to a client on the server, beside a page.
            $this->adding('app/Http/Controllers/BillingController.php', ["        \$stripe = new StripeClient(['api_key' => config('services.stripe.secret')]);", "        return Inertia::render('Billing');"]),
            // A controller that renders no page sends nothing to one.
            $this->adding('app/Http/Controllers/WebhookController.php', ["        \$client = new Client(['token' => config('services.slack.token')]);"]),
            $this->adding('config/services.php', ["        'secret' => env('STRIPE_SECRET'),"]),
        ]);

        $this->assertSame([], UnsafeCode::found($patch));

        // A key a service makes for browsers is allowed in the settings, never in a comment.
        config(['builder.verification.browser_settings' => [...config('builder.verification.browser_settings'), 'MAPBOX_ACCESS_TOKEN']]);
        $this->assertSame([], UnsafeCode::found($this->adding('resources/js/lib/maps.ts', ['const token = import.meta.env.VITE_MAPBOX_ACCESS_TOKEN;'])));
    }

    public function test_a_comment_never_lets_a_secret_reach_the_page_and_lines_not_added_or_in_tests_are_not_found()
    {
        $commented = $this->adding('app/Http/Middleware/HandleInertiaRequests.php', ['            // safe: only signed-in staff see this', "            'mailgun' => config('services.mailgun.secret'),"]);
        $removed = implode("\n", ['diff --git a/.env.example b/.env.example', '--- a/.env.example', '+++ b/.env.example', '@@ -1,2 +1,1 @@', ' APP_NAME=Laravel', '-VITE_STRIPE_SECRET="${STRIPE_SECRET}"']);
        $test = $this->adding('tests/Feature/BillingTest.php', ["        \$this->assertSame(config('services.mailgun.secret'), \$page['mailgun']);", '        // VITE_STRIPE_SECRET']);

        $this->assertSame([['rule' => 'secret_to_page', 'path' => 'app/Http/Middleware/HandleInertiaRequests.php', 'line' => 4]], UnsafeCode::found($commented));
        $this->assertSame('Line 4 of app/Http/Middleware/HandleInertiaRequests.php puts a secret setting on the page, where anyone who opens it can read it. Keep the secret on the server, and let the page reach what it needs through a route of the app.', UnsafeCode::finding(UnsafeCode::found($commented)[0]));
        $this->assertSame([], UnsafeCode::found($removed."\n".$test));
    }

    public function test_a_redirect_or_a_file_whose_address_comes_from_the_request_is_found_whatever_its_comment_says()
    {
        $found = UnsafeCode::found(implode("\n", [
            $this->adding('app/Http/Controllers/LoginController.php', [
                '        // Safe: we trust the next page.',
                '        return redirect($request->input(\'next\', \'/\'));',
            ]),
            $this->adding('app/Http/Controllers/GoController.php', ['        return redirect()->away(request(\'to\'));']),
            $this->adding('app/Http/Controllers/BackController.php', ['        return Inertia::location($request->return_to);']),
            $this->adding('app/Http/Controllers/TenantController.php', ['        return redirect(\'https://\'.$request->input(\'host\'));']),
            $this->adding('app/Http/Controllers/HostController.php', ['        return redirect()->to("//{$request->host}/home");']),
            $this->adding('app/Http/Controllers/DownloadController.php', ['        return Storage::download(\'reports/\'.$request->query(\'file\'));']),
            $this->adding('app/Http/Controllers/ExportController.php', ['        return response()->download(storage_path("exports/{$request->name}"));']),
            $this->adding('app/Actions/RemoveUpload.php', ['        Storage::disk(\'public\')->delete($request->path);']),
        ]));

        $this->assertSame([
            ['rule' => 'open_redirect', 'path' => 'app/Http/Controllers/LoginController.php', 'line' => 4],
            ['rule' => 'open_redirect', 'path' => 'app/Http/Controllers/GoController.php', 'line' => 3],
            ['rule' => 'open_redirect', 'path' => 'app/Http/Controllers/BackController.php', 'line' => 3],
            ['rule' => 'open_redirect', 'path' => 'app/Http/Controllers/TenantController.php', 'line' => 3],
            ['rule' => 'open_redirect', 'path' => 'app/Http/Controllers/HostController.php', 'line' => 3],
            ['rule' => 'path_from_request', 'path' => 'app/Http/Controllers/DownloadController.php', 'line' => 3],
            ['rule' => 'path_from_request', 'path' => 'app/Http/Controllers/ExportController.php', 'line' => 3],
            ['rule' => 'path_from_request', 'path' => 'app/Actions/RemoveUpload.php', 'line' => 3],
        ], $found);
        $this->assertSame(
            'Line 4 of app/Http/Controllers/LoginController.php sends people to an address taken from the request, so a link to the app could send them to any site. Send them to a route of the app (redirect()->route()), to redirect()->intended() or back(), or allow only known values with an in: rule.',
            UnsafeCode::finding($found[0]),
        );
    }

    public function test_the_apps_own_addresses_a_basename_and_values_an_in_rule_allows_are_not_found()
    {
        $this->assertSame([], UnsafeCode::found(implode("\n", [
            $this->adding('app/Http/Controllers/LoginController.php', [
                '        return redirect()->intended(route(\'dashboard\'));',
                '        return redirect()->route($request->input(\'tab\'));',
                '        return back();',
                '        return redirect(url()->previous());',
                '        return redirect(\'/search?q=\'.$request->q);',
                '        return redirect(\'https://docs.example.com/\'.$request->page);',
                '        return redirect($request->user()->homePage());',
            ]),
            $this->adding('app/Http/Controllers/DownloadController.php', [
                '        return Storage::download(\'reports/\'.basename($request->query(\'file\')));',
                '        return Storage::download($report->path);',
                '        return response()->download($request->file(\'upload\')->path());',
                // Only known names: the change's own rule allows them.
                '        return Storage::download(\'sheets/\'.$request->input(\'sheet\'));',
                '        return redirect($request->validated(\'next\'));',
            ]),
            $this->adding('app/Http/Requests/DownloadRequest.php', [
                '            \'sheet\' => [\'required\', Rule::in([\'summary.csv\', \'detail.csv\'])],',
                '            \'next\' => \'required|in:/home,/billing\',',
            ]),
        ])));
    }

    public function test_a_redirect_or_file_path_the_app_had_or_in_a_test_is_not_found()
    {
        $this->assertSame([], UnsafeCode::found(implode("\n", [
            'diff --git a/app/Http/Controllers/GoController.php b/app/Http/Controllers/GoController.php',
            '--- a/app/Http/Controllers/GoController.php',
            '+++ b/app/Http/Controllers/GoController.php',
            '@@ -1,2 +1,2 @@',
            ' first',
            '-        return redirect($request->next);',
            '+        return redirect()->route(\'home\');',
            $this->adding('tests/Feature/DownloadTest.php', ['        $this->get(\'/go?next=\'.urlencode(\'https://x.test\'));', '        Storage::download($request->path);']),
        ])));
    }
}
