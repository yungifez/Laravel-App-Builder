<?php

namespace App\Actions\Workspaces;

use App\Models\Workspace;

/**
 * What a workspace builds with: the box image it started from, the versions
 * of its tools and a hash of each lockfile. A run keeps this, so a change
 * that worked once can be built again the same way, and one that broke can
 * be traced to what moved.
 */
class DescribeEnvironment
{
    /**
     * The lockfiles of the package managers a box has, PHP's and Node's.
     */
    public const LOCKFILES = ['composer.lock', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb'];

    public function __construct(private RunWorkspaceCommand $runWorkspaceCommand) {}

    /**
     * Ask the workspace once. A tool that is missing or will not say its
     * version is null; a lockfile the app does not have is left out.
     *
     * The digest comes from whoever started the box (BOX_IMAGE_DIGEST); a
     * workspace outside a box has none.
     *
     * @return array{image: string|null, image_digest: string|null, tools: array{php: string|null, composer: string|null, node: string|null, npm: string|null, postgres: string|null}, lockfiles: array<string, string>}
     */
    public function handle(Workspace $workspace): array
    {
        $lockfiles = implode(' ', self::LOCKFILES);
        $script = <<<SH
            echo "image_digest \${BOX_IMAGE_DIGEST:-}"
            echo "php \$(php -r 'echo PHP_VERSION;' 2>/dev/null)"
            echo "composer \$(composer --version --no-ansi --no-interaction 2>/dev/null | head -n 1)"
            echo "node \$(node --version 2>/dev/null)"
            echo "npm \$(npm --version 2>/dev/null)"
            echo "postgres \$(psql --version 2>/dev/null)"
            for file in {$lockfiles}; do
                [ -f "\$file" ] && echo "lockfile \$file \$(sha256sum "\$file" | cut -d ' ' -f 1)"
            done
            true
            SH;

        $output = $this->runWorkspaceCommand->handle($workspace, ['sh', '-c', $script], 60)->output;
        $lines = collect(preg_split('/\R/', $output) ?: [])->map(fn (string $line) => explode(' ', trim($line), 2) + [1 => '']);
        $said = $lines->filter(fn (array $line) => $line[0] !== 'lockfile')->mapWithKeys(fn (array $line) => [$line[0] => trim($line[1])]);
        $digest = (string) $said->get('image_digest', '');
        $version = fn (string $tool) => preg_match('/\d+\.\d+(?:\.\d+)?/', (string) $said->get($tool, ''), $match) === 1 ? $match[0] : null;

        return [
            'image' => $workspace->image,
            'image_digest' => preg_match('/^[\w.\/:@-]{1,300}$/', $digest) === 1 ? $digest : null,
            'tools' => [
                'php' => $version('php'),
                'composer' => $version('composer'),
                'node' => $version('node'),
                'npm' => $version('npm'),
                'postgres' => $version('postgres'),
            ],
            'lockfiles' => $lines
                ->filter(fn (array $line) => $line[0] === 'lockfile')
                ->map(fn (array $line) => explode(' ', $line[1], 2) + [1 => ''])
                ->filter(fn (array $file) => in_array($file[0], self::LOCKFILES, true) && preg_match('/^[0-9a-f]{64}$/', $file[1]) === 1)
                ->mapWithKeys(fn (array $file) => [$file[0] => $file[1]])
                ->all(),
        ];
    }
}
