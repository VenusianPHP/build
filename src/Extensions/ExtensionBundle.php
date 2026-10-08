<?php

namespace Venusian\Build\Extensions;

use RuntimeException;

/** The .so files to ship: every wanted extension the runtime lacks. */
final class ExtensionBundle
{
    /**
     * @param  list<string>  $wanted  extension names the app requires; missing ones fail the build
     * @param  list<string>  $builtin  extensions the runtime already has
     * @param  list<string>  $optional  extension names shipped when the PHP has them, skipped when not
     */
    public function __construct(
        private readonly array $wanted,
        private readonly array $builtin,
        private readonly string $extension_dir,
        private readonly array $optional = [],
    ) {}

    /** @return array<string, string> name => .so path, wanted first in order, then the optional ones present */
    public function files(): array
    {
        $builtin = array_map('strtolower', $this->builtin);
        $files = [];

        foreach ([[$this->wanted, true], [$this->optional, false]] as [$names, $required]) {
            foreach ($names as $name) {
                $name = strtolower($name);

                if (in_array($name, $builtin, true) || isset($files[$name])) {
                    continue;
                }

                $path = rtrim($this->extension_dir, '/').'/'.$name.'.so';

                if (is_file($path)) {
                    $files[$name] = $path;
                } elseif ($required) {
                    throw new RuntimeException("{$name}.so not found in {$this->extension_dir}. Install it into that PHP (venusian install:ext) or drop it from build.extensions.");
                }
            }
        }

        return $files;
    }
}
