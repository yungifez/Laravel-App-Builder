<?php

namespace App\VisualEditing;

/**
 * Where an app's Wayfinder writes its actions and routes, and the import
 * aliases that reach them, read from the app's own files: the paths in
 * `tsconfig.json`, the aliases in its Vite config, and the `path` given to
 * Wayfinder's Vite plugin. Without them, Laravel's starter-kit defaults
 * hold: "@" is `resources/js`, and Wayfinder writes `resources/js/actions`
 * and `resources/js/routes`.
 */
final readonly class WayfinderPaths
{
    /**
     * @param  array<string, string>  $aliases  Each import alias, as "@", and the folder it stands for
     * @param  string  $base  The folder Wayfinder writes its actions and routes in
     */
    public function __construct(
        public array $aliases = ['@' => 'resources/js'],
        public string $base = 'resources/js',
    ) {}

    /**
     * Read the paths from the app's TypeScript and Vite configs.
     */
    public static function read(?string $tsconfig, ?string $viteConfig): self
    {
        $aliases = [];
        $tsconfig = (string) preg_replace('#/\*.*?\*/#s', '', (string) $tsconfig);

        if (preg_match('/"paths"\s*:\s*\{([^}]*)\}/', $tsconfig, $paths) === 1) {
            preg_match_all('/"([^"]+)"\s*:\s*\[\s*"([^"]+)"/', $paths[1], $pairs, PREG_SET_ORDER);

            foreach ($pairs as [, $alias, $folder]) {
                $aliases[self::trimmed($alias)] = self::trimmed($folder);
            }
        }

        // As in `'@': path.resolve(__dirname, './resources/js')` or
        // `'@': fileURLToPath(new URL('./resources/js', import.meta.url))`.
        preg_match_all('/[\'"]([@#~$][\w\/-]*)[\'"]\s*:\s*[^,\n]*?[\'"](\.{0,2}\/?[\w\/.-]+)[\'"]/', (string) $viteConfig, $pairs, PREG_SET_ORDER);

        foreach ($pairs as [, $alias, $folder]) {
            $aliases[self::trimmed($alias)] ??= self::trimmed($folder);
        }

        $base = preg_match('/wayfinder\s*\(\s*\{[^}]*?\bpath\s*:\s*[\'"]([^\'"]+)[\'"]/s', (string) $viteConfig, $path) === 1
            ? self::trimmed($path[1])
            : 'resources/js';

        return new self($aliases === [] ? ['@' => 'resources/js'] : $aliases, $base);
    }

    /**
     * Tell what an import is: Wayfinder's actions with the controller's
     * class, its routes with the name the folder stands for, "unknown" for
     * an import that looks like Wayfinder's but is not where this app's
     * Wayfinder writes, or null for anything else.
     *
     * @param  string  $file  The importing file, for relative imports
     * @return array{kind: 'action'|'route'|'unknown', base: string}|null
     */
    public function classify(string $specifier, string $file): ?array
    {
        $path = $this->resolve($specifier, $file);
        $path = $path === null ? null : (string) preg_replace('#/index(\.\w+)?$#', '', $path);

        if ($path !== null && str_starts_with($path, "{$this->base}/actions/")) {
            return ['kind' => 'action', 'base' => str_replace('/', '\\', substr($path, strlen("{$this->base}/actions/")))];
        }

        if ($path !== null && ($path === "{$this->base}/routes" || str_starts_with($path, "{$this->base}/routes/"))) {
            return ['kind' => 'route', 'base' => str_replace('/', '.', ltrim(substr($path, strlen("{$this->base}/routes")), '/'))];
        }

        return preg_match('#(^|/)(actions/[A-Z]|routes(/|$))#', $specifier) === 1 ? ['kind' => 'unknown', 'base' => ''] : null;
    }

    /**
     * Get the project path an import names, or null for a package.
     */
    protected function resolve(string $specifier, string $file): ?string
    {
        if (str_starts_with($specifier, './') || str_starts_with($specifier, '../')) {
            $parts = [];

            foreach (explode('/', dirname($file).'/'.$specifier) as $part) {
                match ($part) {
                    '', '.' => null,
                    '..' => array_pop($parts),
                    default => $parts[] = $part,
                };
            }

            return implode('/', $parts);
        }

        foreach ($this->aliases as $alias => $folder) {
            if ($specifier === $alias || str_starts_with($specifier, "{$alias}/")) {
                return ltrim($folder.substr($specifier, strlen($alias)), '/');
            }
        }

        return null;
    }

    /**
     * Get an alias or folder without its "./", trailing "/*" or "/".
     */
    protected static function trimmed(string $path): string
    {
        return rtrim((string) preg_replace('#^\./|/\*$#', '', $path), '/');
    }
}
