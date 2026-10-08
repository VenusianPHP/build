<?php

namespace Venusian\Build\App;

/** Whether a directory is a Venusian app, and what is missing when it is not. */
final class AppInspector
{
    public function __construct(private readonly string $directory) {}

    /** @return list<string> problems, empty when this is a Venusian app */
    public function problems(): array
    {
        $problems = [];
        $manifest = is_file($this->directory.'/composer.json')
            ? json_decode((string) file_get_contents($this->directory.'/composer.json'), true)
            : null;

        if (! isset($manifest['require']['venusian/framework'])) {
            $problems[] = 'composer.json does not require venusian/framework';
        }

        if (! is_file($this->directory.'/computer')) {
            $problems[] = 'computer is missing';
        }

        if (! is_file($this->directory.'/bootstrap/app.php')) {
            $problems[] = 'bootstrap/app.php is missing';
        }

        if ($problems === [] && ! is_file($this->directory.'/vendor/autoload.php')) {
            $problems[] = 'vendor/autoload.php is missing: run composer install first';
        }

        return $problems;
    }
}
