<?php

namespace Tests\Feature\Features;

use App\Actions\Features\DescribeProof;
use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\Verification;
use App\Runs\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChangeProofTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Record checks for the change: the app's tests (all passing), two more
     * checks on the code, and the separate checks. Evidence is what running
     * the app with and without the change showed.
     *
     * @param  array<string, mixed>|null  $evidence
     */
    protected function checked(FeatureRequest $request, VerificationStatus $status = VerificationStatus::Passed, ?array $evidence = null): void
    {
        $result = fn (string $name, string $stage, array $extra = []) => ['name' => $name, 'stage' => $stage, 'outcome' => 'passed', 'exit_code' => 0, 'timed_out' => false, 'duration_ms' => 5, 'output' => '', ...$extra];

        Verification::factory()->for($request)->create([
            'status' => $status,
            'evidence' => $evidence,
            'results' => [
                $result('Install PHP dependencies', 'setup'),
                $result('Tests', 'checks', ['tests' => [
                    ['file' => 'tests/Feature/TeamTest.php', 'name' => 'test_owners_rename_teams', 'outcome' => 'passed'],
                    ['file' => 'tests/Feature/TeamTest.php', 'name' => 'test_members_cannot_rename_teams', 'outcome' => 'passed'],
                    ['file' => 'tests/Feature/BillingTest.php', 'name' => 'test_seats_follow_members', 'outcome' => 'passed'],
                ]]),
                $result('Static analysis', 'checks'),
                $result('PHP formatting', 'checks'),
                $result('Protected acceptance tests', 'acceptance'),
            ],
        ]);
    }

    /**
     * Record a reviewed run whose tests reached the change as given.
     *
     * @param  array<string, mixed>|null  $observed
     */
    protected function reviewed(FeatureRequest $request, ?array $observed): void
    {
        Run::factory()->for($request)->create([
            'context' => ['mode' => 'selective', 'targets' => ['teams'], 'text' => '', 'included' => [], 'problems' => [], 'outline' => [
                ['key' => 'teams', 'name' => 'Teams', 'summary' => null, 'file' => null, 'paths' => [], 'behaviors' => [], 'effects' => []],
                ['key' => 'billing', 'name' => 'Billing', 'summary' => null, 'file' => null, 'paths' => [], 'behaviors' => [], 'effects' => []],
            ]],
            'review' => ['approved' => true, 'summary' => '', 'findings' => [], 'changes' => [], 'classification' => [
                'requested' => ['teams' => ['app/Policies/TeamPolicy.php']],
                'may_also_affect' => [],
                'unexpected' => [],
                'unclaimed' => [],
                'context_updates' => [],
                'targets' => ['teams'],
                'observed' => $observed,
            ]],
        ]);
    }

    public function test_a_checked_change_says_how_it_is_known_to_work()
    {
        $request = FeatureRequest::factory()->generated()->create();
        $this->checked($request);
        $this->reviewed($request, ['areas' => ['billing' => 1, 'teams' => 2], 'tests' => 3, 'unmapped' => ['app/Support/Money.php'], 'foundation' => [], 'by_line' => []]);

        $this->actingAs($request->project->owner)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('proof', [
                ['kind' => 'passed', 'text' => 'All 3 of the app\'s own tests still pass.'],
                ['kind' => 'passed', 'text' => '2 more checks on the code passed.'],
                ['kind' => 'passed', 'text' => 'Separate checks, written before the work began, pass too.', 'evidence' => true],
                ['kind' => 'passed', 'text' => 'Its code was checked for common safety mistakes, such as unsafe text on a page or unsafe database lookups. None were found.'],
                ['kind' => 'reach', 'text' => '3 of those tests run the code this change touched, in Billing and Teams.', 'evidence' => true],
                // Gaps are said as plainly as passes.
                ['kind' => 'gap', 'text' => 'Some of the new code is not run by any test yet.'],
                ['kind' => 'passed', 'text' => 'The change was looked over a second time before it reached you.'],
            ]));
    }

    public function test_a_change_says_whether_it_kept_the_old_way_working_and_why()
    {
        $request = FeatureRequest::factory()->generated()->create();
        $this->checked($request);
        $run = Run::factory()->for($request)->create();
        $run->recordEvent('compatibility', ['keep_old_working' => false, 'chosen_by_owner' => false]);

        $this->actingAs($request->project->owner)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('proof', fn ($proof) => collect($proof)->contains([
                'kind' => 'approach',
                'text' => 'Nobody uses your app yet, so I changed it cleanly and kept nothing for the old way.',
            ])));
    }

    public function test_a_change_to_code_the_whole_app_shares_says_the_whole_app_was_tested()
    {
        $request = FeatureRequest::factory()->generated()->create();
        $this->checked($request);
        $this->reviewed($request, ['areas' => [], 'tests' => 0, 'unmapped' => [], 'foundation' => ['app/Models/User.php'], 'by_line' => []]);

        $this->actingAs($request->project->owner)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('proof.4', ['kind' => 'reach', 'text' => 'It changed code the whole app shares, so every part of the app was tested.', 'evidence' => true]));
    }

    public function test_problems_caught_along_the_way_are_counted()
    {
        $request = FeatureRequest::factory()->generated()->create();
        $this->checked($request);
        $this->reviewed($request, null);
        $run = $request->runs()->sole();
        $sentBack = fn (string $from, string $reason) => $run->recordEvent('status', ['from' => $from, 'to' => 'implementing', 'reason' => $reason]);
        $sentBack('verifying', 'verification_failed');
        $sentBack('verifying', 'verification_failed');
        $sentBack('reviewing', 'review_findings');
        // A plain move forward is not a problem caught.
        $run->recordEvent('status', ['from' => 'implementing', 'to' => 'verifying']);

        $this->actingAs($request->project->owner)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('proof.3', ['kind' => 'caught', 'text' => 'The checks caught 2 problems along the way, and they were fixed before you saw the change.'])
                ->where('proof.4', ['kind' => 'caught', 'text' => 'A second look found something to fix, and it was fixed first.']));
    }

    public function test_a_change_whose_screens_use_the_theme_says_no_colours_were_made_up()
    {
        $screen = fn (string $class) => implode("\n", [
            'diff --git a/resources/js/pages/Team.vue b/resources/js/pages/Team.vue',
            '--- a/resources/js/pages/Team.vue',
            '+++ b/resources/js/pages/Team.vue',
            '@@ -1 +1,2 @@',
            ' <template>',
            "+    <p class=\"{$class}\">Team</p>",
        ]);
        $themed = FeatureRequest::factory()->generated()->create(['patch' => $screen('text-muted-foreground')]);
        $madeUp = FeatureRequest::factory()->generated()->create(['patch' => $screen('text-[#6b7280]')]);
        $this->checked($themed);
        $this->checked($madeUp);

        $this->actingAs($themed->project->owner)
            ->get(route('feature-requests.show', $themed))
            ->assertInertia(fn (Assert $page) => $page->where('proof.4', ['kind' => 'passed', 'text' => 'Its screens take their colours from your app\'s theme. None were made up.']));
        // A made-up colour left in is never called clean.
        $this->actingAs($madeUp->project->owner)
            ->get(route('feature-requests.show', $madeUp))
            ->assertInertia(fn (Assert $page) => $page->where('proof', fn ($proof) => ! collect($proof)->contains('text', 'Its screens take their colours from your app\'s theme. None were made up.')));
    }

    public function test_a_change_whose_code_takes_no_shortcuts_says_so()
    {
        $patch = implode("\n", [
            'diff --git a/app/Http/Controllers/TeamController.php b/app/Http/Controllers/TeamController.php',
            '--- a/app/Http/Controllers/TeamController.php',
            '+++ b/app/Http/Controllers/TeamController.php',
            '@@ -1 +1,2 @@',
            ' <?php',
            '+$teams = Team::all();',
        ]);
        $clean = FeatureRequest::factory()->generated()->create(['patch' => $patch]);
        $shortcut = FeatureRequest::factory()->generated()->create(['patch' => $patch]);
        $unread = FeatureRequest::factory()->generated()->create(['patch' => $patch]);
        $this->checked($clean);
        $this->checked($shortcut);
        $this->checked($unread);
        $clean->verifications()->sole()->update(['shortcuts' => []]);
        $shortcut->verifications()->sole()->update(['shortcuts' => [['rule' => 'SL210', 'path' => 'app/Http/Controllers/TeamController.php', 'line' => 2]]]);
        $said = 'Its code was checked for shortcuts that slow an app down or hide its errors, such as asking the database once for every row. None were found.';

        $this->actingAs($clean->project->owner)
            ->get(route('feature-requests.show', $clean))
            ->assertInertia(fn (Assert $page) => $page->where('proof', fn ($proof) => collect($proof)->contains(['kind' => 'passed', 'text' => $said])));
        // A shortcut left in, or code the analyser never read, is never called clean.
        foreach ([$shortcut, $unread] as $request) {
            $this->actingAs($request->project->owner)
                ->get(route('feature-requests.show', $request))
                ->assertInertia(fn (Assert $page) => $page->where('proof', fn ($proof) => ! collect($proof)->contains('text', $said)));
        }
    }

    public function test_a_change_whose_pictures_are_described_says_so()
    {
        $request = FeatureRequest::factory()->generated()->create(['patch' => implode("\n", [
            'diff --git a/resources/js/pages/Team.vue b/resources/js/pages/Team.vue',
            '--- a/resources/js/pages/Team.vue',
            '+++ b/resources/js/pages/Team.vue',
            '@@ -1 +1,2 @@',
            ' <template>',
            '+    <img :src="team.logo" :alt="team.name" class="size-8" />',
        ])]);
        $this->checked($request);

        $this->actingAs($request->project->owner)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('proof.5', ['kind' => 'passed', 'text' => 'The pictures it added say what they show, for people who cannot see the screen.']));
    }

    public function test_a_change_whose_screens_fit_every_width_says_so()
    {
        $patch = implode("\n", [
            'diff --git a/resources/js/pages/Team.vue b/resources/js/pages/Team.vue',
            '--- a/resources/js/pages/Team.vue',
            '+++ b/resources/js/pages/Team.vue',
            '@@ -1 +1,2 @@',
            ' <template>',
            '+    <p>Team</p>',
        ]);
        $screens = fn (int $overflow) => ['pages' => [['path' => '/team', 'status' => 200, 'final' => '/team', 'screen' => 'Team', 'widths' => [
            ['width' => 390, 'overflow' => $overflow, 'cut_off' => 0, 'cut' => [], 'small_targets' => 0, 'small' => [], 'errors' => []],
            ['width' => 1280, 'overflow' => 0, 'cut_off' => 0, 'cut' => [], 'small_targets' => 0, 'small' => [], 'errors' => []],
        ]]], 'signed_in' => true];
        $line = 'The screen it changed was opened on a phone, a tablet and a computer. Nothing was cut off, too small to tap or broken.';
        $fits = FeatureRequest::factory()->generated()->create(['patch' => $patch]);
        $scrolls = FeatureRequest::factory()->generated()->create(['patch' => $patch]);
        $this->checked($fits);
        $this->checked($scrolls);
        $fits->verifications()->sole()->update(['screens' => $screens(0)]);
        $scrolls->verifications()->sole()->update(['screens' => $screens(80)]);

        $this->actingAs($fits->project->owner)
            ->get(route('feature-requests.show', $fits))
            ->assertInertia(fn (Assert $page) => $page->where('proof', fn ($proof) => collect($proof)->contains(fn ($proofLine) => $proofLine['kind'] === 'passed' && $proofLine['text'] === $line && $proofLine['pictures'] === [])));

        // The pictures of the first changed screen come with it, narrowest first.
        $shot = fn (string $screen, int $width) => ['screen' => $screen, 'width' => $width, 'path' => "screen-shots/1/{$width}.jpg"];
        $verification = $fits->verifications()->sole();
        $verification->update(['screens' => [...$screens(0), 'shots' => [$shot('Team', 1280), $shot('Team', 390), $shot('Billing', 390), $shot('Team', 820)]]]);
        $this->actingAs($fits->project->owner)
            ->get(route('feature-requests.show', $fits))
            ->assertInertia(fn (Assert $page) => $page->where('proof', fn ($proof) => collect($proof)->firstWhere('text', $line)['pictures'] === [
                ['url' => route('verifications.shots.show', [$verification, 1]), 'label' => 'Phone'],
                ['url' => route('verifications.shots.show', [$verification, 3]), 'label' => 'Tablet'],
                ['url' => route('verifications.shots.show', [$verification, 0]), 'label' => 'Computer'],
            ]));
        // Faint words and hidden focus are gaps the owner sees, not reasons to hold the change.
        $faint = $screens(0);
        $faint['pages'][0]['widths'][0]['faint'] = [['text' => 'Delete team', 'ratio' => 3.76, 'needed' => 4.5]];
        $faint['pages'][0]['widths'][1]['unfocused'] = [['text' => 'Menu'], ['text' => 'Search']];
        $fits->verifications()->sole()->update(['screens' => $faint]);
        $this->actingAs($fits->project->owner)
            ->get(route('feature-requests.show', $fits))
            ->assertInertia(fn (Assert $page) => $page->where('proof', fn ($proof) => collect($proof)->contains('text', $line)
                && collect($proof)->contains(['kind' => 'gap', 'text' => 'Some words on it are hard to read against their background: "Delete team".'])
                && collect($proof)->contains(['kind' => 'gap', 'text' => 'Someone using a keyboard cannot see when some controls on it are selected, such as "Menu".'])));
        // A page that still scrolls sideways is never called a fit.
        $this->actingAs($scrolls->project->owner)
            ->get(route('feature-requests.show', $scrolls))
            ->assertInertia(fn (Assert $page) => $page->where('proof', fn ($proof) => ! collect($proof)->contains('text', $line)));
    }

    public function test_the_tests_a_change_added_are_named()
    {
        $request = FeatureRequest::factory()->generated()->create(['patch' => implode("\n", [
            'diff --git a/tests/Feature/ArchiveTest.php b/tests/Feature/ArchiveTest.php',
            '--- /dev/null',
            '+++ b/tests/Feature/ArchiveTest.php',
            '@@ -0,0 +1,9 @@',
            '+    public function test_owners_can_archive_teams()',
            '+    public function test_members_cannot_archive_teams(): void',
            "+it('hides archived teams', function () {",
            '+test("restoring a team brings it back", function () {',
            // Code outside the tests names no test.
            'diff --git a/app/Models/Team.php b/app/Models/Team.php',
            '+++ b/app/Models/Team.php',
            '@@ -1,1 +1,2 @@',
            '+    public function test_mode()',
        ])]);
        $this->checked($request);

        $this->actingAs($request->project->owner)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('proof.3', [
                'kind' => 'passed',
                'text' => 'It added 4 tests that keep this checked from now on, such as "Owners can archive teams".',
                'evidence' => true,
            ]));
    }

    public function test_new_tests_that_fail_without_the_change_are_what_shows_it_works()
    {
        $patch = implode("\n", [
            'diff --git a/tests/Feature/ArchiveTest.php b/tests/Feature/ArchiveTest.php',
            '--- /dev/null',
            '+++ b/tests/Feature/ArchiveTest.php',
            '@@ -0,0 +1,2 @@',
            '+    public function test_owners_can_archive_teams()',
            '+    public function test_the_team_page_loads()',
        ]);
        $proof = function (array $tests) use ($patch) {
            $request = FeatureRequest::factory()->generated()->create(['patch' => $patch]);
            $this->checked($request, evidence: ['new_tests' => $tests]);

            return $this->actingAs($request->project->owner)->get(route('feature-requests.show', $request));
        };
        $test = fn (string $name, string $without, string $file = 'tests/Feature/ArchiveTest.php') => ['file' => $file, 'name' => $name, 'without_change' => $without];

        // Only the test that fails without the change is counted and named.
        $proof([$test('test_the_team_page_loads', 'passed'), $test('test_owners_can_archive_teams', 'failed')])
            ->assertInertia(fn (Assert $page) => $page->where('proof.3', [
                'kind' => 'passed',
                'text' => 'It added a test that fails without this change and passes with it: "Owners can archive teams".',
                'evidence' => true,
            ]));

        // Tests that pass either way show nothing about the change: a gap, not evidence.
        $proof([$test('test_the_team_page_loads', 'passed'), $test('test_owners_can_archive_teams', 'passed')])
            ->assertInertia(fn (Assert $page) => $page->where('proof.3', [
                'kind' => 'gap',
                'text' => 'The 2 tests it added pass without this change too, so they do not show that the change works.',
            ]));

        // Tests an earlier change of the conversation added are not this one's.
        $proof([$test('test_members_see_invitations', 'passed', 'tests/Feature/InviteTest.php')])
            ->assertInertia(fn (Assert $page) => $page->where('proof.3', [
                'kind' => 'passed',
                'text' => 'It added 2 tests that keep this checked from now on, such as "Owners can archive teams".',
                'evidence' => true,
            ]));
    }

    public function test_a_change_to_who_may_use_a_part_of_the_app_is_said()
    {
        $texts = function (array $routes) {
            $request = FeatureRequest::factory()->generated()->create();
            $this->checked($request, evidence: ['routes' => $routes]);

            return collect(app(DescribeProof::class)->handle($request));
        };
        $kept = 'Every part of your app that asks people to sign in still does.';

        // Nothing about the app's addresses changed: nothing to say.
        $this->assertFalse($texts([])->contains('text', $kept));

        $this->assertContains(['kind' => 'passed', 'text' => $kept], $texts([
            'added' => [['route' => 'POST /teams/{team}/archive', 'middleware' => ['web', 'auth']]],
            'removed' => [],
            'changed' => [['route' => 'GET /teams', 'lost' => [], 'gained' => ['verified']]],
        ])->all());

        // Nothing that ran can tell whether the owner wanted this, so it is a gap they see.
        $opened = $texts(['added' => [], 'removed' => [], 'changed' => [['route' => 'GET /teams/{team}', 'lost' => ['auth', 'verified'], 'gained' => []]]]);
        $this->assertContains(['kind' => 'gap', 'text' => 'A part of your app no longer checks who may use it: /teams/{team}. Make sure you wanted that.'], $opened->all());
        $this->assertFalse($opened->contains('text', $kept));

        // An address that lost middleware of the app's own is not guessed at, either way.
        $unknown = $texts(['added' => [], 'removed' => [], 'changed' => [['route' => 'GET /teams', 'lost' => ['App\\Http\\Middleware\\EnsureAdmin'], 'gained' => []]]]);
        $this->assertFalse($unknown->contains('text', $kept));
        $this->assertFalse($unknown->contains('kind', 'gap'));
    }

    public function test_how_many_of_the_new_lines_of_code_a_test_ran_is_said()
    {
        $proof = function (array $code, ?array $observed = null) {
            $request = FeatureRequest::factory()->generated()->create();
            $this->checked($request, evidence: ['new_code' => $code]);

            if ($observed !== null) {
                $this->reviewed($request, $observed);
            }

            return [$request, app(DescribeProof::class)->handle($request)];
        };
        $gap = ['kind' => 'gap', 'text' => 'Some of the new code is not run by any test yet.'];

        [, $all] = $proof(['lines' => 12, 'run' => 12, 'own_tests_only' => 12, 'unrun' => []]);
        $this->assertContains(['kind' => 'reach', 'text' => 'Tests ran every one of its 12 new lines of code.', 'evidence' => true], $all);
        $this->assertNotContains($gap, $all);

        // One line no test ran is common and harmless.
        [$request, $most] = $proof(['lines' => 123, 'run' => 122, 'own_tests_only' => 106, 'unrun' => ['app/Models/TeamInvitation.php' => [90]]]);
        $this->assertContains(['kind' => 'reach', 'text' => 'Tests ran 122 of its 123 new lines of code.', 'evidence' => true], $most);
        $this->assertNotContains($gap, $most);

        // A large share is a gap, said once even when a whole file is unrun too,
        // and closing it is offered first as the next thing to ask for.
        [$request, $some] = $proof(
            ['lines' => 10, 'run' => 6, 'own_tests_only' => 6, 'unrun' => ['app/Support/Money.php' => [4, 5, 6, 7]]],
            ['areas' => ['teams' => 1], 'tests' => 1, 'unmapped' => ['app/Support/Money.php'], 'foundation' => [], 'by_line' => []],
        );
        $this->assertContains(['kind' => 'reach', 'text' => 'Tests ran 6 of its 10 new lines of code.', 'evidence' => true], $some);
        $this->assertCount(1, array_filter($some, fn (array $line) => $line === $gap));

        // New code no test ran at all is not evidence of anything.
        [$request, $none] = $proof(['lines' => 4, 'run' => 0, 'own_tests_only' => 0, 'unrun' => ['app/Support/Money.php' => [4, 5, 6, 7]]], ['areas' => [], 'tests' => 0, 'unmapped' => [], 'foundation' => [], 'by_line' => []]);
        $this->assertFalse(collect($none)->contains(fn (array $line) => str_starts_with($line['text'], 'Tests ran')));
        $this->assertContains($gap, $none);
        $request->runs()->sole()->update(['plan' => (new Plan('Archive teams.', next: ['Let owners archive teams']))->toArray()]);

        $this->actingAs($request->project->owner)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('run.plan.next.0', 'Add tests for the new code nothing checks yet'));
    }

    public function test_what_the_app_was_seen_to_do_while_its_tests_ran_is_said()
    {
        $proof = function (array $traces) {
            $request = FeatureRequest::factory()->generated()->create();
            $this->checked($request, evidence: ['traces' => ['requests' => 40, 'reached' => 0, 'unseen' => 0, 'existing' => 0, 'findings' => [], 'repeats' => [], ...$traces]]);

            return collect(app(DescribeProof::class)->handle($request));
        };
        $finding = fn (string $kind, string $route) => ['kind' => $kind, 'route' => $route, 'what' => 'update teams', 'at' => 'app/Models/Team.php:3', 'test' => null];
        $clean = 'Nothing was saved by mistake or sent too early.';

        // No recorded request ran the new code: the recording says nothing about it.
        $this->assertFalse($proof([])->contains(fn (array $line) => str_contains($line['text'], $clean)));

        $this->assertContains(
            ['kind' => 'passed', 'text' => "We watched what your app saved and sent while its tests used the new code 12 times. {$clean}"],
            $proof(['reached' => 12])->all(),
        );

        // Tests that fake what is sent hide when it is sent, so only the saving is vouched for.
        $this->assertContains(
            ['kind' => 'passed', 'text' => 'We watched what your app saved while its tests used the new code 12 times. Nothing was saved by mistake. Its tests only pretend to send emails and messages, so we could not watch when it sends them.'],
            $proof(['reached' => 12, 'unseen' => 2])->all(),
        );

        // Each kind of thing seen is one gap, with the first address it was seen at.
        $seen = $proof(['reached' => 12, 'findings' => [
            $finding('saved_on_read', 'GET /teams/{team}'),
            $finding('saved_on_read', 'GET /teams'),
            $finding('kept_after_refusal', 'POST /invitations'),
            $finding('sent_before_saved', 'POST /teams'),
        ]]);
        $this->assertSame([
            'Opening /teams/{team} changes what your app has saved. A page that only shows things should leave them as they are. Make sure you wanted that.',
            'When your app says no at /invitations, it still keeps part of what was sent. Make sure you wanted that.',
            'At /teams your app sends something before it has finished saving. If saving fails, it is sent anyway.',
        ], $seen->where('kind', 'gap')->pluck('text')->all());
        $this->assertFalse($seen->contains(fn (array $line) => str_contains($line['text'], $clean)));
    }

    public function test_new_code_no_test_runs_is_offered_first_as_the_next_thing_to_ask_for()
    {
        $request = FeatureRequest::factory()->generated()->create();
        $this->checked($request);
        $this->reviewed($request, ['areas' => ['teams' => 1], 'tests' => 1, 'unmapped' => ['app/Support/Money.php'], 'foundation' => [], 'by_line' => []]);
        $run = $request->runs()->sole();
        $run->update(['plan' => (new Plan('Archive teams.', next: ['Let owners archive teams', 'Show archived teams', 'Email the owner']))->toArray()]);

        $this->actingAs($request->project->owner)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('run.plan.next', [
                'Add tests for the new code nothing checks yet',
                'Let owners archive teams',
                'Show archived teams',
            ]));
    }

    public function test_a_change_no_separate_check_tried_says_so_as_a_gap()
    {
        $request = FeatureRequest::factory()->generated()->create();
        Verification::factory()->for($request)->create(['status' => VerificationStatus::Unverified, 'results' => [
            ['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'passed', 'exit_code' => 0, 'timed_out' => false, 'duration_ms' => 5, 'output' => '', 'tests' => [
                ['file' => 'tests/Feature/TeamTest.php', 'name' => 'test_owners_rename_teams', 'outcome' => 'passed'],
            ]],
        ]]);

        // Its own tests passing is not the same as a separate check trying
        // it, so the owner's verdict shows a gap rather than "well checked".
        $this->actingAs($request->project->owner)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('proof.1', ['kind' => 'gap', 'text' => 'Only the tests it wrote for itself tried what it does.']));
    }

    public function test_nothing_is_claimed_until_the_checks_pass()
    {
        $request = FeatureRequest::factory()->generated()->create();
        $this->reviewed($request, null);
        $this->checked($request, VerificationStatus::Failed);

        $this->actingAs($request->project->owner)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('proof', []));
    }
}
