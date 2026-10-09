<?php

namespace Venusian\Build\App;

use JsonException;
use RuntimeException;

/**
 * build.json at the app root: the app's build facts, committed with the app.
 *
 * Every key has a default; unknown keys are refused by name so a typo never
 * passes as "not set". The interview writes its answers back here. Machine
 * facts (docker contexts, signing identities) live in the per-user config,
 * never in this file.
 */
final class BuildJson
{
    public const FILE = 'build.json';

    public const SCHEMA = 'https://raw.githubusercontent.com/VenusianPHP/build/main/schema/build.schema.json';

    public const TARGETS = ['macos-arm64', 'linux-arm64', 'linux-x86_64'];

    public const DEFAULTS = [
        'id' => null,            // reverse-DNS; must equal config/app.php app.id when set
        'name' => null,          // null uses app.name
        'version' => '0.1.0',
        'sketch' => null,        // the only sketch, or asked when several
        'icon' => null,          // square PNG relative to the app
        'summary' => '',         // one line
        'description' => '',     // a paragraph
        'author' => '',          // "Name <email>"
        'homepage' => '',
        'license' => '',
        'category' => 'Utility', // freedesktop main category
        'extensions' => [],      // ext names beyond what composer.lock declares
        'zts' => false,          // thread-safe runtime on Linux
        'php' => null,           // macOS: NTS PHP binary supplying .so files
        'repository' => 'phpacker/php-bin', // macOS runtime source
        'sign' => 'adhoc',       // macOS: or a codesign identity string
        'targets' => [],         // [] = this machine
        'env' => [],
        'env_except' => [],
    ];

    private const ID = '/^[A-Za-z_][A-Za-z0-9_-]*(\.[A-Za-z_][A-Za-z0-9_-]*)+$/';

    public const VERSION = '/^\d[0-9A-Za-z.~+-]*$/';

    public function __construct(private readonly string $app_dir) {}

    public function path(): string
    {
        return rtrim($this->app_dir, '/').'/'.self::FILE;
    }

    /** @return array<string, mixed> the defaults with the file over them */
    public function read(): array
    {
        $file = $this->raw();
        unset($file['$schema']);

        foreach (array_keys($file) as $key) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                throw new RuntimeException(self::FILE.' has no key "'.$key.'"; the keys are '.implode(', ', array_keys(self::DEFAULTS)).'.');
            }
        }

        $values = array_merge(self::DEFAULTS, $file);
        $this->validate($values);

        return $values;
    }

    /** @param  array<string, mixed>  $values  keys to set; the rest of the file is kept */
    public function write(array $values): void
    {
        $file = $this->raw();
        unset($file['$schema']);

        // Given keys first, in the order given; untouched keys after, as they were.
        $ordered = ['$schema' => self::SCHEMA];
        foreach ($values as $key => $value) {
            $ordered[$key] = $value;
        }
        foreach ($file as $key => $value) {
            if (! array_key_exists($key, $ordered)) {
                $ordered[$key] = $value;
            }
        }

        file_put_contents($this->path(), json_encode($ordered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    /** @return array<string, mixed> */
    private function raw(): array
    {
        if (! is_file($this->path())) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($this->path()), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(self::FILE.' is not valid JSON: '.$e->getMessage());
        }

        if (! is_array($decoded)) {
            throw new RuntimeException(self::FILE.' must hold a JSON object.');
        }

        return $decoded;
    }

    /** @param  array<string, mixed>  $values */
    private function validate(array $values): void
    {
        if ($values['id'] !== null && (! is_string($values['id']) || ! preg_match(self::ID, $values['id']) || strlen($values['id']) > 255)) {
            throw new RuntimeException(self::FILE.' id "'.$values['id'].'" must be reverse-DNS: dot-separated segments of letters, digits, _ and -, each starting with a letter, such as com.venusian.stargazer.');
        }

        if (! is_string($values['version']) || ! preg_match(self::VERSION, $values['version'])) {
            throw new RuntimeException(self::FILE.' version "'.$values['version'].'" must start with a digit and use only digits, letters, . ~ + and -.');
        }

        foreach ((array) $values['targets'] as $target) {
            if (! in_array($target, self::TARGETS, true)) {
                throw new RuntimeException(self::FILE.' targets: '.$target.' is not one of '.implode(', ', self::TARGETS).'.');
            }
        }

        foreach (['name', 'sketch', 'icon', 'summary', 'description', 'author', 'homepage', 'license', 'category', 'php', 'repository', 'sign'] as $key) {
            if ($values[$key] !== null && ! is_string($values[$key])) {
                throw new RuntimeException(self::FILE.' '.$key.' must be a string.');
            }
        }

        foreach (['extensions', 'targets', 'env_except'] as $key) {
            if (! is_array($values[$key]) || ! array_is_list($values[$key])) {
                throw new RuntimeException(self::FILE.' '.$key.' must be a list.');
            }
        }

        if (! is_array($values['env'])) {
            throw new RuntimeException(self::FILE.' env must be an object of KEY: value.');
        }

        if (! is_bool($values['zts'])) {
            throw new RuntimeException(self::FILE.' zts must be true or false.');
        }
    }
}
