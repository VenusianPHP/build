<?php

use Symfony\Component\Console\Command\Command;

it('declares only command classes that exist, as a venusian-tool package', function () {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['type'])->toBe('venusian-tool');

    foreach ($manifest['extra']['venusian']['commands'] as $class) {
        expect(class_exists($class))->toBeTrue($class)
            ->and(is_subclass_of($class, Command::class))->toBeTrue($class);
    }
});
