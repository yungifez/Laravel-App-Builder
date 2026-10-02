<?php

namespace Tests\Fixtures;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Stands in for a command of an owner's app while the recorder is tested:
 * work the schedule runs with no one there. It marks each person as
 * reminded, then sends the reminder.
 */
class RecordedCommand extends Command
{
    public const PATH = 'tests/Fixtures/RecordedCommand.php';

    protected $signature = 'recorded:remind {--careful} {--hushed} {--recorded} {--broken} {--inner}';

    public function handle(): int
    {
        if ($this->option('broken')) {
            throw new RuntimeException('The reminders cannot be sent.');
        }

        if ($this->option('inner')) {
            Artisan::call('recorded:remind');
        }

        DB::table('users')->where('id', 0)->update(['name' => 'Reminded']);

        try {
            Mail::raw('Reminder', fn ($message) => $message->to('owner@example.com'));
        } catch (Throwable $exception) {
            if ($this->option('recorded')) {
                report($exception);
            }

            if ($this->option('careful')) {
                $this->error('The reminder was not sent.');

                return self::FAILURE;
            }

            if (! $this->option('hushed') && ! $this->option('recorded')) {
                throw $exception;
            }
        }

        User::query()->count();

        return self::SUCCESS;
    }
}
