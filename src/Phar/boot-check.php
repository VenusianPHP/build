<?php

/*
 * Packed into every phar by venusian build, which runs it once with the built binary before
 * packaging, with HOME in a temporary directory: it boots the framework as a sketch does, builds
 * the phar's sketch without running it, opens the default database connection, and prints what
 * it found. An extension the app needs and
 * the binary lacks fails here instead of on a user's first launch.
 */

$root = __DIR__;
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make('Voyager\Contracts\Sketches\Kernel');
$kernel->bootstrap();

// The sketch the phar runs, built as the sketch command builds it and left unrun: what it needs from the container must resolve.
// The kernel's registry() is where rocket fills the registry from discovery and sketches.load; the bare singleton knows none.
$sketch = (new Phar(Phar::running(false)))->getMetadata()['sketch'] ?? null;
if (is_string($sketch) && $sketch !== '') {
    (fn () => $this->registry())->call($kernel)->resolve($sketch);
}

$database = $app->make('config')->get('database.default');
if (is_string($database) && $database !== '') {
    $app->make('db')->connection()->getPdo();
}

echo json_encode(['booted' => true, 'database' => $database, 'extensions' => array_map('strtolower', get_loaded_extensions())]), "\n";
