<?php

namespace Tests\Feature\Features;

use App\Actions\Features\DescribeProof;
use App\Context\ProjectNotes;
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
                ['kind' => 'passed', 'text' => 'Its code was checked for common safety mistakes, such as unsafe text on a page or unsafe database lookups. None were found.', 'topic' => 'safety'],
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

    public function test_a_change_the_second_look_passed_says_it_was_held_to_the_guidance_the_owner_kept()
    {
        $request = FeatureRequest::factory()->generated()->create();
        $this->checked($request);
        $this->reviewed($request, null);

        $guidance = fn () => collect(app(DescribeProof::class)->handle($request))->pluck('text')->filter(fn (string $text) => str_contains($text, 'guidance'))->values()->all();

        $this->assertSame([], $guidance());

        app(ProjectNotes::class)->put($request->project, 'main', ['project.md' => "# Teams\n\n## Engineering direction\n\n- Keep every payment in one place. (Ada, Thu, Oct 1, 2026)\n- Queue every email. (Ada, Thu, Oct 1, 2026)\n"]);

        $this->assertSame(['A second look checked it against the 2 points of guidance you kept from your developer.'], $guidance());

        $request->latestRun->update(['review' => [...$request->latestRun->review, 'approved' => false]]);

        $this->assertSame([], $guidance());
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
            ->assertInertia(fn (Assert $page) => $page->where('proof.4', ['kind' => 'passed', 'text' => 'Its screens take their colours from your app\'s theme. None were made up.', 'topic' => 'your colours']));
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
            ->assertInertia(fn (Assert $page) => $page->where('proof', fn ($proof) => collect($proof)->contains(['kind' => 'passed', 'text' => $said, 'topic' => 'speed'])));
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
            ->assertInertia(fn (Assert $page) => $page->where('proof.5', ['kind' => 'passed', 'text' => 'The pictures it added say what they show, for people who cannot see the screen.', 'topic' => 'pictures']));
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

        $this->assertContains(['kind' => 'passed', 'text' => $kept, 'topic' => 'sign-in'], $texts([
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

        // The review's map missed a file, but the checks later saw every
        // line run: the later answer is said, and nothing is offered.
        [$request, $mapped] = $proof(
            ['lines' => 12, 'run' => 12, 'own_tests_only' => 12, 'unrun' => []],
            ['areas' => [], 'tests' => 0, 'unmapped' => ['app/Models/Booking.php'], 'foundation' => [], 'by_line' => []],
        );
        $this->assertNotContains($gap, $mapped);
        $request->runs()->sole()->update(['plan' => (new Plan('Book classes.', next: ['Let members cancel']))->toArray()]);
        $this->actingAs($request->project->owner)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('run.plan.next', ['Let members cancel']));

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
            ['kind' => 'passed', 'text' => "We watched what your app saved and sent while its tests used the new code 12 times. {$clean}", 'topic' => 'what it saves'],
            $proof(['reached' => 12])->all(),
        );

        // Tests that fake what is sent hide when it is sent, so only the saving is vouched for.
        $this->assertContains(
            ['kind' => 'passed', 'text' => 'We watched what your app saved while its tests used the new code 12 times. Nothing was saved by mistake. Its tests only pretend to send emails and messages, so we could not watch when it sends them.', 'topic' => 'what it saves'],
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

    public function test_what_the_new_code_saved_while_the_app_checked_or_built_a_page_is_said()
    {
        $proof = function (array $boundaries, int $reached = 12) {
            $request = FeatureRequest::factory()->generated()->create();
            $this->checked($request, evidence: [
                'traces' => ['requests' => 40, 'reached' => $reached, 'unseen' => 0, 'existing' => 0, 'findings' => [], 'repeats' => []],
                'boundaries' => ['phased' => 30, 'unknown' => 0, 'existing' => 0, 'findings' => [], ...$boundaries],
            ]);

            return collect(app(DescribeProof::class)->handle($request));
        };
        $finding = fn (string $kind, string $route) => ['kind' => $kind, 'route' => $route, 'what' => 'update posts', 'at' => 'app/Policies/PostPolicy.php:3', 'in' => 'App\Policies\PostPolicy::view', 'test' => null];
        $clean = 'While its tests used the new code, your app never saved or sent anything while checking who may do something, checking what was filled in, or putting a page together.';

        $this->assertContains(['kind' => 'passed', 'text' => $clean, 'topic' => 'when it saves'], $proof([])->all());

        // Nothing ran the new code, or the recorder could not tell the parts apart: nothing is vouched for.
        $this->assertFalse($proof([], reached: 0)->contains('text', $clean));
        $this->assertFalse($proof(['unknown' => 30])->contains('text', $clean));

        $seen = $proof(['findings' => [
            $finding('changed_while_authorizing', 'GET /posts'),
            $finding('changed_while_authorizing', 'GET /posts/{post}'),
            $finding('changed_while_validating', 'POST /posts'),
            $finding('changed_while_rendering', 'GET /drafts'),
        ]]);
        $this->assertSame([
            'At /posts your app saves or sends something while it checks who may do something. That check can run many times, for example once for each item on a page, so it happens again each time.',
            'At /posts your app saves or sends something while it checks what was filled in. If it then says no, what it saved or sent stays.',
            'At /drafts your app saves or sends something while it puts the page together. That can happen more than once each time the page opens.',
        ], $seen->where('kind', 'gap')->pluck('text')->all());
        $this->assertFalse($seen->contains('text', $clean));
    }

    public function test_what_the_app_left_behind_when_one_thing_was_made_to_fail_is_said()
    {
        $proof = function (array $faults, array $evidence = []) {
            $request = FeatureRequest::factory()->generated()->create();
            $this->checked($request, evidence: ['faults' => ['points' => 4, 'run' => 0, 'missed' => 0, 'existing' => 0, 'findings' => [], ...$faults], ...$evidence]);

            return collect(app(DescribeProof::class)->handle($request));
        };
        $finding = fn (string $kind, string $route, string $failed) => ['kind' => $kind, 'route' => $route, 'failed' => $failed, 'what' => 'insert orders', 'at' => 'app/Models/Order.php:3', 'test' => 'Tests\Feature\OrderTest::test_customers_order'];
        $clean = 'left nothing half done';

        // No failure was really caused: nothing is known, so nothing is said.
        $this->assertFalse($proof(['missed' => 2])->contains(fn (array $line) => str_contains($line['text'], $clean)));

        $this->assertContains(
            ['kind' => 'passed', 'text' => 'We made things go wrong 3 times while your app used the new code, such as an email that cannot be sent or a save that fails. Each time, your app left nothing half done.', 'topic' => 'what goes wrong'],
            $proof(['run' => 3])->all(),
        );
        $this->assertContains(
            ['kind' => 'passed', 'text' => 'We made one thing go wrong while your app used the new code, such as an email that cannot be sent or a save that fails. Your app left nothing half done.', 'topic' => 'what goes wrong'],
            $proof(['run' => 1])->all(),
        );

        // Each kind of thing left behind is one gap, with the first address it happened at. The other addresses are named after it.
        $left = $proof(['run' => 5, 'findings' => [
            $finding('saved_then_failed', 'POST /orders', 'mail App\Mail\Receipt'),
            $finding('saved_then_failed', 'POST /orders/{order}/pay', 'http POST api.stripe.com'),
            $finding('sent_then_lost', 'POST /invitations', 'insert invitations'),
            $finding('saved_in_part', 'POST /teams', 'insert team_user'),
            $finding('file_gone', 'DELETE /documents/{document}', 'delete documents'),
            $finding('done_twice', 'POST /orders', 'job App\Jobs\SendReceipt'),
            $finding('called_again', 'POST /orders/{order}/pay', 'http POST api.stripe.com'),
        ]]);
        $this->assertSame([
            'If an email cannot be sent at /orders, the person sees an error, but your app has already saved what they did. They may try again and do it twice. Something like this also happens at one more place: /orders/{order}/pay.',
            'If saving fails at /invitations, your app has already sent something. People are told about something that was not saved.',
            'If saving fails at /teams, your app keeps one part of what it was saving and loses the rest.',
            'If saving fails at /documents/{document}, your app has already deleted a file. What it kept still points to that file, and the file is gone.',
            'Your app does some work on its own after someone uses /orders. If that work is cut off and starts over, it sends or adds the same thing twice.',
            'If an outside service is slow to answer at /orders/{order}/pay, your app asks it again. The service may then do the same thing twice, such as take a payment twice.',
        ], $left->where('kind', 'gap')->pluck('text')->all());
        $this->assertFalse($left->contains(fn (array $line) => str_contains($line['text'], $clean)));

        // A file deleted before a new file that was not stored is said by what failed.
        $this->assertSame(
            ['If a file cannot be stored at /profile/photo, your app has already deleted a file. What it kept still points to that file, and the file is gone.'],
            $proof(['run' => 1, 'findings' => [$finding('file_gone', 'POST /profile/photo', 'file write')]])->where('kind', 'gap')->pluck('text')->all(),
        );

        // A notice that cannot be left, and a cache that is down, are said so.
        $this->assertSame(
            ['If a notice cannot be left for someone at /bookings, the person sees an error, but your app has already saved what they did. They may try again and do it twice.'],
            $proof(['run' => 1, 'findings' => [$finding('saved_then_failed', 'POST /bookings', 'notification App\Notifications\BookingMade')]])->where('kind', 'gap')->pluck('text')->all(),
        );
        $this->assertSame(
            ['If the cache is down at /rooms, the person sees an error, but your app has already saved what they did. They may try again and do it twice.'],
            $proof(['run' => 1, 'findings' => [$finding('saved_then_failed', 'POST /rooms', 'cache write')]])->where('kind', 'gap')->pluck('text')->all(),
        );

        // A file the app moved is said as moved, and a move that fails as a move.
        $this->assertSame(
            ['If saving fails at /documents/{document}/publish, your app has already moved a file. What it kept still points to where the file was, and the file is not there.'],
            $proof(['run' => 1, 'findings' => [[...$finding('file_gone', 'POST /documents/{document}/publish', 'update documents'), 'what' => 'file move']]])->where('kind', 'gap')->pluck('text')->all(),
        );
        $this->assertSame(
            ['If a file cannot be moved at /documents/{document}/publish, your app carries on as if it worked. The person sees the same as when it works, and nothing is written down, so you would not find out.'],
            $proof(['run' => 1, 'findings' => [$finding('failure_hidden', 'POST /documents/{document}/publish', 'file move')]])->where('kind', 'gap')->pluck('text')->all(),
        );

        // Work that sends twice only when its save fails is said in its own words,
        // and once when it also sends twice each time it starts over.
        $again = $finding('sent_again', 'POST /orders', 'job App\Jobs\SendReceipt');
        $this->assertSame(
            ['Your app does some work on its own after someone uses /orders. If saving fails during that work and it starts over, it sends the same thing twice.'],
            $proof(['run' => 1, 'findings' => [$again]])->where('kind', 'gap')->pluck('text')->all(),
        );
        $this->assertSame(
            ['Your app does some work on its own after someone uses /orders. If that work is cut off and starts over, it sends or adds the same thing twice.'],
            $proof(['run' => 2, 'findings' => [$finding('done_twice', 'POST /orders', 'job App\Jobs\SendReceipt'), $again]])->where('kind', 'gap')->pluck('text')->all(),
        );

        $this->assertSame(
            ['If an outside service does not answer at /orders/{order}/pay, the person sees an error, but your app has already saved what they did. They may try again and do it twice.'],
            $proof(['run' => 1, 'findings' => [$finding('saved_then_failed', 'POST /orders/{order}/pay', 'http POST api.stripe.com')]])->where('kind', 'gap')->pluck('text')->all(),
        );

        $this->assertSame(
            ['When someone uses /orders, your app does a few things one after the other, and nothing says which comes first. When they happen the other way round, your app does not do the same things.'],
            $proof(['run' => 1, 'findings' => [$finding('depends_on_order', 'POST /orders', 'event App\Events\OrderPlaced')]])->where('kind', 'gap')->pluck('text')->all(),
        );

        $this->assertSame(
            ['Your app does some work on its own after someone uses /orders, and does not wait for it. But what your app does next only goes right when that work is already done.'],
            $proof(['run' => 1, 'findings' => [$finding('needs_job_done', 'POST /orders', 'job App\Jobs\SendReceipt')]])->where('kind', 'gap')->pluck('text')->all(),
        );

        $this->assertSame(
            ['Your app does some work on its own after someone uses /orders. That work runs a moment later, after your app has answered. By then something it counts on is gone, such as who the person is, and it does not do the same things.'],
            $proof(['run' => 1, 'findings' => [$finding('job_needs_request', 'POST /orders', 'job App\Jobs\SendReceipt')]])->where('kind', 'gap')->pluck('text')->all(),
        );

        $this->assertSame(
            ['If an outside service says it could not do what your app asked at /orders/{order}/pay, your app does not look at that answer. It carries on as if the service did it.'],
            $proof(['run' => 1, 'findings' => [$finding('answer_not_checked', 'POST /orders/{order}/pay', 'http POST api.stripe.com')]])->where('kind', 'gap')->pluck('text')->all(),
        );

        // A failure the app hides is said by what failed: an email, an outside service, a file or a save.
        $this->assertSame([
            ['If an email cannot be sent at /orders, your app carries on as if it worked. The person sees the same as when it works, and nothing is written down, so you would not find out.'],
            ['If an outside service does not answer at /orders, your app carries on as if it worked. The person sees the same as when it works, and nothing is written down, so you would not find out.'],
            ['If a file cannot be stored at /orders, your app carries on as if it worked. The person sees the same as when it works, and nothing is written down, so you would not find out.'],
            ['If saving fails at /orders, your app carries on as if it worked. The person sees the same as when it works, and nothing is written down, so you would not find out.'],
        ], array_map(
            fn (string $failed) => $proof(['run' => 1, 'findings' => [$finding('failure_hidden', 'POST /orders', $failed)]])->where('kind', 'gap')->pluck('text')->all(),
            ['mail App\Mail\Receipt', 'http POST api.stripe.com', 'file write', 'insert orders'],
        ));

        // The same thing at more than one place: the owner's choice is for all of them, so each is named.
        $this->assertSame([
            ['If an email cannot be sent at /orders, your app carries on as if it worked. The person sees the same as when it works, and nothing is written down, so you would not find out. Something like this also happens at one more place: /refunds.'],
            ['If an email cannot be sent at /orders, your app carries on as if it worked. The person sees the same as when it works, and nothing is written down, so you would not find out. Something like this also happens at 3 more places, such as /refunds and the work “reminders send”.'],
            // Two things that fail at one address are one place.
            ['If an email cannot be sent at /orders, your app carries on as if it worked. The person sees the same as when it works, and nothing is written down, so you would not find out.'],
        ], array_map(
            fn (array $routes) => $proof(['run' => 1, 'findings' => array_map(fn (string $route) => $finding('failure_hidden', $route, 'mail App\\Mail\\Receipt'), $routes)])->where('kind', 'gap')->pluck('text')->all(),
            [['POST /orders', 'POST /refunds'], ['POST /orders', 'POST /refunds', 'ARTISAN reminders:send', 'POST /invoices', 'POST /refunds'], ['POST /orders', 'POST /orders']],
        ));

        // A failure hidden in work the app leaves for later has no person who sees an answer.
        $this->assertSame([
            ['Your app does some work on its own after someone uses /orders. If an email cannot be sent during that work, it carries on as if it worked. Nothing is written down, so you would not find out.'],
            ['Your app does some work on its own (“reminders send”) and leaves part of it for later. If an email cannot be sent during that part, it carries on as if it worked. Nothing is written down, so you would not find out.'],
        ], array_map(
            fn (string $route) => $proof(['run' => 1, 'findings' => [[...$finding('failure_hidden', $route, 'mail App\Mail\Receipt'), 'job' => true]]])->where('kind', 'gap')->pluck('text')->all(),
            ['POST /orders', 'ARTISAN reminders:send'],
        ));

        // Work the app does on its own at set times has no person and no address: it is said by its name.
        $this->assertSame([
            ['Your app does some work on its own (“reminders send”). If an email cannot be sent during that work, it stops, but it has already saved part of it. The next time, it may skip that part or do it twice.'],
            ['Your app does some work on its own (“reminders send”). If an email cannot be sent during that work, it carries on as if it worked. Nothing is written down, so you would not find out.'],
            ['Your app does some work on its own (“reminders send”) and leaves part of it for later. If that part is cut off and starts over, it sends or adds the same thing twice.'],
        ], array_map(
            fn (string $kind) => $proof(['run' => 1, 'findings' => [$finding($kind, 'ARTISAN reminders:send', 'mail App\Mail\Reminder')]])->where('kind', 'gap')->pluck('text')->all(),
            ['saved_then_failed', 'failure_hidden', 'done_twice'],
        ));
        // A job that was seen by itself is said by its name too.
        $this->assertSame([
            ['Your app does some work on its own (“send reminder”). If that work is cut off and starts over, it sends or adds the same thing twice.'],
            ['Your app does some work on its own (“send reminder”). If saving fails during that work and it starts over, it sends the same thing twice.'],
        ], array_map(
            fn (string $kind) => $proof(['run' => 1, 'findings' => [$finding($kind, 'JOB App\\Jobs\\SendReminder', 'job App\\Jobs\\SendReminder')]])->where('kind', 'gap')->pluck('text')->all(),
            ['done_twice', 'sent_again'],
        ));
        // One failure that stops the rest is said with or without an address.
        $this->assertSame([
            ['Your app sends to several people at /orders. If an email cannot be sent for one of them, your app stops there, and the people after them get nothing.'],
            ['Your app does some work on its own (“reminders send”) and sends to several people. If an email cannot be sent for one of them, it stops there, and the people after them get nothing.'],
        ], array_map(
            fn (string $route) => $proof(['run' => 1, 'findings' => [$finding('rest_not_sent', $route, 'mail App\Mail\Reminder')]])->where('kind', 'gap')->pluck('text')->all(),
            ['POST /orders', 'ARTISAN reminders:send'],
        ));
        // Work that sends to many and starts over after one email failed is said by what failed.
        $this->assertSame(
            ['Your app does some work on its own (“send reminder”). If an email cannot be sent during that work and it starts over, it sends the same thing twice.'],
            $proof(['run' => 1, 'findings' => [$finding('sent_again', 'JOB App\\Jobs\\SendReminder', 'mail App\\Mail\\Reminder')]])->where('kind', 'gap')->pluck('text')->all(),
        );
        // Work that starts over and does not send what it could not send is said for each way the work runs.
        $this->assertSame([
            ['Your app does some work on its own after someone uses /orders. If an email cannot be sent during that work and it starts over, it does not try to send again. What it had to send is never sent.'],
            ['Your app does some work on its own (“reminders send”) and leaves part of it for later. If an email cannot be sent during that part and it starts over, it does not try to send again. What it had to send is never sent.'],
            ['Your app does some work on its own (“send reminder”). If an email cannot be sent during that work and it starts over, it does not try to send again. What it had to send is never sent.'],
        ], array_map(
            fn (string $route) => $proof(['run' => 1, 'findings' => [$finding('never_sent', $route, 'mail App\Mail\Reminder')]])->where('kind', 'gap')->pluck('text')->all(),
            ['POST /orders', 'ARTISAN reminders:send', 'JOB App\\Jobs\\SendReminder'],
        ));

        // The recording already said it sends before saving ends: it is said once.
        $twice = $proof(['run' => 1, 'findings' => [$finding('sent_then_lost', 'POST /invitations', 'insert invitations')]], ['traces' => ['requests' => 40, 'reached' => 12, 'unseen' => 0, 'existing' => 0, 'repeats' => [], 'findings' => [
            ['kind' => 'sent_before_saved', 'route' => 'POST /invitations', 'what' => 'mail App\Mail\Invited', 'at' => 'app/Models/Order.php:3', 'test' => null],
        ]]]);
        $this->assertSame(
            ['At /invitations your app sends something before it has finished saving. If saving fails, it is sent anyway.'],
            $twice->where('kind', 'gap')->pluck('text')->all(),
        );
        $this->assertFalse($twice->contains(fn (array $line) => str_contains($line['text'], $clean)));
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

    public function test_a_change_that_only_failed_where_the_app_failed_before_still_says_what_it_proved()
    {
        $request = FeatureRequest::factory()->generated()->create();
        $this->checked($request, VerificationStatus::Failed);
        $verification = $request->verifications()->sole();
        $results = $verification->results;
        $results[1] = [...$results[1], 'outcome' => 'failed', 'at_start' => 'failed', 'new_problems' => []];
        $results[1]['tests'][2]['outcome'] = 'failed';
        $verification->update(['results' => $results]);

        $proof = collect(app(DescribeProof::class)->handle($request->refresh()));

        $this->assertContains(['kind' => 'passed', 'text' => '2 of the app\'s own tests still pass.'], $proof->all());
        $this->assertContains(['kind' => 'gap', 'text' => 'One test was already failing before this change. Ask me to fix it.'], $proof->all());

        // A problem new with the change takes every claim back.
        $results[1]['new_problems'] = ['test_seats_follow_members'];
        $verification->update(['results' => $results]);

        $this->assertSame([], app(DescribeProof::class)->handle($request->refresh()));
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
