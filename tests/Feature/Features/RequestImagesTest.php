<?php

namespace Tests\Feature\Features;

use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RequestImagesTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner, 'owner')->create();
    }

    public function test_the_owner_attaches_pictures_to_a_request_and_sees_them_in_the_chat()
    {
        $this->actingAs($this->owner)->post(route('feature-requests.store', $this->project), [
            'prompt' => 'Make the header look like this.',
            'images' => [UploadedFile::fake()->image('Header design.png', 640, 200), UploadedFile::fake()->image('menu.jpg')],
        ])->assertSessionHasNoErrors();

        $request = $this->project->featureRequests()->sole();
        $this->assertCount(2, $request->images);
        $this->assertSame('Header design.png', $request->images[0]['name']);
        $this->assertStringStartsWith("request-images/{$this->project->id}/", $request->images[0]['path']);
        Storage::disk('local')->assertExists([$request->images[0]['path'], $request->images[1]['path']]);

        $this->get(route('projects.show', ['project' => $this->project, 'change' => $request->uuid]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('change.featureRequest.images.0.name', 'Header design.png')
                ->where('change.featureRequest.images.0.url', route('feature-requests.images.show', [$request, 0])));

        $this->get(route('feature-requests.images.show', [$request, 1]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'");
        $this->get(route('feature-requests.images.show', [$request, 2]))->assertNotFound();
    }

    public function test_a_follow_up_can_carry_a_picture_too()
    {
        $parent = FeatureRequest::factory()->generated()->for($this->project)->create(['patch' => "diff --git a/a b/a\n"]);

        $this->actingAs($this->owner)->post(route('feature-requests.follow-ups.store', $parent), [
            'prompt' => 'Closer to this, please.',
            'images' => [UploadedFile::fake()->image('closer.webp')],
        ])->assertSessionHasNoErrors();

        $this->assertSame('closer.webp', $parent->followUps()->sole()->images[0]['name']);
    }

    public function test_only_a_few_real_pictures_are_taken()
    {
        $this->actingAs($this->owner);
        $ask = fn (array $images) => $this->post(route('feature-requests.store', $this->project), ['prompt' => 'Like this.', 'images' => $images]);

        // A picture format that can hold code is refused.
        $ask([UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml')])->assertSessionHasErrors('images.0');
        $ask([UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf')])->assertSessionHasErrors('images.0');
        $ask(array_map(fn (int $i) => UploadedFile::fake()->image("{$i}.png"), range(1, 5)))->assertSessionHasErrors('images');
        $ask([UploadedFile::fake()->image('huge.png')->size(6000)])->assertSessionHasErrors('images.0');

        $this->assertSame(0, $this->project->featureRequests()->count());
        Queue::assertNothingPushed();
    }

    public function test_other_people_cannot_see_the_pictures()
    {
        $request = FeatureRequest::factory()->for($this->project)->create(['images' => [['path' => 'request-images/1/a.png', 'name' => 'a.png']]]);
        Storage::disk('local')->put('request-images/1/a.png', 'png');

        $this->actingAs(User::factory()->create())
            ->get(route('feature-requests.images.show', [$request, 0]))
            ->assertForbidden();
    }
}
