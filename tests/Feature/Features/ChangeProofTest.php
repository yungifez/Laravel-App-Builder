<?php

namespace Tests\Feature\Features;

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
     * checks on the code, and the separate checks.
     */
    protected function checked(FeatureRequest $request, VerificationStatus $status = VerificationStatus::Passed): void
    {
        $result = fn (string $name, string $stage, array $extra = []) => ['name' => $name, 'stage' => $stage, 'outcome' => 'passed', 'exit_code' => 0, 'timed_out' => false, 'duration_ms' => 5, 'output' => '', ...$extra];

        Verification::factory()->for($request)->create([
            'status' => $status,
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
