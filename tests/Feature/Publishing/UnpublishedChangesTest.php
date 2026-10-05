<?php

namespace Tests\Feature\Publishing;

use App\Actions\Projects\CreateProject;
use App\Enums\DeploymentStatus;
use App\Jobs\PublishDeployment;
use App\Models\Deployment;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use App\Models\VisualEdit;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class UnpublishedChangesTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected ProjectRepository $repository;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWorkspaces();
        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource(), draftNotes: false);
        $this->repository->import($this->project);
    }

    public function test_the_owner_sees_which_requests_going_online_adds_and_takes_back()
    {
        $online = $this->commit('a.txt', 'Add a');
        $taken = $this->kept($online, 'Show the opening hours');
        Deployment::factory()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'commit_sha' => $online, 'status' => DeploymentStatus::Published]);

        $added = $this->kept($this->commit('b.txt', 'Add b'), 'Show prices next to each item');
        $taken->forceFill(['revert_sha' => $this->commit('a.txt', 'Undo a', null), 'reverted_at' => now()])->save();
        $both = $this->kept($this->commit('c.txt', 'Add c'), 'Try a darker page');
        $both->forceFill(['revert_sha' => $this->commit('c.txt', 'Undo c', null), 'reverted_at' => now()])->save();
        VisualEdit::factory()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'commit_sha' => $this->commit('d.txt', 'Edit the look')]);
        VisualEdit::factory()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'commit_sha' => $this->commit('e.txt', 'Edit the look again')]);
        VisualEdit::factory()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'commit_sha' => $online]);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('publishing.unpublished', [
                'added' => [['id' => $added->uuid, 'asked' => 'Show prices next to each item', 'data' => []]],
                'undone' => [['id' => $taken->uuid, 'asked' => 'Show the opening hours']],
                'edits' => 2,
            ]));

        // The apps list counts one kept, one undone, and the design changes
        // as one, however many clicks they took.
        $this->actingAs($this->owner)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('projects.0.offline', 3));
    }

    public function test_nothing_is_listed_before_the_first_publish_or_when_online_is_newest()
    {
        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('publishing.unpublished', null));
        $this->actingAs($this->owner)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('projects.0.offline', 0));

        Deployment::factory()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'commit_sha' => $this->repository->head($this->project), 'status' => DeploymentStatus::Published]);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('publishing.unpublished', null));
    }

    public function test_going_online_says_which_changes_touch_information_the_app_keeps()
    {
        Deployment::factory()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'commit_sha' => $this->repository->head($this->project), 'status' => DeploymentStatus::Published]);
        $migration = $this->migration(...);

        // Removing a column only when undone is not a risk going forward.
        $adds = $this->kept($this->commit('database/migrations/2026_01_01_000000_add_phone.php', 'Add phone', $migration(
            "Schema::table('people', fn (Blueprint \$table) => \$table->string('phone')->nullable());",
            "Schema::table('people', fn (Blueprint \$table) => \$table->dropColumn('phone'));",
        )), 'Ask for a phone number');
        // Where the migration lives does not matter; what it is does.
        $drops = $this->kept($this->commit('db/2026_01_02_000000_drop_nickname.php', 'Drop nickname', $migration(
            "Schema::table('people', fn (Blueprint \$table) => \$table->dropColumn('nickname')); DB::table('people')->update(['active' => true]);",
            '',
        )), 'Stop asking for a nickname');
        $renames = $this->kept($this->commit('database/migrations/2026_01_03_000000_rename.php', 'Rename', $migration(
            "Schema::table('people', fn (Blueprint \$table) => \$table->renameColumn('name', 'full_name'));",
            "Schema::table('people', fn (Blueprint \$table) => \$table->renameColumn('full_name', 'name'));",
        )), 'Call it full name');

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('publishing.unpublished.added', [
                ['id' => $adds->uuid, 'asked' => 'Ask for a phone number', 'data' => []],
                ['id' => $drops->uuid, 'asked' => 'Stop asking for a nickname', 'data' => ['deletes', 'rewrites']],
                ['id' => $renames->uuid, 'asked' => 'Call it full name', 'data' => ['renames']],
            ]));
    }

    public function test_a_version_that_deletes_information_online_goes_only_once_the_owner_says_yes()
    {
        Queue::fake([PublishDeployment::class]);
        $this->project->update(['deploy_remote' => 'git@github.com:acme/shop.git', 'deploy_branch' => 'main']);
        Deployment::factory()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'commit_sha' => $this->repository->head($this->project), 'status' => DeploymentStatus::Published]);
        $this->kept($this->commit('database/migrations/2026_01_02_000000_drop_nickname.php', 'Drop nickname', $this->migration("Schema::table('people', fn (Blueprint \$table) => \$table->dropColumn('nickname'));", '')), 'Stop asking for a nickname');
        $head = $this->repository->head($this->project);

        $this->actingAs($this->owner)
            ->post(route('deployments.store', $this->project), ['seen' => $head])
            ->assertSessionHasErrors(['lose_data' => 'This version deletes or changes information your app online keeps. Say you want that, then put it online.']);
        $this->assertSame(0, $this->project->deployments()->where('status', DeploymentStatus::Checking)->count());

        $this->actingAs($this->owner)
            ->post(route('deployments.store', $this->project), ['seen' => $head, 'lose_data' => 'on'])
            ->assertSessionHasNoErrors();

        $deployment = $this->project->deployments()->where('status', DeploymentStatus::Checking)->sole();
        $this->assertSame($head, $deployment->commit_sha);
        $this->assertNotNull($deployment->data_loss_confirmed_at);
        Queue::assertPushed(PublishDeployment::class, 1);
    }

    public function test_a_version_that_only_adds_information_goes_online_without_asking()
    {
        Queue::fake([PublishDeployment::class]);
        $this->project->update(['deploy_remote' => 'git@github.com:acme/shop.git', 'deploy_branch' => 'main']);
        Deployment::factory()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'commit_sha' => $this->repository->head($this->project), 'status' => DeploymentStatus::Published]);
        $this->kept($this->commit('database/migrations/2026_01_01_000000_add_phone.php', 'Add phone', $this->migration(
            "Schema::table('people', fn (Blueprint \$table) => \$table->string('phone')->nullable());",
            "Schema::table('people', fn (Blueprint \$table) => \$table->dropColumn('phone'));",
        )), 'Ask for a phone number');

        $this->actingAs($this->owner)
            ->post(route('deployments.store', $this->project), ['seen' => $this->repository->head($this->project)])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->project->deployments()->where('status', DeploymentStatus::Checking)->sole()->data_loss_confirmed_at);
    }

    public function test_a_yes_counts_only_for_the_version_the_owner_saw_and_without_one_nothing_goes_online()
    {
        Queue::fake([PublishDeployment::class]);
        $this->project->update(['deploy_remote' => 'git@github.com:acme/shop.git', 'deploy_branch' => 'main']);
        Deployment::factory()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id, 'commit_sha' => $this->repository->head($this->project), 'status' => DeploymentStatus::Published]);
        $this->kept($this->commit('database/migrations/2026_01_02_000000_drop_nickname.php', 'Drop nickname', $this->migration("Schema::dropIfExists('nicknames');", '')), 'Forget nicknames');
        $seen = $this->repository->head($this->project);
        // A newer change kept in another tab: the yes was for what the owner saw.
        $this->kept($this->commit('b.txt', 'Add b'), 'Show prices');

        $this->actingAs($this->owner)
            ->post(route('deployments.store', $this->project), ['seen' => $seen, 'lose_data' => 'on'])
            ->assertSessionHasErrors('publish');
        // A yes without the version it was for is no yes.
        $this->actingAs($this->owner)
            ->post(route('deployments.store', $this->project), ['lose_data' => 'on'])
            ->assertSessionHasErrors('lose_data');

        $this->assertSame(0, $this->project->deployments()->where('status', DeploymentStatus::Checking)->count());
        Queue::assertNothingPushed();
    }

    protected function migration(string $up, string $down): string
    {
        return "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        {$up}\n    }\n\n    public function down(): void\n    {\n        {$down}\n    }\n};\n";
    }

    protected function commit(string $file, string $message, ?string $contents = "x\n"): string
    {
        return $this->repository->commitFiles($this->project, $this->repository->head($this->project), [$file => $contents], $message, null);
    }

    protected function kept(string $commit, string $prompt): FeatureRequest
    {
        return FeatureRequest::factory()->create(['project_id' => $this->project->id, 'prompt' => $prompt, 'commit_sha' => $commit, 'accepted_at' => now()]);
    }
}
