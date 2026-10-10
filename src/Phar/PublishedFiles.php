<?php

namespace Venusian\Build\Phar;

use RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * The files a directory publishes: what git would put in an archive of its
 * working tree. In a git repository, the tracked files still present and the
 * untracked ones git does not ignore, less every path export-ignore'd in
 * .gitattributes, where a rule on a directory covers everything under it.
 * Outside one, every file but those under the fallback directories. .git
 * never publishes. Inside one, a git that fails stops the build with its
 * message: the fallback would ship what .gitignore keeps out.
 */
final class PublishedFiles
{
    /**
     * @param  list<string>  $fallback  directories left out when $dir is not in a git work tree
     * @return list<string> paths relative to $dir, sorted
     */
    public static function in(string $dir, array $fallback = []): array
    {
        $dir = rtrim($dir, '/');
        if (! self::inRepository($dir)) {
            $finder = Finder::create()->files()->in($dir)->ignoreDotFiles(false)->ignoreVCS(true)->exclude($fallback);
            $files = array_map(fn ($file): string => $file->getRelativePathname(), iterator_to_array($finder, false));
            sort($files);

            return $files;
        }

        $listed = self::git($dir, ['ls-files', '-z', '--cached', '--others', '--exclude-standard', '--', '.']);
        $files = array_values(array_unique(array_filter(explode("\0", $listed), fn (string $path): bool => $path !== '' && is_file("{$dir}/{$path}"))));
        $paths = [];
        foreach ($files as $file) {
            for ($path = $file; $path !== '.'; $path = dirname($path)) {
                $paths[$path] = true;
            }
        }

        $ignored = [];
        $parts = explode("\0", self::git($dir, ['check-attr', '-z', '--stdin', 'export-ignore'], implode("\0", array_keys($paths))."\0"));
        for ($i = 0; $i + 2 < count($parts); $i += 3) {
            if ($parts[$i + 2] === 'set') {
                $ignored[$parts[$i]] = true;
            }
        }

        $published = array_values(array_filter($files, function (string $file) use ($ignored): bool {
            for ($path = $file; $path !== '.'; $path = dirname($path)) {
                if (isset($ignored[$path])) {
                    return false;
                }
            }

            return true;
        }));
        sort($published);

        return $published;
    }

    /** A .git (directory, or file for a worktree or submodule) in $dir or above it. */
    private static function inRepository(string $dir): bool
    {
        for ($path = (string) (realpath($dir) ?: $dir); ; $path = dirname($path)) {
            if (file_exists("{$path}/.git")) {
                return true;
            }
            if (dirname($path) === $path) {
                return false;
            }
        }
    }

    /** @param  list<string>  $args */
    private static function git(string $dir, array $args, ?string $input = null): string
    {
        $process = new Process(['git', ...$args], $dir);
        $process->setInput($input);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("git cannot list what {$dir} publishes:\n".trim($process->getErrorOutput().$process->getOutput()));
        }

        return $process->getOutput();
    }
}
