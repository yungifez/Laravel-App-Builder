<?php

namespace App\Actions\Previews;

use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class ReadPreviewFiles
{
    /**
     * Where a Laravel app keeps the files it stores, and how many are listed.
     */
    public const ROOT = 'storage/app';

    protected const LIMIT = 200;

    /**
     * Pictures a browser shows without running anything inside them. Other
     * files, SVG and HTML among them, are only ever downloaded.
     */
    public const PICTURES = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif'];

    /**
     * List the files under the storage folder, newest first, with their
     * size and time. It does not start the app, so it is quick.
     */
    protected const SCRIPT = <<<'PHP'
        $root = $argv[1]; $files = [];
        if (is_dir($root)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getFilename() !== '.gitignore') {
                    $files[] = [substr($file->getPathname(), strlen($root) + 1), $file->getSize(), $file->getMTime()];
                }
            }
        }
        usort($files, fn ($a, $b) => $b[2] <=> $a[2]);
        echo json_encode(array_slice($files, 0, (int) $argv[2]), JSON_INVALID_UTF8_SUBSTITUTE);
        PHP;

    public function __construct(private ReadPreviewLog $readPreviewLog, private RunPreviewCommand $runPreviewCommand) {}

    /**
     * Get the files the app on show stored, such as uploads, or null while
     * it does not run. Paths are from the storage folder.
     *
     * @return list<array{path: string, name: string, folder: string, size: int, stored_at: string, picture: bool}>|null
     */
    public function handle(Project $project): ?array
    {
        $preview = $this->readPreviewLog->preview($project);

        if ($preview === null) {
            return null;
        }

        return Cache::remember("previews:{$preview->id}:files", now()->addSeconds(4), function () use ($preview) {
            $output = (string) rescue(
                fn () => $this->runPreviewCommand->handle($preview, ['php', '-r', self::SCRIPT, '--', self::ROOT, (string) self::LIMIT], 60, ''),
                '',
                report: false,
            );
            $files = json_decode((string) collect(explode("\n", $output))->last(fn (string $line) => str_starts_with(trim($line), '[')), true);

            return array_values(collect(is_array($files) ? $files : [])
                ->filter(fn ($file) => is_array($file) && is_string($file[0] ?? null) && is_int($file[1] ?? null) && is_int($file[2] ?? null))
                ->map(fn (array $file) => [
                    'path' => $file[0],
                    'name' => basename($file[0]),
                    'folder' => dirname($file[0]) === '.' ? '' : dirname($file[0]),
                    'size' => $file[1],
                    'stored_at' => CarbonImmutable::createFromTimestamp($file[2])->toIso8601String(),
                    'picture' => in_array(strtolower(pathinfo($file[0], PATHINFO_EXTENSION)), self::PICTURES, true),
                ])
                ->all());
        });
    }
}
