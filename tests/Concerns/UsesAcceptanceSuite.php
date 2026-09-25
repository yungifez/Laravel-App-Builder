<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

trait UsesAcceptanceSuite
{
    /**
     * Write a small platform acceptance suite and point verification at it.
     */
    protected function useAcceptanceSuite(): string
    {
        $directory = storage_path('framework/testing/acceptance-'.Str::lower(Str::random(8)));

        File::ensureDirectoryExists("{$directory}/Support");
        File::ensureDirectoryExists("{$directory}/Invitations");
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));

        File::put("{$directory}/phpunit.xml", '<phpunit>platform runner</phpunit>');
        File::put("{$directory}/Support/Helper.php", '<?php // platform helper');
        File::put("{$directory}/Invitations/ContractTest.php", '<?php // platform contract test');

        config(['builder.verification.acceptance.path' => $directory]);

        return $directory;
    }
}
