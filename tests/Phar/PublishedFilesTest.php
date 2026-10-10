<?php

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Venusian\Build\Phar\PublishedFiles;

/*
 * What a directory publishes: in a git repository, tracked files and untracked
 * ones git does not ignore, less export-ignore (a directory's rule covers what
 * is under it); outside one, everything but the fallback directories.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-published-'.bin2hex(random_bytes(4));
    $files = new Filesystem;
    foreach (['src/A.php', 'tests/ATest.php', 'docs/guide.md', 'README.md', 'ignored.log', 'vendor/x.php', 'notes.txt'] as $path) {
        $files->dumpFile("{$this->root}/{$path}", 'x');
    }
    $files->dumpFile("{$this->root}/.gitignore", "/vendor\n*.log\n");
    $files->dumpFile("{$this->root}/.gitattributes", "/tests export-ignore\n/docs export-ignore\n/.gitattributes export-ignore\n");
    $this->git = fn (string ...$args) => (new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', ...$args], $this->root))->mustRun();
});

afterEach(function () {
    (new Filesystem)->remove($this->root);
});

it('publishes tracked and untracked files git does not ignore, less export-ignore', function () {
    ($this->git)('init', '-q');
    ($this->git)('add', '.gitignore', '.gitattributes', 'src', 'tests', 'docs', 'README.md');
    ($this->git)('commit', '-q', '-m', 'x');

    expect(PublishedFiles::in($this->root))->toBe(['.gitignore', 'README.md', 'notes.txt', 'src/A.php']);
});

it('leaves out a tracked file deleted from the working tree', function () {
    ($this->git)('init', '-q');
    ($this->git)('add', 'src', 'README.md');
    ($this->git)('commit', '-q', '-m', 'x');
    unlink($this->root.'/README.md');

    expect(PublishedFiles::in($this->root))->not->toContain('README.md');
});

it('falls back to every file but the given directories outside a git repository', function () {
    expect(PublishedFiles::in($this->root, ['vendor', 'tests']))
        ->toBe(['.gitattributes', '.gitignore', 'README.md', 'docs/guide.md', 'ignored.log', 'notes.txt', 'src/A.php']);
});

it('refuses, with git\'s message, a repository git cannot read, instead of shipping what it ignores', function () {
    file_put_contents($this->root.'/.git', "gitdir: {$this->root}/nowhere\n");

    expect(fn () => PublishedFiles::in($this->root.'/src'))->toThrow(RuntimeException::class, "git cannot list what {$this->root}/src publishes");
});
