<?php

namespace Tests\Feature\Evaluation;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditEvaluationTest extends TestCase
{
    public function test_a_transcript_that_saw_the_canary_or_touched_hidden_paths_fails_the_audit()
    {
        $dir = sys_get_temp_dir().'/builder-eval-audit-test-'.Str::lower(Str::random(8));
        File::ensureDirectoryExists($dir);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($dir));

        $clean = json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'Edit', 'input' => ['file_path' => '/tmp/work/app/X.php', 'old_string' => 'function () { return 1; }', 'new_string' => '{}']]]]])
            ."\n".json_encode(['type' => 'attachment', 'content' => 'AGENTS.md mentions fixtures/evaluation without touching it']);
        $leaky = json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'Read', 'input' => ['file_path' => '/repo/fixtures/evaluation/comparison/hidden/A.php']]]]]);
        $saw = json_encode(['type' => 'user', 'content' => 'contents: eval-canary-test']);

        File::put("{$dir}/clean.jsonl", $clean);
        File::put("{$dir}/leaky.jsonl", $leaky);
        File::put("{$dir}/saw.jsonl", $saw);

        $this->artisan('eval:audit', ['files' => ["{$dir}/clean.jsonl"], '--canary' => 'eval-canary-test'])->assertSuccessful();
        $this->artisan('eval:audit', ['files' => ["{$dir}/leaky.jsonl"], '--canary' => 'eval-canary-test'])->assertFailed();
        $this->artisan('eval:audit', ['files' => ["{$dir}/saw.jsonl"], '--canary' => 'eval-canary-test'])->assertFailed();
    }
}
