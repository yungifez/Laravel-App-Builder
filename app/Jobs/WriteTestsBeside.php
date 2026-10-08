<?php

namespace App\Jobs;

use App\Actions\Runs\WriteTestsFirst;
use App\Models\Run;
use App\Runs\Plan;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * A first version's tests are written while the coder builds it, so the
 * owner sees it sooner (§12). The run takes what this job wrote once its
 * coder is done, or writes the tests itself when the job never started.
 */
class WriteTestsBeside implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run: two asks of the writer.
     */
    public int $timeout = 900;

    /**
     * A failed write is not retried here; the run writes them itself.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     *
     * @param  int  $asked  The sequence of the run's "tests_beside_asked" event
     */
    public function __construct(public Run $run, public int $asked)
    {
        // On the checks' queue, which waits idle while a first version is built.
        $this->onQueue(config('builder.verification.queue'));
    }

    /**
     * Write the tests and record what came of it for the run to take. The
     * run or this job claims the write first; the other leaves it.
     */
    public function handle(WriteTestsFirst $writeTestsFirst): void
    {
        if (! Cache::add(self::claim($this->run, $this->asked), 'job', now()->addDay())) {
            return;
        }

        $data = $this->run->events()->where('sequence', $this->asked)->value('data');

        try {
            $plan = $writeTestsFirst->write($this->run, Plan::fromArray($data['plan']), ['prompt' => $data['prompt'], 'existing' => $data['existing']]);

            $this->run->recordEvent('tests_beside', ['asked' => $this->asked, 'outcome' => 'written', 'plan' => $plan->toArray()]);
        } catch (Throwable $exception) {
            // The run asks again itself, so it stops the way it does today.
            $this->run->recordEvent('tests_beside', ['asked' => $this->asked, 'outcome' => 'failed', 'error' => Str::limit($exception::class.': '.$exception->getMessage(), 2000)]);
        }
    }

    /**
     * Get the key the run and the job claim the write with.
     */
    public static function claim(Run $run, int $asked): string
    {
        return "tests-beside:{$run->id}:{$asked}";
    }
}
