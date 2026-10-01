<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\NarrateWork;
use App\Context\ProjectNotes;
use App\Models\Run;
use App\Models\Verification;
use App\Runs\Agents\RunnerAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class WorkStoryTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    public function test_only_the_agents_plain_sentences_reach_the_owner()
    {
        $narrate = app(NarrateWork::class);

        $this->assertSame(
            'Only team owners should send invitations, so I am adding that check first. Then people get an email with a link to join.',
            $narrate->plain("Now, only team owners should send invitations, so I am adding that check first. Then people get an email with a link to join.\nAfter that I tidy up."),
        );
        $this->assertSame('Everyone on the team can see the new page.', $narrate->plain("**Everyone on the team can see the new page.**\n- Let me look.\n- I'll update `TeamPolicy::invite`."));

        foreach ([
            'Let me read app/Policies/TeamPolicy.php to see the rules.',
            'I will add an invitations table with a migration next.',
            'The instructions say I must not change those tests at all.',
            'I am calling sendInvitation when the form is saved.',
            'I will run php artisan test to check the whole thing.',
            'Let me look.',
            "Let's update the frontend tests to cover this new display.",
            'To keep date formatting deterministic regardless of the timezone, let me pin it to UTC.',
            'Purely formatting changes like attribute wrapping and Tailwind class ordering.',
        ] as $technical) {
            $this->assertNull($narrate->plain($technical), $technical);
        }
    }

    public function test_the_story_tells_each_stage_and_names_the_parts_of_the_app_in_the_owners_words()
    {
        [$run] = $this->implementingRun();
        $run->update([
            'context' => ['outline' => [['key' => 'teams', 'name' => 'Teams', 'paths' => ['app/Models/Team.php', 'config/teams.php']]]],
            'plan' => [...($run->plan ?? []), 'steps' => [['key' => 'permission', 'kind' => 'permission', 'label' => 'Who may invite', 'file' => 'app/Policies/TeamPolicy.php', 'symbol' => 'TeamPolicy::invite', 'detail' => 'Owners only.']]],
        ]);
        $run->recordEvent('agent_story', ['story' => [
            ['kind' => 'said', 'text' => 'First I want to understand how teams work today.'],
            ['kind' => 'read', 'file' => 'app/Models/Team.php'],
            ['kind' => 'read', 'file' => 'config/teams.php'],
            ['kind' => 'read', 'file' => 'routes/web.php'],
            ['kind' => 'said', 'text' => 'Only team owners should send invitations, so I am adding that check first.'],
            ['kind' => 'changed', 'file' => 'app/Policies/TeamPolicy.php'],
            ['kind' => 'changed', 'file' => 'app/Models/Team.php'],
            ['kind' => 'changed', 'file' => 'tests/Feature/InviteTest.php'],
            ['kind' => 'testing'],
            ['kind' => 'testing'],
            ['kind' => 'changed', 'file' => ProjectNotes::directory().'/teams.md'],
        ]]);
        $run->recordEvent('status', ['from' => 'verifying', 'to' => 'implementing', 'reason' => 'verification_failed']);
        $run->recordEvent('agent_story', ['story' => [['kind' => 'changed', 'file' => 'app/Other.php']]]);
        $run->recordEvent('status', ['from' => 'verifying', 'to' => 'reviewing', 'verification' => 'passed']);
        $run->recordEvent('review', ['approved' => true]);

        $this->assertSame([
            ['kind' => 'thought', 'text' => 'First I want to understand how teams work today.'],
            ['kind' => 'read', 'text' => 'Looked at how Teams works'],
            ['kind' => 'thought', 'text' => 'Only team owners should send invitations, so I am adding that check first.'],
            ['kind' => 'changed', 'text' => 'Worked on “Who may invite” and Teams'],
            ['kind' => 'tested', 'text' => 'Wrote a test for it'],
            ['kind' => 'tried', 'text' => 'Tried it out'],
            ['kind' => 'noted', 'text' => 'Wrote down what I learned'],
            ['kind' => 'stage', 'text' => 'Some checks failed, so I went back to fix them'],
            ['kind' => 'changed', 'text' => 'Changed a part of your app'],
            ['kind' => 'stage', 'text' => 'The checks passed. Looking over what changed'],
            ['kind' => 'stage', 'text' => 'The change looks right'],
        ], app(NarrateWork::class)->handle($run->refresh()));
    }

    public function test_the_owner_follows_the_story_while_the_change_is_made()
    {
        [$run] = $this->implementingRun();
        $path = $this->workspaceFile($run, RunnerAgent::TASK_DIRECTORY.'/progress.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode(['doing' => 'reading', 'last' => null, 'read' => [], 'changed' => [], 'story' => [
            ['kind' => 'said', 'text' => 'First I want to understand how teams work today.'],
            ['kind' => 'read', 'file' => 'routes/web.php'],
            // Its reasons show too, as plainly as what it says.
            ['kind' => 'thinking', 'text' => "The owner wants only team owners to invite people. I should check `TeamPolicy` first.\nThat keeps members from inviting strangers."],
            ['kind' => 'thinking', 'text' => 'Let me read app/Policies/TeamPolicy.php.'],
        ]]));
        Cache::flush();

        $this->actingAs($run->featureRequest->project->owner)
            ->get(route('projects.show', ['project' => $run->featureRequest->project, 'change' => $run->featureRequest->uuid]))
            ->assertInertia(fn (Assert $page) => $page->where('change.run.work', [
                ['kind' => 'thought', 'text' => 'First I want to understand how teams work today.'],
                ['kind' => 'read', 'text' => 'Looked around your app'],
                ['kind' => 'thinking', 'text' => 'The owner wants only team owners to invite people. That keeps members from inviting strangers.'],
            ]));
    }

    public function test_each_finished_check_says_what_it_found_in_the_story()
    {
        [$run] = $this->implementingRun();
        $check = fn (string $name, string $stage, string $outcome, array $extra = []) => ['name' => $name, 'stage' => $stage, 'outcome' => $outcome, 'exit_code' => 0, 'timed_out' => false, 'duration_ms' => 10, 'output' => '', ...$extra];

        $run->recordEvent('status', ['from' => 'implementing', 'to' => 'verifying']);
        Verification::factory()->create(['feature_request_id' => $run->feature_request_id, 'run_id' => $run->id, 'results' => [
            $check('Install PHP dependencies', 'setup', 'passed'),
            $check('Tests', 'checks', 'failed', ['tests' => [['file' => 'tests/A.php', 'name' => 'a', 'outcome' => 'failed']]]),
            $check('Static analysis', 'checks', 'failed', ['at_start' => 'failed', 'new_problems' => []]),
            $check('Their own check', 'checks', 'skipped'),
        ]]);
        $run->recordEvent('status', ['from' => 'verifying', 'to' => 'implementing', 'reason' => 'verification_failed']);
        $run->recordEvent('status', ['from' => 'implementing', 'to' => 'verifying']);
        // The second check is still running: what has finished shows.
        Verification::factory()->create(['feature_request_id' => $run->feature_request_id, 'run_id' => $run->id, 'results' => [
            $check('Tests', 'checks', 'passed', ['tests' => [['file' => 'tests/A.php', 'name' => 'a', 'outcome' => 'passed'], ['file' => 'tests/B.php', 'name' => 'b', 'outcome' => 'passed']]]),
            $check('Their own check', 'checks', 'passed'),
            $check('Invite', 'acceptance', 'passed'),
            $check('Invite again', 'acceptance', 'passed'),
        ]]);

        $this->assertSame([
            ['kind' => 'stage', 'text' => 'Checking it works'],
            ['kind' => 'failed', 'text' => 'Ran your app\'s tests: it found a problem'],
            ['kind' => 'known', 'text' => 'Read the code for mistakes: it found a problem that was there before this change'],
            ['kind' => 'stage', 'text' => 'Some checks failed, so I went back to fix them'],
            ['kind' => 'stage', 'text' => 'Checking it works'],
            ['kind' => 'passed', 'text' => 'Ran your app\'s tests: all 2 pass'],
            ['kind' => 'passed', 'text' => 'Ran “Their own check”'],
            ['kind' => 'passed', 'text' => 'Tried it the way you asked for it, and it works'],
        ], app(NarrateWork::class)->handle($run->refresh()));
    }

    public function test_the_story_tells_what_the_second_look_caught_in_plain_words()
    {
        [$run] = $this->implementingRun();
        $run->recordEvent('review', ['approved' => false, 'findings' => [
            ['severity' => 'blocking', 'file' => null, 'summary' => 'The count is beside the “Team name” label, while the team’s name appears separately in the input below it. The new test checks adjacency to the label.'],
            // Missing tests show in the checks; code is not for the owner.
            ['severity' => 'blocking', 'file' => null, 'summary' => 'No test in the change checks: owners can still rename the team.'],
            ['severity' => 'blocking', 'file' => 'app/Models/Team.php', 'summary' => 'The `members_count` query runs once per team in the switcher list.'],
            ['severity' => 'minor', 'file' => null, 'summary' => 'The switcher spacing looks slightly uneven between the team rows.'],
        ]]);

        $this->assertSame([
            ['kind' => 'stage', 'text' => 'I found something to fix'],
            ['kind' => 'failed', 'text' => 'The count is beside the “Team name” label, while the team’s name appears separately in the input below it.'],
        ], app(NarrateWork::class)->handle($run->refresh()));
    }

    public function test_a_change_made_without_a_story_shows_none()
    {
        [$run] = $this->implementingRun();

        $this->assertSame([], app(NarrateWork::class)->handle(Run::query()->findOrFail($run->id)));
    }
}
