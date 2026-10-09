<?php

/* The build environment files parse: shell with sh -n, YAML with the parser, and the Dockerfile names the floor. */
it('has shell scripts that parse', function () {
    foreach (['recipe.sh', 'package.sh'] as $script) {
        $process = new Symfony\Component\Process\Process(['sh', '-n', __DIR__.'/../build-env/'.$script]);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($script.': '.$process->getErrorOutput());
    }
});

it('builds on the Ubuntu 24.04 floor', function () {
    expect(file_get_contents(__DIR__.'/../build-env/Dockerfile'))->toStartWith('# The Linux build environment')
        ->and(file_get_contents(__DIR__.'/../build-env/Dockerfile'))->toContain("\nFROM ubuntu:24.04\n");
});

it('has a workflow that parses and pushes both architectures', function () {
    $yaml = file_get_contents(__DIR__.'/../.github/workflows/build-env.yml');
    $parsed = function_exists('yaml_parse') ? yaml_parse($yaml) : null;
    if ($parsed === null) {
        $process = new Symfony\Component\Process\Process(['ruby', '-ryaml', '-e', 'YAML.load_file(ARGV[0]); puts "ok"', __DIR__.'/../.github/workflows/build-env.yml']);
        $process->run();
        expect(trim($process->getOutput()))->toBe('ok', $process->getErrorOutput());
    }
    expect($yaml)->toContain('platforms: linux/amd64,linux/arm64')
        ->and($yaml)->toContain('ghcr.io/venusianphp/build-env:ubuntu24.04');
});

it('chains no commands with && where set -e would let a failure through', function () {
    // Under POSIX set -e a failing command before the last of an && list does not stop the script.
    foreach (['recipe.sh', 'package.sh'] as $script) {
        foreach (file(__DIR__.'/../build-env/'.$script) as $n => $line) {
            if (str_contains($line, '&&') && ! str_starts_with(ltrim($line), 'if ') && ! str_contains($line, '||')) {
                throw new RuntimeException("{$script}:".($n + 1).' chains with &&: '.trim($line));
            }
        }
    }
    expect(true)->toBeTrue();
});
