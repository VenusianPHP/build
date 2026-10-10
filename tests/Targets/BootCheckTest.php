<?php

use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\Targets\BootCheck;

/*
 * The real boot check runs the binary with a time limit; a boot that never
 * finishes stops the build like any other failed boot, with what it printed.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/venusian-bootcheck-'.bin2hex(random_bytes(4));
    (new Filesystem)->dumpFile($this->root.'/hangs', "#!/bin/sh\necho starting\nexec sleep 30\n");
    chmod($this->root.'/hangs', 0755);
});

afterEach(function () {
    (new Filesystem)->remove($this->root);
});

it('stops a boot that never finishes, naming the app and showing what it printed', function () {
    try {
        BootCheck::real(1)->check($this->root.'/hangs', $this->root.'/app.phar', 'Probe');
        $message = null;
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toStartWith('Probe does not start; the built binary, booting the packaged app, said:')
        ->toContain('Still booting after 1 s; stopped.')
        ->toContain('starting');
});
