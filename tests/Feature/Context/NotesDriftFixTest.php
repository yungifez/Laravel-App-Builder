<?php

namespace Tests\Feature\Context;

use App\Actions\Projects\CreateProject;
use App\Context\Capability;
use App\Context\ProjectNotes;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class NotesDriftFixTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected const PLANS_NOTES = <<<'MARKDOWN'
    ---
    capability: plans
    summary: Customers pick a plan.
    paths:
        - app/Models/Plan.php
        - app/Billing/*
    effects:
        - to: teams
          strength: strong
          reason: Each team has one plan.
          source: owner
        - to: invoices
          strength: possible
          reason: Plans are billed.
          source: agent
    ---

    # Plans

    ## Rules

    - Every customer sees the same plans.

    MARKDOWN;

    protected const TEAMS_NOTES = <<<'MARKDOWN'
    ---
    capability: teams
    paths: [app/Models/Team.php]
    ---
    # Teams

    MARKDOWN;

    protected ProjectRepository $repository;

    protected ProjectNotes $notes;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(ProjectRepository::class);
        $this->notes = app(ProjectNotes::class);
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([
            '.builder/capabilities/plans.md' => self::PLANS_NOTES,
            '.builder/capabilities/teams.md' => self::TEAMS_NOTES,
            'app/Models/Plan.php' => "<?php\n",
            'app/Models/Team.php' => "<?php\n",
        ]), draftNotes: false);
        $this->repository->import($this->project);
    }

    public function test_the_owner_removes_files_the_notes_point_to_that_are_not_in_the_app(): void
    {
        $this->fix('paths:plans', ['app/Billing/*'])->assertSessionHasNoErrors();

        $plans = $this->plans();
        $this->assertStringContainsString("paths:\n    - app/Models/Plan.php\neffects:", $plans);
        $this->assertStringNotContainsString('app/Billing', $plans);
        // The rest of the file is kept as it was.
        $this->assertStringContainsString("summary: Customers pick a plan.\n", $plans);
        $this->assertStringContainsString("# Plans\n\n## Rules\n\n- Every customer sees the same plans.\n", $plans);
        $this->assertNotContains('paths:plans', $this->fixesFound());
    }

    public function test_the_owner_removes_a_connection_to_a_part_the_notes_do_not_describe(): void
    {
        $this->fix('effects:plans', ['invoices'])->assertSessionHasNoErrors();

        $effects = Capability::fromMarkdown('capabilities/plans.md', $this->plans())->effects;
        $this->assertSame([['to' => 'teams', 'strength' => 'strong', 'reason' => 'Each team has one plan.', 'source' => 'owner', 'observed' => null]], array_map(fn ($effect) => $effect->toArray(), $effects));
        $this->assertNotContains('effects:plans', $this->fixesFound());
    }

    public function test_notes_changed_since_or_files_back_in_the_app_are_left_alone(): void
    {
        $before = $this->plans();

        $this->fix('paths:plans', ['app/Billing/*'], revision: str_repeat('a', 40))
            ->assertSessionHasErrors(['fix' => 'The notes changed since you checked. Check your app again.']);

        // The file came back: the check no longer finds it, so it stays.
        $this->repository->commitFiles($this->project, $this->repository->head($this->project), ['app/Billing/Invoice.php' => "<?php\n"], 'Add billing', null);

        $this->fix('paths:plans', ['app/Billing/*'])
            ->assertSessionHasErrors(['fix' => 'This is already fixed. Check your app again.']);
        // Only what the check finds is taken out, never what the owner names.
        $this->fix('paths:plans', ['app/Models/Plan.php'])
            ->assertSessionHasErrors(['fix' => 'This is already fixed. Check your app again.']);

        $this->assertSame($before, $this->plans());
    }

    /**
     * @param  list<string>  $remove
     * @return TestResponse<Response>
     */
    protected function fix(string $part, array $remove, ?string $revision = null): TestResponse
    {
        return $this->actingAs($this->owner)
            ->from(route('projects.understanding.show', $this->project))
            ->post(route('projects.notes-fixes.store', $this->project), [
                'part' => $part,
                'remove' => $remove,
                'revision' => $revision ?? $this->notes->version($this->project),
            ]);
    }

    protected function plans(): string
    {
        return (string) ($this->notes->files($this->project)['capabilities/plans.md'] ?? '');
    }

    /**
     * Get the parts the quick check offers to fix, as the page reads them.
     *
     * @return list<string>
     */
    protected function fixesFound(): array
    {
        $found = [];

        $this->actingAs($this->owner)
            ->get(route('projects.understanding.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('check', function (Assert $page) use (&$found) {
                $found = array_values(array_filter(array_column(array_column($page->toArray()['props']['check'], 'fix'), 'part')));
            }));

        return $found;
    }
}
