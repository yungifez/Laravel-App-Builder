<?php

namespace Tests\Unit;

use App\Enums\ModelRole;
use Tests\TestCase;

class ModelRoleTest extends TestCase
{
    public function test_each_role_uses_its_own_configured_provider_and_model()
    {
        config([
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'frontier-model'],
            'builder.models.reviewer' => ['provider' => 'gemini', 'model' => 'review-model'],
        ]);

        $this->assertSame(['anthropic', 'frontier-model'], [ModelRole::Planner->provider(), ModelRole::Planner->model()]);
        $this->assertSame(['gemini', 'review-model'], [ModelRole::Reviewer->provider(), ModelRole::Reviewer->model()]);
    }

    public function test_empty_settings_fall_back_to_the_ai_sdk_defaults()
    {
        config([
            'ai.default' => 'openai',
            'builder.models.reviewer' => ['provider' => null, 'model' => ''],
        ]);

        $this->assertSame('openai', ModelRole::Reviewer->provider());
        $this->assertNull(ModelRole::Reviewer->model());
    }

    public function test_a_role_fails_over_to_the_other_providers_that_have_a_key()
    {
        config([
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'frontier-model'],
            'builder.models.failover' => ['openai', 'anthropic', 'gemini'],
            'ai.providers.openai.key' => 'openai-test-key',
            'ai.providers.anthropic.key' => 'anthropic-test-key',
            'ai.providers.gemini.key' => null,
        ]);

        $this->assertSame(['anthropic' => 'frontier-model', 'openai' => null], ModelRole::Planner->providers());
    }
}
