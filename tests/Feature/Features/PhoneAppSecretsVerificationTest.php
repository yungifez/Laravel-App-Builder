<?php

namespace Tests\Feature\Features;

use App\Actions\Features\RequestVerification;
use App\Actions\Runs\CompleteRunVerification;
use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\UsesAcceptanceSuite;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

/*
| A phone app carries its settings inside the app, so a secret there is
| given away to everyone who downloads it. Our check, not the app's own
| tests, sends such a change back.
*/
class PhoneAppSecretsVerificationTest extends TestCase
{
    use FakesWorkspaces, RefreshDatabase, UsesAcceptanceSuite;

    protected FakeWorkspaceDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();
        $this->useAcceptanceSuite();

        config([
            'builder.verification.workspace_driver' => 'fake',
            'builder.verification.setup' => [['name' => 'Install', 'command' => ['composer', 'install'], 'timeout' => 600]],
            'builder.verification.checks' => [['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 300]],
            'builder.verification.security.enabled' => false,
            'builder.verification.roles.enabled' => false,
        ]);
    }

    /**
     * Verify a change to the given app whose example settings are given.
     */
    protected function verify(Project $project, string $settings): Verification
    {
        // The first workspace the fake driver makes.
        $this->driver->files['fake-1:.env.example'] = $settings;
        $request = FeatureRequest::factory()->generated()->for($project)->create(['acceptance' => ['Invitations/ContractTest.php']]);
        app(RequestVerification::class)->handle($request);

        return $request->verifications()->sole();
    }

    /**
     * @return list<array{name: string, outcome: string, output: string}>
     */
    protected function secretsResults(Verification $verification): array
    {
        return array_values(array_filter($verification->results, fn (array $result) => $result['name'] === 'Phone app keeps no secrets'));
    }

    public function test_a_phone_app_whose_settings_hold_a_token_is_sent_back_with_what_to_empty()
    {
        $phone = Project::factory()->create(['parent_id' => Project::factory()->create()->id]);

        $verification = $this->verify($phone, "APP_NAME=Phone\nBACKEND_URL=https://bright.test\nBACKEND_TOKEN=abc123\n");

        $this->assertSame(VerificationStatus::Failed, $verification->status);
        [$result] = $this->secretsResults($verification);
        $this->assertSame('failed', $result['outcome']);
        $this->assertStringContainsString('Leave these empty: BACKEND_TOKEN.', $result['output']);
        $this->assertStringNotContainsString('abc123', $result['output']);
        $this->assertStringContainsString('Leave these empty: BACKEND_TOKEN.', implode("\n", app(CompleteRunVerification::class)->failures($verification)));
    }

    public function test_a_phone_app_with_its_secrets_left_empty_passes()
    {
        $phone = Project::factory()->create(['parent_id' => Project::factory()->create()->id]);

        $verification = $this->verify($phone, "APP_NAME=Phone\nAPP_KEY=\nBACKEND_URL=https://bright.test\nREDIS_PASSWORD=null\n");

        $this->assertSame(VerificationStatus::Passed, $verification->status);
        $this->assertSame(['passed'], array_column($this->secretsResults($verification), 'outcome'));
    }

    public function test_an_app_that_is_not_a_phone_app_keeps_its_settings_on_its_server_and_is_not_checked()
    {
        $verification = $this->verify(Project::factory()->create(), "APP_NAME=Shop\nSTRIPE_SECRET=sk_test_1\n");

        $this->assertSame(VerificationStatus::Passed, $verification->status);
        $this->assertSame([], $this->secretsResults($verification));
    }
}
