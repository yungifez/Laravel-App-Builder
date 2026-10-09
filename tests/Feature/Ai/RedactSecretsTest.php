<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\NotesDrafter;
use Closure;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Tests\TestCase;

class RedactSecretsTest extends TestCase
{
    public function test_a_key_in_the_instructions_or_the_prompt_reaches_the_provider_removed()
    {
        // Built at run time, so no key-shaped text sits in the repository.
        $key = 'sk-ant-'.str_repeat('k1', 20);
        $agent = new class($key) extends NotesDrafter
        {
            public function __construct(protected string $key) {}

            public function instructions(): string
            {
                return "The owner's notes say the key is {$this->key}.";
            }
        };
        $provider = new class(['notes' => []]) extends FakeTextGateway
        {
            /** @var list<array{instructions: string|null, prompt: string|null}> */
            public array $sent = [];

            public function generateTextStep(TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): StepResponse
            {
                $this->sent[] = ['instructions' => $instructions, 'prompt' => end($messages)->content];

                return parent::generateTextStep($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext);
            }
        };
        // The provider stands in for the real one, so what it gets is what
        // would leave.
        $ai = Ai::getFacadeRoot();
        Closure::bind(fn () => $this->fakeAgentGateways[$agent::class] = $provider, $ai, $ai::class)();

        $agent->prompt("Connect payments with {$key} please.");

        $this->assertSame([[
            'instructions' => "The owner's notes say the key is [secret removed].",
            'prompt' => 'Connect payments with [secret removed] please.',
        ]], $provider->sent);
    }
}
