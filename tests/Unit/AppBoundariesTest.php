<?php

namespace Tests\Unit;

use App\Features\AppBoundaries;
use Tests\TestCase;

class AppBoundariesTest extends TestCase
{
    /**
     * A patch that adds lines 3 and 4 to the post policy.
     */
    protected const PATCH = <<<'DIFF'
        diff --git a/app/Policies/PostPolicy.php b/app/Policies/PostPolicy.php
        --- a/app/Policies/PostPolicy.php
        +++ b/app/Policies/PostPolicy.php
        @@ -1,2 +1,4 @@
         <?php
         // Posts
        +$post->increment('views');
        +Http::post('https://stats.example.com');
        DIFF;

    protected const NEW = 'app/Policies/PostPolicy.php';

    /**
     * One recorded request, as AppTraces::parse() gives it.
     *
     * @param  list<array<string, mixed>>  $effects
     * @return array<string, mixed>
     */
    protected function recorded(string $method, string $route, array $effects): array
    {
        return ['test' => 'Tests\Feature\PostTest::test_people_read_posts', 'method' => $method, 'route' => $route, 'status' => 200, 'refused' => false, 'effects' => $effects, 'blind' => [], 'cut' => false];
    }

    /**
     * A query of a request, in a phase, from a line of the app's code.
     *
     * @return array<string, mixed>
     */
    protected function asked(string $sql, ?string $at, string $phase): array
    {
        return ['kind' => 'query', 'sql' => $sql, 'open' => 0, 'at' => $at, 'phase' => $phase, 'frames' => ['App\Policies\PostPolicy::view']];
    }

    public function test_nothing_is_said_when_the_recorder_names_no_phase()
    {
        $requests = [$this->recorded('GET', '/posts', [['kind' => 'query', 'sql' => 'update "posts" set "views" = ?', 'open' => 0, 'at' => self::NEW.':3']])];

        $this->assertNull(AppBoundaries::measure($requests, self::PATCH));
        $this->assertNull(AppBoundaries::measure([], self::PATCH));
    }

    public function test_a_save_and_a_call_out_from_new_lines_while_checking_who_may_act_are_found()
    {
        $measured = AppBoundaries::measure([$this->recorded('GET', '/posts', [
            $this->asked('select * from "posts"', 'app/Http/Controllers/PostController.php:12', 'handling'),
            $this->asked('update "posts" set "views" = "views" + 1 where "id" = ?', self::NEW.':3', 'authorization'),
            ['kind' => 'http', 'what' => 'POST stats.example.com', 'open' => 0, 'at' => self::NEW.':4', 'phase' => 'authorization', 'frames' => ['App\Policies\PostPolicy::view']],
        ])], self::PATCH);

        $this->assertSame(3, $measured['phased']);
        $this->assertSame([
            ['kind' => AppBoundaries::CHANGED_WHILE_AUTHORIZING, 'route' => 'GET /posts', 'what' => 'update posts', 'at' => self::NEW.':3', 'in' => 'App\Policies\PostPolicy::view', 'test' => 'Tests\Feature\PostTest::test_people_read_posts'],
            ['kind' => AppBoundaries::CHANGED_WHILE_AUTHORIZING, 'route' => 'GET /posts', 'what' => 'http POST stats.example.com', 'at' => self::NEW.':4', 'in' => 'App\Policies\PostPolicy::view', 'test' => 'Tests\Feature\PostTest::test_people_read_posts'],
        ], $measured['findings']);
    }

    public function test_the_same_save_repeated_for_each_row_is_one_finding()
    {
        $view = $this->asked('update "posts" set "views" = "views" + 1 where "id" = ?', self::NEW.':3', 'authorization');

        $measured = AppBoundaries::measure([$this->recorded('GET', '/posts', [$view, $view, $view])], self::PATCH);

        $this->assertCount(1, $measured['findings']);
    }

