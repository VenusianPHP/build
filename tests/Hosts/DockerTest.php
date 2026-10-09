<?php

use Venusian\Build\Hosts\Docker;

/*
 * Every docker call goes through one closure, so the tests see the exact
 * command lines and feed the answers.
 */
beforeEach(function () {
    $this->commands = [];
    $this->answers = [];
    $this->docker = new Docker(function (array $command, ?string $stdin): string {
        $this->commands[] = [$command, $stdin];
        $answer = array_shift($this->answers);
        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer ?? '';
    });
});

it('reads the architecture of a context in Build::hostName spelling', function () {
    $this->answers = ["aarch64\n", "x86_64\n"];

    expect($this->docker->arch('jetson'))->toBe('arm64')
        ->and($this->docker->arch('gamingpc'))->toBe('x86_64')
        ->and($this->commands[0][0])->toBe(['docker', '--context', 'jetson', 'info', '--format', '{{.Architecture}}']);
});

it('asks each context for its architecture once', function () {
    $this->answers = ["aarch64\n"];

    $this->docker->arch('jetson');
    $this->docker->arch('jetson');

    expect($this->commands)->toHaveCount(1);
});

it('says a context does not answer when docker info prints no architecture', function () {
    // docker info exits 0 with an empty format result when the daemon is down; the error went to stderr.
    $this->answers = ["\n"];

    $this->docker->arch('desktop-linux');
})->throws(RuntimeException::class, 'docker context desktop-linux does not answer: is its docker daemon running?');

it('names the current context, the local engine docker uses without --context', function () {
    $this->answers = ["desktop-linux\n"];

    expect($this->docker->current())->toBe('desktop-linux')
        ->and($this->commands[0][0])->toBe(['docker', 'context', 'show']);
});

it('says when a context does not answer', function () {
    $this->answers = [new RuntimeException('Cannot connect to the Docker daemon')];

    $this->docker->arch('jetson');
})->throws(RuntimeException::class, 'docker context jetson does not answer: Cannot connect to the Docker daemon');

it('checks, pulls and builds an image, the build context sent as a tar on stdin', function () {
    $dir = sys_get_temp_dir().'/venusian-dockerfile-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/Dockerfile', 'FROM ubuntu:24.04');
    $this->answers = [new RuntimeException('No such image'), '', ''];

    expect($this->docker->hasImage('default', 'img'))->toBeFalse();
    $this->docker->buildImage('default', 'img', $dir);

    expect($this->commands[1][0])->toBe(['docker', '--context', 'default', 'build', '-t', 'img', '-'])
        ->and($this->commands[1][1])->not->toBeNull()
        ->and(is_file($this->commands[1][1]))->toBeFalse();   // the tar is deleted after the call
    unlink($dir.'/Dockerfile');
    rmdir($dir);
});

it('pushes a tar into a volume, runs in it, and fetches a file out', function () {
    $tar = tempnam(sys_get_temp_dir(), 'tar');
    $this->answers = ['', "built\n", 'DEB'];
    $to = tempnam(sys_get_temp_dir(), 'out');

    $this->docker->push('gamingpc', 'img', 'vol', $tar);
    $out = $this->docker->run('gamingpc', 'img', 'vol', ['sh', 'recipe.sh']);
    $this->docker->fetch('gamingpc', 'img', 'vol', 'out/app.deb', $to);

    expect($this->commands[0][0])->toBe(['docker', '--context', 'gamingpc', 'run', '--rm', '-i', '-v', 'vol:/work', '-w', '/work', 'img', 'tar', '-xf', '-'])
        ->and($this->commands[0][1])->toBe($tar)
        ->and($this->commands[1][0])->toBe(['docker', '--context', 'gamingpc', 'run', '--rm', '-v', 'vol:/work', '-w', '/work', 'img', 'sh', 'recipe.sh'])
        ->and($out)->toBe("built\n")
        ->and($this->commands[2][0])->toBe(['docker', '--context', 'gamingpc', 'run', '--rm', '-v', 'vol:/work', '-w', '/work', 'img', 'cat', 'out/app.deb'])
        ->and(file_get_contents($to))->toBe('DEB');
    unlink($tar);
    unlink($to);
});
