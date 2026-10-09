<?php

namespace Tests\Feature\Settings;

use App\Jobs\ForgetProjectFiles;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\User;
use App\Models\Verification;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Cashier\Subscription;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

/**
 * The privacy page promises that deleting an account deletes the person's
 * apps and their history. The rows go with the account; these tests hold
 * the files kept outside the database to the same promise.
 */
class DeleteAccountTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    public function test_deleting_an_account_removes_each_apps_files_too(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = Project::factory()->for($user, 'owner')->create();
        $other = Project::factory()->create();
        $featureRequest = FeatureRequest::factory()->for($project)->create();
        Verification::factory()->for($featureRequest)->create([
            'screens' => ['pages' => [], 'shots' => [['screen' => 'Home', 'width' => 390, 'path' => 'screen-shots/7/1-390.jpg']]],
        ]);

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect(route('home'));

        $this->assertNull($project->fresh());
        $this->assertNotNull($other->fresh());
        Queue::assertPushed(ForgetProjectFiles::class, 1);
        Queue::assertPushed(ForgetProjectFiles::class, fn (ForgetProjectFiles $job) => $job->projectId === $project->id && $job->shots === ['screen-shots/7/1-390.jpg']);
    }

    public function test_the_job_removes_the_code_the_pictures_and_the_screenshots(): void
    {
        Storage::fake('images');
        Storage::fake('shots');
        config(['builder.construction.images.disk' => 'images', 'builder.verification.screens.shots_disk' => 'shots']);
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $repository = app(ProjectRepository::class);
        $repository->import($project);
        Storage::disk('images')->put("request-images/{$project->id}/a.png", 'png');
        Storage::disk('images')->put('request-images/999999/b.png', 'png');
        Storage::disk('shots')->put('screen-shots/7/1-390.jpg', 'jpg');
        Storage::disk('shots')->put('screen-shots/8/1-390.jpg', 'jpg');
        $project->delete();

        (new ForgetProjectFiles($project->id, ['screen-shots/7/1-390.jpg']))->handle($repository);

        $this->assertDirectoryDoesNotExist($repository->path($project));
        Storage::disk('images')->assertMissing("request-images/{$project->id}/a.png");
        Storage::disk('shots')->assertMissing('screen-shots/7/1-390.jpg');
        // Nobody else's files go with it.
        Storage::disk('images')->assertExists('request-images/999999/b.png');
        Storage::disk('shots')->assertExists('screen-shots/8/1-390.jpg');
    }

    public function test_deleting_an_account_stops_its_paid_plan_at_once(): void
    {
        Queue::fake();
        $calls = $this->fakeStripe(200);
        $user = User::factory()->create();
        $subscription = $this->subscribe($user);

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect(route('home'));

        $this->assertNull($user->fresh());
        $this->assertSame([['delete', "/v1/subscriptions/{$subscription->stripe_id}"]], $calls->getArrayCopy());
    }

    public function test_an_account_whose_plan_cannot_be_stopped_is_kept(): void
    {
        Queue::fake();
        $this->fakeStripe(500);
        $user = User::factory()->create();
        $project = Project::factory()->for($user, 'owner')->create();
        $this->subscribe($user);

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors(['account' => 'This is our fault: we could not stop your paid plan, so your account was not deleted. Please try again in a few minutes.']);

        $this->assertNotNull($user->fresh());
        $this->assertNotNull($project->fresh());
        $this->assertAuthenticatedAs($user);
        Queue::assertNothingPushed();
    }

    public function test_the_profile_page_lists_each_app_to_download_before_deleting(): void
    {
        $user = User::factory()->create();
        Project::factory()->for($user, 'owner')->create(['name' => 'Bakery', 'live_url' => 'https://bakery.example.com']);
        Project::factory()->for($user, 'owner')->create(['name' => 'Classes']);
        Project::factory()->create(['name' => 'Someone else']);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('apps', 2)
                ->where('apps.0.name', 'Bakery')
                ->where('apps.0.live', true)
                ->where('apps.1.name', 'Classes')
                ->where('apps.1.live', false));
    }

    protected function subscribe(User $user): Subscription
    {
        return $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.Str::random(10),
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro',
            'quantity' => 1,
        ]);
    }

    /**
     * Answer every Stripe call with the status, and record each call's
     * method and path.
     *
     * @return \ArrayObject<int, array{0: string, 1: string}>
     */
    protected function fakeStripe(int $status): \ArrayObject
    {
        $calls = new \ArrayObject;
        config(['cashier.secret' => 'sk_test_fake']);

        ApiRequestor::setHttpClient(new class($status, $calls) implements ClientInterface
        {
            /** @param \ArrayObject<int, array{0: string, 1: string}> $calls */
            public function __construct(private int $status, private \ArrayObject $calls) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->calls->append([$method, (string) parse_url($absUrl, PHP_URL_PATH)]);

                return $this->status === 200
                    ? [(string) json_encode(['id' => 'sub_x', 'object' => 'subscription', 'status' => 'canceled']), 200, []]
                    : [(string) json_encode(['error' => ['message' => 'Stripe is down', 'type' => 'api_error']]), $this->status, []];
            }
        });
        $this->beforeApplicationDestroyed(fn () => ApiRequestor::setHttpClient(null));

        return $calls;
    }
}