    public function test_a_save_while_validating_or_rendering_is_found_and_reads_are_not()
    {
        $measured = AppBoundaries::measure([$this->recorded('POST', '/posts', [
            $this->asked('select * from "posts" where "slug" = ?', self::NEW.':3', 'validation'),
            $this->asked('insert into "drafts" ("body") values (?)', self::NEW.':3', 'validation'),
            $this->asked('select * from "users"', self::NEW.':4', 'rendering'),
            ['kind' => 'mail', 'what' => 'App\Mail\Posted', 'open' => 0, 'at' => self::NEW.':4', 'phase' => 'rendering'],
        ])], self::PATCH);

        $this->assertSame([AppBoundaries::CHANGED_WHILE_VALIDATING, AppBoundaries::CHANGED_WHILE_RENDERING], array_column($measured['findings'], 'kind'));
        $this->assertNull($measured['findings'][1]['in']);
    }

    public function test_saves_in_the_handling_phase_or_an_unknown_one_are_not_held_against_the_change()
    {
        $measured = AppBoundaries::measure([$this->recorded('POST', '/posts', [
            $this->asked('insert into "posts" ("title") values (?)', self::NEW.':3', 'handling'),
            $this->asked('update "posts" set "views" = ?', self::NEW.':3', 'unknown'),
        ])], self::PATCH);

        $this->assertSame([], $measured['findings']);
        $this->assertSame(1, $measured['unknown']);
    }

    public function test_only_the_changes_own_lines_count_unless_it_added_the_route()
    {
        $old = $this->asked('update "posts" set "views" = ?', 'app/Policies/PostPolicy.php:40', 'authorization');
        $framework = $this->asked('update "sessions" set "payload" = ?', null, 'authorization');
        $requests = [$this->recorded('GET', '/posts', [$old, $framework])];

        $measured = AppBoundaries::measure($requests, self::PATCH);

        $this->assertSame([], $measured['findings']);
        $this->assertSame(1, $measured['existing']);
        $this->assertCount(1, AppBoundaries::measure($requests, self::PATCH, ['GET /posts'])['findings']);
    }

    public function test_what_reading_the_code_found_joins_what_was_seen_once()
    {
        $measured = AppBoundaries::measure([$this->recorded('GET', '/posts', [
            $this->asked('update "posts" set "views" = ?', self::NEW.':3', 'authorization'),
        ])], self::PATCH);
        $read = fn (string $kind, string $at) => ['kind' => $kind, 'what' => 'save', 'at' => $at, 'in' => 'App\Policies\PostPolicy::view'];

        $merged = AppBoundaries::withRead($measured, ['read' => [
            $read(AppBoundaries::CHANGED_WHILE_AUTHORIZING, self::NEW.':3'),
            $read(AppBoundaries::CHANGED_WHILE_AUTHORIZING, self::NEW.':9'),
            $read(AppBoundaries::CHANGED_WHILE_BOOTING, 'app/Providers/AppServiceProvider.php:14'),
        ], 'before' => []], ['rendering']);

        // Seen is said as seen; a phase not asked for is left out; the app's start is always read.
        $this->assertCount(1, $merged['findings']);
        $this->assertSame(['app/Providers/AppServiceProvider.php:14'], array_column($merged['read'], 'at'));
        $this->assertSame([self::NEW.':9', 'app/Providers/AppServiceProvider.php:14'], array_column(AppBoundaries::withRead($measured, ['read' => [
            $read(AppBoundaries::CHANGED_WHILE_AUTHORIZING, self::NEW.':3'),
            $read(AppBoundaries::CHANGED_WHILE_AUTHORIZING, self::NEW.':9'),
            $read(AppBoundaries::CHANGED_WHILE_BOOTING, 'app/Providers/AppServiceProvider.php:14'),
        ], 'before' => []])['read'], 'at'));

        // Without a recording, what was read is still kept; with neither, nothing is said.
        $this->assertSame(0, AppBoundaries::withRead(null, ['read' => [$read(AppBoundaries::CHANGED_WHILE_BOOTING, 'app/Providers/AppServiceProvider.php:14')], 'before' => []])['phased']);
        $this->assertNull(AppBoundaries::withRead(null, ['read' => [], 'before' => []]));
    }

