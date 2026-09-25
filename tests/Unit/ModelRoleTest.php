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
            'builder.models.coder' => ['provider' => 'openai', 'model' => 'economical-model'],
            'builder.models.reviewer' => ['provider' => 'gemini', 'model' => 'review-model'],
        ]);

        $this->assertSame(['anthropic', 'frontier-model'], [ModelRole::Planner->provider(), ModelRole::Planner->model()]);
        $this->assertSame(['openai', 'economical-model'], [ModelRole::Coder->provider(), ModelRole::Coder->model()]);
        $this->assertSame(['gemini', 'review-model'], [ModelRole::Reviewer->provider(), ModelRole::Reviewer->model()]);
    }

    public function test_empty_settings_fall_back_to_the_ai_sdk_defaults()
    {
        config([
            'ai.default' => 'openai',
            'builder.models.coder' => ['provider' => null, 'model' => ''],
        ]);

        $this->assertSame('openai', ModelRole::Coder->provider());
        $this->assertNull(ModelRole::Coder->model());
    }
}
