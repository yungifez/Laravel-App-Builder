<?php

namespace Tests\Feature\VisualEditing;

use App\Actions\Projects\CreateProject;
use App\Actions\VisualEditing\CommitDesignEdits;
use App\Enums\VerificationStatus;
use App\Jobs\CatchUpDesignDraft;
use App\Jobs\RebuildPreview;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use App\Models\Verification;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use App\VisualEditing\DesignDrafts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

/**
 * Design edits on the app wait in a draft, and join the app only when the
 * owner keeps them and the checks clear them.
 */
class DesignEditsTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected const CARD = <<<'VUE'
    <template>
        <div class="flex gap-4 p-4 text-sm">
            <h1 class="text-xl">Plans</h1>
            <p>Pick one</p>
        </div>
    </template>

    VUE;

    protected ProjectRepository $repository;

    protected User $owner;

    protected Project $project;

    protected Preview $preview;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->fakeWorkspaces();
        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada@example.com']);
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([
            'resources/js/pages/Plans.vue' => self::CARD,
        ]), draftNotes: false);
        $this->repository->import($this->project);
        $this->preview = Preview::factory()->editable($this->repository->head($this->project))->ready()->create([
            'project_id' => $this->project->id,
            'workspace_id' => Workspace::factory()->create(['user_id' => $this->owner->id])->id,
        ]);
    }

    public function test_a_design_edit_on_the_app_waits_in_a_draft_and_the_app_does_not_move()
    {
        $main = $this->repository->head($this->project);

        $this->edit(['gap' => 24]);

        $this->assertSame($main, $this->repository->head($this->project), 'The app itself does not move.');

        $draft = $this->draft();
        $this->assertSame($main, $draft->base_revision);
        $this->assertStringContainsString('md:gap-6', (string) $this->repository->show($this->project, $this->repository->head($this->project, $draft->designBranch()), 'resources/js/pages/Plans.vue'));
        $this->assertStringContainsString('+    <div class="flex gap-4 p-4 text-sm md:gap-6">', (string) $draft->patch);
        $this->assertSame($draft->designBranch(), $this->preview->refresh()->branch(), "The app's preview shows the draft.");
        $this->assertTrue($this->project->visualEdits()->sole()->featureRequest->is($draft));
        Queue::assertPushed(RebuildPreview::class, fn (RebuildPreview $job) => $job->preview->is($this->preview));

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('designEdits', ['edits' => 1, 'checking' => false, 'problem' => null])
                ->has('edits', 1));
    }

    public function test_a_second_edit_goes_into_the_same_draft()
    {
        $this->edit(['gap' => 24]);
        $this->edit(['gap' => 32], 'flex gap-4 p-4 text-sm md:gap-6');

        $this->assertSame(1, FeatureRequest::query()->where('generator', DesignDrafts::GENERATOR)->count());
        $this->assertStringContainsString('md:gap-8', (string) $this->draft()->patch);
    }

    public function test_keeping_the_edits_checks_them_and_refuses_more_edits_until_the_check_ends()
    {
        $this->edit(['gap' => 24]);

        $this->actingAs($this->owner)
            ->post(route('design-edits.store', $this->project))
            ->assertSessionHasNoErrors();

        Queue::assertPushed(VerifyFeatureRequest::class, fn (VerifyFeatureRequest $job) => $job->verification->featureRequest->is($this->draft()));

        $this->actingAs($this->owner)
            ->post(route('visual-edits.store', $this->project), $this->editFields(['gap' => 32], 'flex gap-4 p-4 text-sm md:gap-6'))
            ->assertSessionHasErrors(['edit' => 'Your edits are being checked. You can change more when that is done.']);

        $this->actingAs($this->owner)
            ->post(route('visual-edits.reversion.store', $this->project->visualEdits()->sole()))
            ->assertSessionHasErrors('edit');

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('designEdits.checking', true));
    }

    public function test_edits_the_checks_clear_join_the_app_as_one_commit()
    {
        $main = $this->repository->head($this->project);
        $this->edit(['gap' => 24]);
        $draft = $this->draft();

        $this->finishCheck($draft, VerificationStatus::Unverified);

        $draft->refresh();
        $head = $this->repository->head($this->project);
        $this->assertNotSame($main, $head);
        $this->assertSame($head, $draft->commit_sha);
        $this->assertNotNull($draft->accepted_at);
        $this->assertStringContainsString('md:gap-6', (string) $this->repository->show($this->project, $head, 'resources/js/pages/Plans.vue'));
        $this->assertFalse($this->repository->hasBranch($this->project, $draft->designBranch()));
        $this->assertSame($this->project->branch(), $this->preview->refresh()->branch(), "The app's preview shows the app again.");
        $this->assertNull(app(DesignDrafts::class)->find($this->project));
    }

    public function test_edits_that_break_a_check_wait_and_the_owner_is_told_why()
    {
        $main = $this->repository->head($this->project);
        $this->edit(['gap' => 24]);

        $this->finishCheck($this->draft(), VerificationStatus::Failed, [
            ['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'failed', 'exit_code' => 1, 'timed_out' => false, 'duration_ms' => 10, 'output' => 'failed'],
        ]);

        $this->assertSame($main, $this->repository->head($this->project));
        $this->assertNull($this->draft()->accepted_at);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('designEdits.checking', false)
                ->where('designEdits.problem', 'Your edits would break part of your app, so they are not kept. Undo the edit that did it, or change it.'));

        // Editing again starts over: the old problem is not told any more.
        $this->travel(1)->minute();
        $this->edit(['gap' => 32], 'flex gap-4 p-4 text-sm md:gap-6');

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('designEdits.problem', null));
    }

    public function test_a_check_that_failed_only_as_before_lets_the_edits_in()
    {
        $this->edit(['gap' => 24]);
        $draft = $this->draft();

        $this->finishCheck($draft, VerificationStatus::Failed, [
            ['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'failed', 'exit_code' => 1, 'timed_out' => false, 'duration_ms' => 10, 'output' => 'failed', 'at_start' => 'failed', 'new_problems' => []],
        ]);

        $this->assertNotNull($draft->refresh()->commit_sha);
    }

    public function test_undo_all_throws_the_draft_away_and_the_app_never_had_it()
    {
        $main = $this->repository->head($this->project);
        $this->edit(['gap' => 24]);
        $draft = $this->draft();

        $this->actingAs($this->owner)
            ->delete(route('design-edits.destroy', $this->project))
            ->assertSessionHasNoErrors();

        $this->assertNotNull($draft->refresh()->dismissed_at);
        $this->assertFalse($this->repository->hasBranch($this->project, $draft->designBranch()));
        $this->assertSame($main, $this->repository->head($this->project));
        $this->assertSame($this->project->branch(), $this->preview->refresh()->branch());

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('designEdits', null)->has('edits', 0));
    }

    public function test_the_draft_moves_onto_what_the_app_gains_while_it_waits()
    {
        $this->edit(['gap' => 24]);
        $draft = $this->draft();

        $main = $this->repository->commitFiles($this->project, $this->repository->head($this->project), ['README.md' => "Acme\n"], 'Add a readme', null);

        Queue::assertPushed(CatchUpDesignDraft::class, function (CatchUpDesignDraft $job) {
            app()->call([$job, 'handle']);

            return true;
        });

        $draft->refresh();
        $this->assertSame($main, $draft->base_revision);
        $tip = $this->repository->head($this->project, $draft->designBranch());
        $this->assertSame("Acme\n", $this->repository->show($this->project, $tip, 'README.md'));
        $this->assertStringContainsString('md:gap-6', (string) $this->repository->show($this->project, $tip, 'resources/js/pages/Plans.vue'));
    }

    public function test_kept_words_and_size_edits_join_the_app_together()
    {
        $this->editHeading(['text_size' => '2xl'], 'text-xl');
        $this->actingAs($this->owner)
            ->post(route('visual-texts.store', $this->project), [
                'preview' => $this->preview->uuid,
                'target' => 'resources/js/pages/Plans.vue:3:9',
                'before' => 'Plans',
                'text' => 'Prices',
                'revision' => $this->repository->head($this->project, $this->preview->refresh()->branch()),
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->post(route('design-edits.store', $this->project))
            ->assertSessionHasNoErrors();
        $this->finishCheck($this->draft(), VerificationStatus::Unverified);

        $this->assertStringContainsString(
            '<h1 class="text-xl md:text-2xl">Prices</h1>',
            (string) $this->repository->show($this->project, $this->repository->head($this->project), 'resources/js/pages/Plans.vue'),
        );
    }

    public function test_an_undone_edit_stays_out_when_the_rest_are_kept()
    {
        $this->edit(['gap' => 24]);
        $this->editHeading(['text_size' => '2xl'], 'text-xl');

        $this->actingAs($this->owner)
            ->post(route('visual-edits.reversion.store', $this->project->visualEdits()->latest('id')->firstOrFail()))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)
            ->post(route('design-edits.store', $this->project))
            ->assertSessionHasNoErrors();
        $this->finishCheck($this->draft(), VerificationStatus::Unverified);

        $kept = (string) $this->repository->show($this->project, $this->repository->head($this->project), 'resources/js/pages/Plans.vue');
        $this->assertStringContainsString('md:gap-6', $kept);
        $this->assertStringContainsString('<h1 class="text-xl">Plans</h1>', $kept);
    }

    public function test_there_is_nothing_to_keep_without_edits()
    {
        $this->actingAs($this->owner)
            ->post(route('design-edits.store', $this->project))
            ->assertSessionHasErrors(['keep' => 'There are no edits to keep.']);
    }

    public function test_only_the_owner_can_keep_or_throw_away_the_edits()
    {
        $this->edit(['gap' => 24]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('design-edits.store', $this->project))->assertForbidden();
        $this->actingAs($stranger)->delete(route('design-edits.destroy', $this->project))->assertForbidden();
        $this->assertNull($this->draft()->dismissed_at);
    }

    /**
     * @param  array<string, int>  $changes
     */
    protected function edit(array $changes, string $expected = 'flex gap-4 p-4 text-sm'): void
    {
        $this->actingAs($this->owner)
            ->post(route('visual-edits.store', $this->project), $this->editFields($changes, $expected))
            ->assertSessionHasNoErrors();
    }

    /**
     * @param  array<string, int>  $changes
     * @return array<string, mixed>
     */
    protected function editFields(array $changes, string $expected): array
    {
        return [
            'preview' => $this->preview->uuid,
            'target' => 'resources/js/pages/Plans.vue:2:5',
            'revision' => $this->repository->head($this->project, $this->preview->refresh()->branch()),
            'expected' => $expected,
            'device' => 'md',
            'changes' => $changes,
        ];
    }

    /**
     * @param  array<string, string>  $changes
     */
    protected function editHeading(array $changes, string $expected): void
    {
        $this->actingAs($this->owner)
            ->post(route('visual-edits.store', $this->project), [
                ...$this->editFields([], $expected),
                'target' => 'resources/js/pages/Plans.vue:3:9',
                'changes' => $changes,
            ])
            ->assertSessionHasNoErrors();
    }

    protected function draft(): FeatureRequest
    {
        return FeatureRequest::query()->where('generator', DesignDrafts::GENERATOR)->sole();
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    protected function finishCheck(FeatureRequest $draft, VerificationStatus $status, array $results = []): void
    {
        $verification = Verification::query()->forceCreate([
            'feature_request_id' => $draft->id,
            'status' => $status,
            'results' => $results,
            'finished_at' => now(),
        ]);

        app(CommitDesignEdits::class)->handle($verification);
    }
}
