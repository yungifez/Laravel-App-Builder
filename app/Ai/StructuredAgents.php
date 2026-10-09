<?php

namespace App\Ai;

use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\HasStructuredOutput;
use ReflectionClass;

/**
 * Finds every agent that answers in a fixed format, by reading the agent
 * folders, so a new agent is checked without being listed anywhere.
 */
class StructuredAgents
{
    /**
     * @param  list<string>  $directories
     * @return list<class-string<HasStructuredOutput>>
     */
    public static function in(array $directories): array
    {
        $agents = [];

        foreach ($directories as $directory) {
            if (! File::isDirectory($directory)) {
                continue;
            }

            foreach (File::allFiles($directory) as $file) {
                $class = self::className((string) file_get_contents($file->getPathname()));

                if ($class !== null && class_exists($class) && is_subclass_of($class, HasStructuredOutput::class) && (new ReflectionClass($class))->isInstantiable()) {
                    $agents[] = $class;
                }
            }
        }

        sort($agents);

        return array_values(array_unique($agents));
    }

    /**
     * Get the class a PHP file declares, from its namespace and class name.
     */
    protected static function className(string $contents): ?string
    {
        if (preg_match('/^\s*class\s+(\w+)/m', $contents, $class) !== 1) {
            return null;
        }

        return preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $contents, $namespace) === 1
            ? $namespace[1].'\\'.$class[1]
            : $class[1];
    }
}
