<?php

namespace Venusian\Build\Extensions;

use ReflectionClass;

/**
 * Which extension defines a function or class: lower-case name => lower-case
 * extension name, as a full PHP reports it through get_extension_funcs() and
 * class reflection. The always-on extensions and development tools are never
 * named. The shipped map (resources/extension-symbols.json) is merged under the
 * PHP running the build, so an extension installed here is known too.
 */
final class SymbolMap
{
    /** Always in PHP (ExtensionSet::ALWAYS), or tools no build compiles in. */
    private const SKIP = ['core', 'date', 'hash', 'json', 'pcre', 'random', 'reflection', 'spl', 'standard', 'xdebug', 'zend opcache', 'zephir_parser'];

    /**
     * @param  array<string, string>  $functions
     * @param  array<string, string>  $classes  classes, interfaces and enums
     */
    public function __construct(
        public readonly array $functions,
        public readonly array $classes,
    ) {}

    public static function fromRunningPhp(): self
    {
        $functions = [];
        foreach (get_loaded_extensions() as $extension) {
            $name = strtolower($extension);
            if (! in_array($name, self::SKIP, true)) {
                foreach (get_extension_funcs($extension) ?: [] as $function) {
                    $functions[strtolower($function)] = $name;
                }
            }
        }

        $classes = [];
        foreach ([...get_declared_classes(), ...get_declared_interfaces()] as $class) {
            $reflection = new ReflectionClass($class);
            $name = strtolower((string) $reflection->getExtensionName());
            if ($reflection->isInternal() && $name !== '' && ! in_array($name, self::SKIP, true)) {
                $classes[strtolower($class)] = $name;
            }
        }

        return new self($functions, $classes);
    }

    public static function fromFile(string $path): self
    {
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return new self((array) $data['functions'], (array) $data['classes']);
    }

    public static function shipped(): self
    {
        return self::fromFile(__DIR__.'/../../resources/extension-symbols.json')->merge(self::fromRunningPhp());
    }

    /** $over's entries win. */
    public function merge(self $over): self
    {
        return new self([...$this->functions, ...$over->functions], [...$this->classes, ...$over->classes]);
    }

    public function toJson(): string
    {
        $functions = $this->functions;
        $classes = $this->classes;
        ksort($functions);
        ksort($classes);

        return json_encode(['functions' => $functions, 'classes' => $classes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
