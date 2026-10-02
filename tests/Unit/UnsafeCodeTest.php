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
}
