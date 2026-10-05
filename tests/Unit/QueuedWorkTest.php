<?php

use App\Features\QueuedWork;
use Tests\TestCase;

uses(TestCase::class);

const SEND_REMINDER = <<<'PHP'
<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendReminder implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}
PHP;

const GUARDED_WORK = <<<'PHP'
<?php

namespace App\Mail;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\Attributes\Backoff;
use Throwable;

#[Backoff([10, 60])]
class InvoicePaid extends Mailable implements ShouldQueueAfterCommit
{
    public function retryUntil(): DateTime
    {
        return now()->addHour();
    }

    public function failed(Throwable $exception): void {}
}

class Receipt extends Mailable
{
}
PHP;

const QUEUED_LISTENER = <<<'PHP'
<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyTeam implements ShouldQueue
{
    public $tries = 3;

    public function handle(): void {}
}
PHP;

it('finds queued work and what it leaves out', function () {
    expect(QueuedWork::read('app/Jobs/SendReminder.php', SEND_REMINDER))->toBe([
        ['class' => 'App\Jobs\SendReminder', 'kind' => 'job', 'at' => 'app/Jobs/SendReminder.php:8', 'missing' => ['tries', 'backoff', 'failed']],
    ])->and(QueuedWork::read('app/Listeners/NotifyTeam.php', QUEUED_LISTENER)[0])->toMatchArray(['kind' => 'listener', 'missing' => ['backoff', 'failed']]);
});

it('counts an attribute, retryUntil() and failed() as said, and leaves out work that is not queued', function () {
    expect(QueuedWork::read('app/Mail/InvoicePaid.php', GUARDED_WORK))->toBe([
        ['class' => 'App\Mail\InvoicePaid', 'kind' => 'mail', 'at' => 'app/Mail/InvoicePaid.php:10', 'missing' => []],
    ])->and(QueuedWork::read('app/Jobs/Broken.php', '<?php class {'))->toBe([]);
});

it('finds only the work a patch queues, not what the app already queued', function () {
    $patch = implode("\n", [
        'diff --git a/app/Jobs/SendReminder.php b/app/Jobs/SendReminder.php',
        'new file mode 100644',
        '--- /dev/null',
        '+++ b/app/Jobs/SendReminder.php',
        '@@ -0,0 +1 @@',
        '+<?php',
        'diff --git a/app/Listeners/NotifyTeam.php b/app/Listeners/NotifyTeam.php',
        '--- a/app/Listeners/NotifyTeam.php',
        '+++ b/app/Listeners/NotifyTeam.php',
        '@@ -11,2 +11,4 @@',
        '     public function handle(): void {}',
        '+',
        '+    // Sends to the whole team.',
        ' }',
        'diff --git a/tests/Feature/ReminderTest.php b/tests/Feature/ReminderTest.php',
        'new file mode 100644',
        '--- /dev/null',
        '+++ b/tests/Feature/ReminderTest.php',
        '@@ -0,0 +1 @@',
        '+<?php',
        '',
    ]);
    $files = [
        'app/Jobs/SendReminder.php' => SEND_REMINDER,
        'app/Listeners/NotifyTeam.php' => str_replace("handle(): void {}\n", "handle(): void {}\n\n    // Sends to the whole team.\n", QUEUED_LISTENER),
        'tests/Feature/ReminderTest.php' => SEND_REMINDER,
    ];

    expect(array_column(QueuedWork::inPatch($patch, fn (string $path) => $files[$path] ?? null), 'class'))->toBe(['App\Jobs\SendReminder']);

    // A listener the change makes queued is the change's.
    $queuedNow = implode("\n", [
        'diff --git a/app/Listeners/NotifyTeam.php b/app/Listeners/NotifyTeam.php',
        '--- a/app/Listeners/NotifyTeam.php',
        '+++ b/app/Listeners/NotifyTeam.php',
        '@@ -7,1 +7,1 @@',
        '-class NotifyTeam',
        '+class NotifyTeam implements ShouldQueue',
        '',
    ]);
    expect(array_column(QueuedWork::inPatch($queuedNow, fn () => QUEUED_LISTENER), 'class'))->toBe(['App\Listeners\NotifyTeam']);
});

it('finds each queued class that leaves a part out, less what the owner accepted, and tells the coder what to add', function () {
    $queued = [
        ...QueuedWork::read('app/Jobs/SendReminder.php', SEND_REMINDER),
        ...QueuedWork::read('app/Mail/InvoicePaid.php', GUARDED_WORK),
    ];
    $findings = QueuedWork::findings($queued);

    expect($findings)->toBe([['kind' => QueuedWork::UNGUARDED, 'subject' => 'App\Jobs\SendReminder']])
        ->and(QueuedWork::findings($queued, [QueuedWork::identity($findings[0])]))->toBe([])
        ->and(QueuedWork::findings(null))->toBe([])
        ->and(QueuedWork::finding($findings[0], $queued))->toBe('App\Jobs\SendReminder (app/Jobs/SendReminder.php:8) is queued but does not say how many times to try (a $tries property) or how long to wait between tries (a $backoff property) or what to do when it gives up (a failed() method). Add them, as Laravel\'s queue documentation shows. If it must run only once, ask the owner to keep it.')
        ->and(QueuedWork::name('App\Jobs\SendInvoiceReminder'))->toBe('send invoice reminder');
});
