<?php

namespace Venusian\Build\Runtime;

/**
 * The ini phpmicro reads from the block between the sfx and the payload.
 *
 * Rendered line by line so repeated extension= keys all survive; an
 * associative merge would keep only the last one.
 */
final class MicroIni
{
    /**
     * @param  string  $extension_dir  resolved by phpmicro against the working directory
     * @param  list<string>  $extensions  file names inside $extension_dir
     * @param  array<string, string>  $settings
     */
    public function __construct(
        public readonly string $extension_dir,
        public readonly array $extensions,
        public readonly array $settings = ['memory_limit' => '1024M', 'display_errors' => 'stderr'],
    ) {}

    public function render(): string
    {
        $lines = [];

        foreach ($this->settings as $key => $value) {
            $lines[] = $key.'='.$value;
        }

        $lines[] = 'extension_dir='.$this->extension_dir;

        foreach ($this->extensions as $file) {
            $lines[] = 'extension='.$file;
        }

        return implode("\n", $lines)."\n";
    }
}
