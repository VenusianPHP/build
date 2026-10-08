<?php

/*
 * Run by PharBuilder in a child PHP: php -d phar.readonly=0 pack.php <stage> <output> <metadata json>
 *
 * Files are added by relative name through an iterator that follows
 * symlinks, so a vendor package installed from a path repository (a
 * symlink out of the stage) ships as files like any other.
 *
 * The stub hands rocket the sketch as its first argument, so the packaged
 * app runs that sketch; any further arguments pass through. Named a script
 * inside itself as the first argument, it runs that script instead, the way
 * `php <script>` would: the framework's process pools spawn PHP_BINARY on
 * their worker script, and in a packaged app PHP_BINARY is this binary.
 */

[, $stage, $output, $metadata] = $argv;
$stage = rtrim($stage, '/');
$metadata = json_decode($metadata, true, flags: JSON_THROW_ON_ERROR);
$sketch = var_export((string) $metadata['sketch'], true);

$files = static function () use ($stage): Generator {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS | FilesystemIterator::CURRENT_AS_PATHNAME),
    );

    foreach ($iterator as $path) {
        if (is_file($path)) {
            yield substr($path, strlen($stage) + 1) => $path;
        }
    }
};

$phar = new Phar($output);
$phar->setMetadata($metadata);
$phar->buildFromIterator($files());
$phar->setStub(<<<PHP
<?php
Phar::interceptFileFuncs();
\$self = ['phar://'.__FILE__.'/', 'phar://'.realpath(__FILE__).'/'];
\$script = \$_SERVER['argv'][1] ?? '';
if (\$script !== '' && (str_starts_with(\$script, \$self[0]) || str_starts_with(\$script, \$self[1]))) {
    \$argv = \$_SERVER['argv'] = array_slice(\$_SERVER['argv'], 1);
    \$argc = \$_SERVER['argc'] = count(\$argv);
    require \$script;
    return;
}
\$argv = \$_SERVER['argv'] = ['rocket', {$sketch}, ...array_slice(\$_SERVER['argv'] ?? [], 1)];
\$argc = \$_SERVER['argc'] = count(\$argv);
require 'phar://'.__FILE__.'/rocket';
__HALT_COMPILER();
PHP);
