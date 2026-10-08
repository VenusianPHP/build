<?php

/*
 * Run by ManifestReader in a child PHP: php read.php <app dir>
 *
 * Loads the app's autoloader when it has one (config files call framework
 * classes), exports .env into the environment so env() answers as the app
 * expects, evaluates config/build.php and config/app.php, lists the sketches
 * under app/Runner/Sketches by the name the framework's SketchRegistry would
 * give them, and prints JSON with the parsed .env beside them.
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

foreach ($env as $key => $value) {
    if (getenv($key) === false) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
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

echo json_encode([
    'build' => $read($app.'/config/build.php'),
    'app' => $read($app.'/config/app.php'),
    'sketches' => $sketches,
    'dotenv' => (object) $env,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