    public function test_a_finding_the_code_had_before_the_change_only_moved_unless_the_change_added_more()
    {
        $view = 'App\Policies\PostPolicy::view';
        $measured = AppBoundaries::measure([$this->recorded('GET', '/posts', [
            $this->asked('update "posts" set "views" = ?', self::NEW.':3', 'authorization'),
        ])], self::PATCH);
        $read = fn (string $at, string $what = 'save') => ['kind' => AppBoundaries::CHANGED_WHILE_AUTHORIZING, 'what' => $what, 'at' => $at, 'in' => $view];

        // The policy saved before the change too: the change only moved it, seen or read.
        $moved = AppBoundaries::withRead($measured, ['read' => [$read(self::NEW.':4', 'http')], 'before' => [$read('app/Policies/PostPolicy.php:12'), $read('app/Policies/PostPolicy.php:13', 'http')]]);
        $this->assertSame([], $moved['findings']);
        $this->assertSame([], $moved['read']);
        $this->assertSame(2, $moved['existing']);

        // A second save of the same kind in the same method is the change's.
        $more = AppBoundaries::withRead(null, ['read' => [$read(self::NEW.':3'), $read(self::NEW.':4')], 'before' => [$read('app/Policies/PostPolicy.php:12')]]);
        $this->assertCount(1, $more['read']);
        $this->assertSame(1, $more['existing']);
    }

    public function test_what_a_person_accepted_is_set_aside_by_what_it_is()
    {
        $view = 'App\Policies\PostPolicy::view';
        $measured = AppBoundaries::withRead(AppBoundaries::measure([$this->recorded('GET', '/posts', [
            $this->asked('update "posts" set "views" = ?', self::NEW.':3', 'authorization'),
            ['kind' => 'http', 'what' => 'POST stats.example.com', 'open' => 0, 'at' => self::NEW.':4', 'phase' => 'authorization', 'frames' => [$view]],
        ])], self::PATCH), ['read' => [['kind' => AppBoundaries::CHANGED_WHILE_AUTHORIZING, 'what' => 'save', 'at' => self::NEW.':9', 'in' => $view]], 'before' => []]);

        $left = AppBoundaries::without($measured, [AppBoundaries::CHANGED_WHILE_AUTHORIZING.'|'.$view.'|save']);

        $this->assertSame(['http POST stats.example.com'], array_column($left['findings'], 'what'));
        $this->assertSame([], $left['read']);
        $this->assertSame(2, $left['accepted']);
        $this->assertSame($measured, AppBoundaries::without($measured, []));
        $this->assertNull(AppBoundaries::without(null, ['anything']));
    }

    public function test_only_the_phases_asked_for_are_checked()
    {
        $requests = [$this->recorded('GET', '/posts', [
            $this->asked('update "posts" set "views" = ?', self::NEW.':3', 'authorization'),
            $this->asked('update "posts" set "seen" = ?', self::NEW.':3', 'rendering'),
        ])];

        $measured = AppBoundaries::measure($requests, self::PATCH, phases: ['rendering']);

        $this->assertSame([AppBoundaries::CHANGED_WHILE_RENDERING], array_column($measured['findings'], 'kind'));
        $this->assertSame([], AppBoundaries::findings($measured, AppBoundaries::CHANGED_WHILE_AUTHORIZING));
        $this->assertCount(1, AppBoundaries::findings($measured, AppBoundaries::CHANGED_WHILE_RENDERING));
    }

    public function test_each_finding_says_where_to_do_the_work_instead()
    {
        $finding = fn (string $kind, ?string $in) => AppBoundaries::finding(['kind' => $kind, 'route' => 'POST /posts', 'what' => 'update posts', 'at' => self::NEW.':3', 'in' => $in, 'test' => null]);

        $this->assertSame('POST /posts while Laravel checked the input: update posts at '.self::NEW.':3 in App\\Http\\Requests\\StorePost::rules, seen in a test run. The input is checked before the app decides to act, so this stays when the request is refused later. Do this in the code that handles the request, after the input passes.', $finding(AppBoundaries::CHANGED_WHILE_VALIDATING, 'App\\Http\\Requests\\StorePost::rules'));
        $this->assertSame('POST /posts while Laravel built the response: update posts at '.self::NEW.':3, seen in a test run. A response can be built more than once per request. Do this in the code that handles the request and give the response the result.', $finding(AppBoundaries::CHANGED_WHILE_RENDERING, null));
    }
}
