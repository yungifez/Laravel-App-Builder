<?php

namespace App\Previews;

/**
 * Says in the owner's words why their app could not start: the stage it
 * reached, whose fault it is and what to do next. The page puts "Your app
 * could not start" above it, so it does not say that again.
 */
class PreviewFailure
{
    /**
     * Each setup step's stage, and whether a failure there comes from the
     * app's own code. A step not listed is a part of getting ready that is
     * ours.
     *
     * @var array<string, array{stage: string, app: bool}>
     */
    protected const STAGES = [
        'Create .env' => ['stage' => 'preparing your app\'s settings', 'app' => false],
        'Generate app key' => ['stage' => 'preparing your app\'s settings', 'app' => false],
        'Install PHP dependencies' => ['stage' => 'getting the parts your app is built from', 'app' => false],
        'Install Node dependencies' => ['stage' => 'getting the parts your app is built from', 'app' => false],
        'Start the database' => ['stage' => 'starting your app\'s database', 'app' => false],
        'Create the database' => ['stage' => 'setting up your app\'s data', 'app' => true],
        'Generate route helpers' => ['stage' => 'building your app\'s pages', 'app' => true],
        'Build the frontend' => ['stage' => 'building your app\'s pages', 'app' => true],
    ];

    /**
     * Why a setup step stopped the app from starting.
     */
    public static function step(string $name, bool $timedOut, int $timeoutSeconds): string
    {
        ['stage' => $stage, 'app' => $app] = self::STAGES[$name] ?? ['stage' => 'getting your app ready', 'app' => false];

        if ($timedOut) {
            return __(':Stage took more than :minutes, so I stopped. This is our fault. Try once more.', [
                'stage' => $stage,
                'minutes' => trans_choice('{1} a minute|[2,*] :count minutes', max(1, intdiv($timeoutSeconds, 60))),
            ]);
        }

        return $app
            ? __('Something in your app\'s code went wrong while :stage. Ask me in the chat to fix it.', ['stage' => $stage])
            : __('Something went wrong on our side while :stage. This is our fault. Try once more.', ['stage' => $stage]);
    }
}
