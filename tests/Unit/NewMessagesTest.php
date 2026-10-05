<?php

use App\Features\NewMessages;
use Tests\TestCase;

uses(TestCase::class);

const INVOICE_PAID = <<<'PHP'
<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class InvoicePaid extends Mailable
{
}

abstract class BaseMail extends Mailable
{
}
PHP;

const LOGIN_CODE = <<<'PHP'
<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Twilio\TwilioChannel;

class LoginCodeNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail', TwilioChannel::class];
    }
}
PHP;

const TEAM_JOINED = <<<'PHP'
<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class TeamJoined extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }
}
PHP;

/**
 * A patch that changes one file from what it was to what it is.
 */
function messagePatch(string $path, string $before, string $after): string
{
    $old = explode("\n", $before);
    $new = explode("\n", $after);

    return implode("\n", [
        "diff --git a/{$path} b/{$path}",
        "--- a/{$path}",
        "+++ b/{$path}",
        '@@ -1,'.count($old).' +1,'.count($new).' @@',
        ...array_map(fn (string $line) => "-{$line}", $old),
        ...array_map(fn (string $line) => "+{$line}", $new),
    ])."\n";
}

it('finds each mailable, and each notification by the channels via() names', function () {
    expect(NewMessages::read('app/Mail/InvoicePaid.php', INVOICE_PAID))->toBe([
        ['class' => 'App\Mail\InvoicePaid', 'channels' => ['mail'], 'at' => 'app/Mail/InvoicePaid.php:7'],
    ])->and(NewMessages::read('app/Notifications/LoginCodeNotification.php', LOGIN_CODE))->toBe([
        ['class' => 'App\Notifications\LoginCodeNotification', 'channels' => ['mail', 'sms'], 'at' => 'app/Notifications/LoginCodeNotification.php:8'],
    ]);
});

it('reads the message methods when via() is not plain, and leaves out notifications that only save', function () {
    $chosen = str_replace("return ['database'];", 'return $notifiable->prefers();', TEAM_JOINED);
    $mailed = str_replace('public function toArray(', "public function toMail(object \$notifiable): MailMessage\n    {\n        return new MailMessage;\n    }\n\n    public function toVonage(", $chosen);

    expect(NewMessages::read('app/Notifications/TeamJoined.php', TEAM_JOINED))->toBe([])
        ->and(NewMessages::read('app/Notifications/TeamJoined.php', $chosen))->toBe([])
        ->and(NewMessages::read('app/Notifications/TeamJoined.php', $mailed)[0]['channels'])->toBe(['mail', 'sms'])
        ->and(NewMessages::read('app/Mail/Broken.php', '<?php class {'))->toBe([]);
});

it('finds what a patch newly sends: a new class, or an existing notification that now sends by mail', function () {
    $mails = str_replace("return ['database'];", "return ['database', 'mail'];", TEAM_JOINED);
    $added = "diff --git a/app/Mail/InvoicePaid.php b/app/Mail/InvoicePaid.php\nnew file mode 100644\n--- /dev/null\n+++ b/app/Mail/InvoicePaid.php\n@@ -0,0 +1 @@\n+<?php\n";
    $test = "diff --git a/tests/Feature/MailTest.php b/tests/Feature/MailTest.php\nnew file mode 100644\n--- /dev/null\n+++ b/tests/Feature/MailTest.php\n@@ -0,0 +1 @@\n+<?php\n";
    $files = ['app/Mail/InvoicePaid.php' => INVOICE_PAID, 'app/Notifications/TeamJoined.php' => $mails, 'tests/Feature/MailTest.php' => INVOICE_PAID];

    expect(array_column(NewMessages::inPatch($added.$test.messagePatch('app/Notifications/TeamJoined.php', TEAM_JOINED, $mails), fn (string $path) => $files[$path] ?? null), 'class'))
        ->toBe(['App\Mail\InvoicePaid', 'App\Notifications\TeamJoined']);

    // A mailable the app already sends, changed in its wording, is not new.
    $reworded = str_replace('class InvoicePaid', "// Sent once paid.\nclass InvoicePaid", INVOICE_PAID);
    expect(NewMessages::inPatch(messagePatch('app/Mail/InvoicePaid.php', INVOICE_PAID, $reworded), fn () => $reworded))->toBe([])
        // Hunks out of order: the earlier text cannot be rebuilt.
        ->and(NewMessages::inPatch("diff --git a/app/Mail/InvoicePaid.php b/app/Mail/InvoicePaid.php\n--- a/app/Mail/InvoicePaid.php\n+++ b/app/Mail/InvoicePaid.php\n@@ -5,1 +5,1 @@\n-a\n+b\n@@ -1,1 +1,1 @@\n-c\n+d\n", fn () => INVOICE_PAID))->toBe([]);
});

it('finds each new message the owner has not approved, and names it for them', function () {
    $messages = [
        ['class' => 'App\Mail\InvoicePaid', 'channels' => ['mail'], 'at' => 'app/Mail/InvoicePaid.php:7'],
        ['class' => 'App\Notifications\LoginCodeNotification', 'channels' => ['sms'], 'at' => 'app/Notifications/LoginCodeNotification.php:8'],
    ];
    $findings = NewMessages::findings($messages);

    expect($findings)->toBe([
        ['kind' => NewMessages::UNAPPROVED, 'subject' => 'App\Mail\InvoicePaid'],
        ['kind' => NewMessages::UNAPPROVED, 'subject' => 'App\Notifications\LoginCodeNotification'],
    ])->and(NewMessages::findings($messages, [NewMessages::identity($findings[0])]))->toBe([$findings[1]])
        ->and(NewMessages::findings(null))->toBe([])
        ->and(NewMessages::name('App\Notifications\LoginCodeNotification'))->toBe('login code')
        ->and(NewMessages::name('App\Mail\WelcomeMail'))->toBe('welcome')
        ->and(NewMessages::name('App\Mail\Mail'))->toBe('mail');
});
