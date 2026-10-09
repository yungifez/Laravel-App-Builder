<?php

namespace Tests\Feature\Projects;

use App\Actions\Projects\CreateProject;
use App\Context\ProjectNotes;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

/*
| A phone app is an app of its own that talks to the owner's app, so each
| change to it is planned, built and proved like any other change.
*/
class PhoneAppTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config([
            'builder.projects.mobile_template' => $this->makeProjectSource([
                ...$this->laravelApp(),
                // Like the starter's, with a setting there twice.
                '.env.example' => "APP_NAME=Laravel\nNATIVEPHP_APP_ID=\nDB_CONNECTION=sqlite\nBACKEND_URL=\nNATIVEPHP_APP_ID=\n",
                'routes/mobile.php' => "<?php\n",
            ]),
            'builder.projects.mobile_app_id_prefix' => 'com.example',
        ]);
        $this->owner = User::factory()->create(['name' => 'Ada Owner']);
        $this->project = app(CreateProject::class)->handle($this->owner, 'Bright Cleaning', $this->makeProjectSource($this->laravelApp()));
        $this->project->forceFill(['live_url' => 'https://bright.example.com'])->save();
    }

    public function test_an_owner_adds_a_phone_app_that_talks_to_their_app()
    {
        $response = $this->actingAs($this->owner)->post(route('projects.phone-app.store', $this->project));

        $phone = $this->project->phoneApp()->sole();
        $response->assertRedirect(route('projects.show', $phone));
        $this->assertSame(['Bright Cleaning phone app', $this->owner->id, true], [$phone->name, $phone->user_id, $phone->started_here]);
        $this->assertTrue($phone->parent->is($this->project));

        $repository = app(ProjectRepository::class);
        $settings = (string) $repository->show($phone, $repository->head($phone), '.env.example');
        $this->assertStringContainsString("APP_NAME=\"Bright Cleaning phone app\"\n", $settings);
        $this->assertStringContainsString("BACKEND_URL=https://bright.example.com\n", $settings);
        $this->assertSame(2, substr_count($settings, "NATIVEPHP_APP_ID=com.example.brightcleaning\n"));
        $this->assertSame(['Point the phone app at Bright Cleaning', 'Import Bright Cleaning phone app'], array_column($repository->log($phone), 'subject'));
        $this->assertStringContainsString('The phone app for Bright Cleaning.', app(ProjectNotes::class)->files($phone)['project.md']);

        // Each app's menu leads to the other.
        $this->actingAs($this->owner)->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('project.phone', ['app' => ['id' => $phone->uuid, 'name' => $phone->name], 'parent' => null, 'available' => true]));
        $this->actingAs($this->owner)->get(route('projects.show', $phone))
            ->assertInertia(fn (Assert $page) => $page->where('project.phone.parent', ['id' => $this->project->uuid, 'name' => 'Bright Cleaning'])->where('project.phone.app', null));
    }

    public function test_an_app_not_yet_online_gets_a_phone_app_set_up_later_and_a_taken_name_is_numbered()
    {
        $this->project->forceFill(['live_url' => null, 'name' => '7 Day Gym'])->save();
        $this->owner->projects()->create(['name' => '7 Day Gym phone app', 'source_path' => '/elsewhere']);

        $this->actingAs($this->owner)->post(route('projects.phone-app.store', $this->project))->assertSessionHasNoErrors();

        $phone = $this->project->phoneApp()->sole();
        $this->assertSame('7 Day Gym phone app 2', $phone->name);
        $repository = app(ProjectRepository::class);
        $settings = (string) $repository->show($phone, $repository->head($phone), '.env.example');
        $this->assertStringContainsString("BACKEND_URL=\n", $settings);
        // A store id part must start with a letter.
        $this->assertStringContainsString("NATIVEPHP_APP_ID=com.example.app7daygym{$phone->id}\n", $settings);
    }

    public function test_one_phone_app_per_app_none_for_a_phone_app_and_only_for_its_owner()
    {
        $this->actingAs($this->owner)->post(route('projects.phone-app.store', $this->project));
        $phone = $this->project->phoneApp()->sole();

        $this->actingAs($this->owner)->post(route('projects.phone-app.store', $this->project))
            ->assertSessionHasErrors(['phone_app' => 'This app already has a phone app.']);
        $this->actingAs($this->owner)->post(route('projects.phone-app.store', $phone))
            ->assertSessionHasErrors(['phone_app' => 'This is already a phone app.']);
        $this->actingAs(User::factory()->create())->post(route('projects.phone-app.store', $this->project))->assertForbidden();

        $this->assertSame(2, Project::count());
    }

    public function test_without_a_phone_app_template_nothing_is_made_and_it_says_whose_fault()
    {
        Exceptions::fake();
        config(['builder.projects.mobile_template' => sys_get_temp_dir().'/no-such-mobile-template']);

        $this->actingAs($this->owner)->post(route('projects.phone-app.store', $this->project))
            ->assertSessionHasErrors(['phone_app' => 'This is our fault: phone apps are switched off here right now. Nothing was saved. Please try again later.']);

        Exceptions::assertReported(RuntimeException::class);
        $this->assertSame(1, Project::count());
        $this->actingAs($this->owner)->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('project.phone.available', false));
    }
}
