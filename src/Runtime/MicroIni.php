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
    public const DEFAULTS = ['memory_limit' => '1024M', 'display_errors' => 'stderr'];

    /**
     * @param  string  $extension_dir  resolved by phpmicro against the working directory
     * @param  list<string>  $extensions  file names inside $extension_dir
     * @param  array<string, string>  $settings
     */
    public function __construct(
        public readonly string $extension_dir,
        public readonly array $extensions,
        public readonly array $settings = self::DEFAULTS,
    ) {}

    /**
     * The settings a packaged app runs with: the defaults plus micro.php_binary
     * naming the binary itself, relative to the directory the launcher enters.
     * PHP_BINARY is empty in phpmicro otherwise; with it the framework's
     * process pools spawn this same binary on their worker script.
     *
     * @return array<string, string>
     */
    public static function forBinary(string $binary_name): array
    {
        return [...self::DEFAULTS, 'micro.php_binary' => './'.$binary_name];
    }

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
