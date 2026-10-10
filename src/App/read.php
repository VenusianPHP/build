<?php

/*
 * Run by ManifestReader in a child PHP: php read.php <app dir> [<{"env": {...}, "except": [...]} from build.json>]
 *
 * Loads the app's autoloader when it has one (config files call framework
 * classes), exports .env into the environment so env() answers as the app
 * expects, evaluates config/app.php, lists the sketches under
 * app/Runner/Sketches by the name the framework's SketchRegistry would give
 * them, and prints JSON with the parsed .env beside them.
 */

$app = rtrim((string) ($argv[1] ?? ''), '/');

$env = [];
if (is_file($app.'/.env')) {
    foreach (file($app.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(ltrim($line), '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim(trim($value), "\"'");
    }
}

// Config is evaluated in the environment the packaged app gets: build.json env over .env, less env_except.
$dotenv = $env;
$packaged = json_decode((string) ($argv[2] ?? '{}'), true) ?: [];
$overrides = array_map('strval', (array) ($packaged['env'] ?? []));
$env = array_diff_key([...$env, ...$overrides], array_flip((array) ($packaged['except'] ?? [])));

foreach ($env as $key => $value) {
    if (getenv($key) === false || array_key_exists($key, $overrides)) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

// The framework's path helpers ask its container; config files evaluated here get the app's own paths.
if (! function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return rtrim($GLOBALS['app'].'/'.ltrim($path, '/'), '/');
    }
}
if (! function_exists('database_path')) {
    function database_path(string $path = ''): string
    {
        return base_path('database/'.ltrim($path, '/'));
    }
}
if (! function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage/'.ltrim($path, '/'));
    }
}

if (is_file($app.'/vendor/autoload.php')) {
    require $app.'/vendor/autoload.php';
}

if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        global $env;
        $value = $env[$key] ?? getenv($key);

        return $value === false || $value === null ? $default : $value;
    }
}

if (! function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}

$read = static function (string $path): array {
    if (! is_file($path)) {
        return [];
    }
    $value = require $path;

    return is_array($value) ? $value : [];
};

$sketches = [];
$directory = $app.'/app/Runner/Sketches';
if (is_dir($directory)) {
    foreach (glob($directory.'/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);
        if (preg_match('/^\s*abstract\s+class\s/m', $source)) {
            continue;
        }
        if (! preg_match('/^\s*(?:final\s+)?class\s+(\w+)/m', $source, $class)) {
            continue;
        }
        if (preg_match('/#\[\s*\\\\?(?:[\w\\\\]*\\\\)?\w+\s*\(\s*(?:name\s*:\s*)?[\'"]([^\'"]+)[\'"]\s*\)\s*\]\s*(?:final\s+)?class\s+'.$class[1].'\b/', $source, $named)) {
            $sketches[] = $named[1];
            continue;
        }
        $sketches[] = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $class[1]));
    }
}
sort($sketches);

$database = $read($app.'/config/database.php');
$default = $database['default'] ?? null;
$connection = is_string($default) ? ($database['connections'][$default] ?? null) : null;

echo json_encode([
    'app' => $read($app.'/config/app.php'),
    'sketches' => $sketches,
    'database' => is_array($connection) ? ['driver' => (string) ($connection['driver'] ?? ''), 'database' => (string) ($connection['database'] ?? '')] : null,
    'dotenv' => (object) $dotenv,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
