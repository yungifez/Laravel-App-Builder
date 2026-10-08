<?php

namespace App\Evaluation;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * Hands a model call to an outside responder through files, and waits for
 * the answer.
 *
 * Each call writes `{id}.request.json` to the directory. The responder
 * writes `{id}.response.json` next to it. Requests are written atomically, so
 * a responder never reads half a request.
 */
class Handoff
{
    public function __construct(
        protected string $directory,
        protected int $timeoutSeconds = 3600,
        protected int $pollMilliseconds = 2000,
    ) {}

    /**
     * Create the hand-off from configuration, or null when it is not enabled.
     */
    public static function fromConfig(): ?self
    {
        $path = config('evaluation.handoff.path');

        if (! is_string($path) || $path === '') {
            return null;
        }

        return new self($path, (int) config('evaluation.handoff.timeout_seconds'));
    }

    /**
     * Write the request and wait for its response.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     *
     * @throws RuntimeException when no valid response arrives in time.
     */
    public function ask(string $role, array $request): array
    {
        File::ensureDirectoryExists($this->directory);

        $id = now()->format('Ymd-His').'-'.$role.'-'.Str::lower(Str::random(6));
        $requestPath = $this->path("{$id}.request.json");
        $responsePath = $this->path("{$id}.response.json");

        File::put("{$requestPath}.tmp", (string) json_encode(['id' => $id, 'role' => $role, 'response' => $responsePath, ...$request], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        File::move("{$requestPath}.tmp", $requestPath);

        $deadline = now()->addSeconds($this->timeoutSeconds);

        while (now()->isBefore($deadline)) {
            if (File::exists($responsePath)) {
                return $this->read($responsePath);
            }

            Sleep::for($this->pollMilliseconds)->milliseconds();
        }

        throw new RuntimeException("No response to hand-off [{$id}] within {$this->timeoutSeconds} seconds.");
    }

    /**
     * Read a response, waiting briefly if the responder is still writing it.
     *
     * @return array<string, mixed>
     */
    protected function read(string $path): array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $decoded = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

                if (is_array($decoded)) {
                    /** @var array<string, mixed> $decoded */
                    return $decoded;
                }
            } catch (JsonException) {
                //
            }

            Sleep::for(500)->milliseconds();
        }

        throw new RuntimeException("The hand-off response [{$path}] is not a JSON object.");
    }

    protected function path(string $name): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
    }
}
