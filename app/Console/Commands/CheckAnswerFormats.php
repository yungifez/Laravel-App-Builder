<?php

namespace App\Console\Commands;

use App\Ai\Attributes\Tier;
use App\Ai\StructuredAgents;
use App\Models\AnswerFormatCheck;
use App\Runs\Exceptions\ProvidersUnavailable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use ReflectionClass;
use Throwable;

#[Signature('ai:check-formats')]
#[Description('Try each agent\'s answer format once on its real model, and say which ones the AI service refuses')]
class CheckAnswerFormats extends Command
{
    /**
     * The smallest task that still makes the model answer in the format.
     * No project data goes with it.
     */
    public const PROMPT = 'This is a format check with no real task. Return the smallest valid answer: empty lists and short placeholder texts.';

    /**
     * Fakes in tests cannot show that the AI service refuses an answer
     * format (such as a schema whose compiled grammar is too large), and
     * when it does, every call to that agent fails. One tiny real call per
     * agent shows it. Each result is kept, and a failed one is an attention
     * item until a later check passes. Run it after each deploy; it also
     * runs daily when builder.answer_formats.enabled is on.
     */
    public function handle(): int
    {
        $failed = 0;

        foreach (StructuredAgents::in(array_values(array_map(strval(...), (array) config('builder.answer_formats.directories')))) as $class) {
            $result = $this->check($class);
            AnswerFormatCheck::query()->updateOrCreate(['agent' => $class], [...$result, 'checked_at' => now()]);

            $name = class_basename($class);

            if ($result['accepted']) {
                $this->components->twoColumnDetail($name, '<fg=green>OK</>');
            } else {
                $failed++;
                $this->components->twoColumnDetail($name, "<fg=red>{$result['reason']}</> ".Str::limit((string) $result['message'], 160));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  class-string  $class
     * @return array{role: string|null, accepted: bool, reason: string|null, service_error: array<string, mixed>|null, message: string|null}
     */
    protected function check(string $class): array
    {
        $tier = (new ReflectionClass($class))->getAttributes(Tier::class)[0] ?? null;

        if ($tier === null) {
            return ['role' => null, 'accepted' => false, 'reason' => 'no_tier', 'service_error' => null, 'message' => 'The agent names no model tier. Add #[Tier(ModelRole::...)] to it.'];
        }

        $role = $tier->newInstance()->role;
        $failed = fn (string $reason, ?string $message, ?array $serviceError = null) => ['role' => $role->value, 'accepted' => false, 'reason' => $reason, 'service_error' => $serviceError, 'message' => $message];

        try {
            /** @var Agent $agent */
            $agent = app($class);
            $response = $agent->prompt(self::PROMPT, provider: $role->providers());
        } catch (RequestException $exception) {
            $stop = ProvidersUnavailable::fromResponse($exception);

            return $failed($stop->reason()->value, $stop->getMessage(), $stop->serviceError());
        } catch (FailoverableException $exception) {
            $stop = ProvidersUnavailable::afterFailover($exception);

            return $failed($stop->reason()->value, $stop->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $failed('ours', Str::limit($exception->getMessage(), 500));
        }

        if (! $response instanceof StructuredAgentResponse) {
            return $failed('ours', 'The answer came back without its format.');
        }

        return ['role' => $role->value, 'accepted' => true, 'reason' => null, 'service_error' => null, 'message' => null];
    }
}
