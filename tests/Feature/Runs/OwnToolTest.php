<?php

namespace Tests\Feature\Runs;

use App\Actions\Projects\ConnectOwnTool;
use App\Actions\Projects\CreateProject;
use App\Actions\Runs\StartRun;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Projects\ProjectRepository;
use App\Runs\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;
use ZipArchive;

class OwnToolTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    public function test_once_connected_the_owners_tool_writes_every_new_change()
    {
        Queue::fake();
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->post(route('projects.own-tool.store', $project))
            ->assertRedirect();
        $this->assertTrue(ConnectOwnTool::connected($project));

        $run = app(StartRun::class)->handle(FeatureRequest::factory()->for($project)->create());

        $this->assertSame('worker', $run->driver);
        $this->assertTrue($run->events()->where('type', 'handed_to_owner')->exists());
    }

    public function test_the_tool_gets_the_oldest_waiting_change_of_its_own_app_with_its_code()
    {
        $project = Project::factory()->create();
        $token = app(ConnectOwnTool::class)->handle($project);

        $this->getTask($token)->assertOk()->assertSee('No change waits for you now.');

        $this->waitingRun(Project::factory()->create(), 'Trainers can cancel a class.');
        $first = $this->waitingRun($project, 'Members can book a class.');
        $this->waitingRun($project, 'Members can cancel a booking.');

        $brief = (string) $this->getTask($token)->assertOk()->json('result.content.0.text');

        $this->assertStringContainsString('Members can book a class.', $brief);
        $this->assertStringNotContainsString('Members can cancel a booking.', $brief);
        $this->assertStringNotContainsString('Trainers can cancel a class.', $brief);
        $this->assertStringContainsString('/worker-code/'.$first->uuid.'?', $brief);
    }

    public function test_the_code_link_gives_the_code_the_change_starts_from_and_only_when_signed()
    {
        $owner = User::factory()->create();
        $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource(['README.md' => "Acme\n"]), draftNotes: false);
        app(ProjectRepository::class)->import($project);
        $run = $this->waitingRun($project);
        $token = app(ConnectOwnTool::class)->handle($project);

        preg_match('#http\S+/worker-code/\S+?(?=\s|\(|$)#', (string) $this->getTask($token)->json('result.content.0.text'), $link);
        $this->assertNotEmpty($link);

        $this->get($link[0])->assertOk()->assertDownload('acme.zip');
        $this->get(route('worker-code.show', $run))->assertForbidden();
    }

    public function test_the_code_of_a_follow_up_holds_the_earlier_change_not_kept_yet()
    {
        $owner = User::factory()->create();
        $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource(['README.md' => "Acme\n"]), draftNotes: false);
        $base = app(ProjectRepository::class)->import($project);
        $earlier = FeatureRequest::factory()->for($project)->create([
            'base_revision' => $base,
            'patch' => "diff --git a/booking.txt b/booking.txt\nnew file mode 100644\n--- /dev/null\n+++ b/booking.txt\n@@ -0,0 +1 @@\n+Book a class\n",
        ]);
        $run = Run::factory()->implementing()->for(FeatureRequest::factory()->for($project)->create(['parent_id' => $earlier->id, 'base_revision' => $base]))->create(['driver' => 'worker']);

        $response = $this->get(URL::temporarySignedRoute('worker-code.show', now()->addHour(), ['run' => $run]))->assertOk();
        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());

        $this->assertSame("Book a class\n", $zip->getFromName('acme/booking.txt'));
        $this->assertSame("Acme\n", $zip->getFromName('acme/README.md'));

        $this->assertSame($base, app(ProjectRepository::class)->head($project), 'The app itself stays as it was.');
    }

    public function test_after_disconnecting_the_tool_is_shut_out_and_new_changes_are_ours()
    {
        Queue::fake();
        $project = Project::factory()->create();
        $token = app(ConnectOwnTool::class)->handle($project);

        $this->actingAs($project->owner)->delete(route('projects.own-tool.destroy', $project))->assertRedirect();

        $this->getTask($token)->assertUnauthorized();
        $this->assertNotSame('worker', app(StartRun::class)->handle(FeatureRequest::factory()->for($project)->create())->driver);
    }

    public function test_only_people_who_can_change_the_app_connect_a_tool()
    {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->create())->post(route('projects.own-tool.store', $project))->assertForbidden();
        $this->assertFalse(ConnectOwnTool::connected($project));
    }

    /**
     * Call the get_task tool as the owner's MCP client would.
     */
    protected function getTask(string $token): TestResponse
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson(route('mcp.task'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_task', 'arguments' => []]]);
    }

    protected function waitingRun(Project $project, string $summary = 'Members can book a class.'): Run
    {
        return Run::factory()->implementing()->for(FeatureRequest::factory()->for($project))->create([
            'driver' => 'worker',
            'plan' => (new Plan(summary: $summary, acceptanceCriteria: ['It works.'], tasks: ['Do it.']))->toArray(),
        ]);
    }
}
