<?php

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Venusian\Build\App\Manifest;
use Venusian\Build\Console\Interview;

/*
 * The interview: name, version, sketch when several, author and summary
 * when empty, signing when the build signs. Enter keeps every default. The
 * answers that changed, plus the id, go back to build.json.
 */
$manifest = fn (array $over = []): Manifest => Manifest::fromJson(json_encode([
    'name' => 'Star Gazer', 'id' => 'com.venusian.star-gazer', 'version' => '1.0.0', 'sketch' => 'stargazer', 'sketches' => ['stargazer'],
    'icon' => null, 'extensions' => [], 'php' => null, 'repository' => 'phpacker/php-bin', 'sign' => 'adhoc', 'targets' => [], 'base_path' => '/app',
    'author' => 'A <a@b.c>', 'summary' => 'Sky', ...$over,
]));

it('keeps every default on Enter and asks signing when the build signs', function () use ($manifest) {
    Prompt::fake([Key::ENTER, Key::ENTER, Key::ENTER]);

    $after = (new Interview)->ask($manifest(), true, true);

    expect($after->name)->toBe('Star Gazer')->and($after->version)->toBe('1.0.0')->and($after->sign)->toBe('adhoc');
});

it('asks for author and summary only when they are empty', function () use ($manifest) {
    Prompt::fake([Key::ENTER, Key::ENTER, 'B <b@c.d>', Key::ENTER, 'Stars', Key::ENTER]);

    $after = (new Interview)->ask($manifest(['author' => '', 'summary' => '']), true, false);

    expect($after->name)->toBe('Star Gazer')->and($after->author)->toBe('B <b@c.d>')->and($after->summary)->toBe('Stars');
});

it('does not ask signing when nothing signs', function () use ($manifest) {
    Prompt::fake([Key::ENTER, Key::ENTER]);

    expect((new Interview)->ask($manifest(), true, false)->sign)->toBe('adhoc');
});

it('lists the changed answers and the id for build.json', function () use ($manifest) {
    $before = $manifest();
    $after = $before->with(['name' => 'Night Sky', 'version' => '2.0.0']);

    expect(Interview::answers($before, $after))->toBe(['id' => 'com.venusian.star-gazer', 'name' => 'Night Sky', 'version' => '2.0.0']);
});

it('refuses several sketches without a terminal', function () use ($manifest) {
    (new Interview)->ask($manifest(['sketch' => null, 'sketches' => ['a', 'b']]), false, true);
})->throws(RuntimeException::class, 'Several sketches (a, b): set sketch in build.json');

it('refuses a version Debian would not take and asks again', function () use ($manifest) {
    // The default 1.0.0 is in the field; "_" makes it 1.0.0_, refused; backspace restores 1.0.0.
    Prompt::fake([Key::ENTER, '_', Key::ENTER, Key::BACKSPACE, Key::ENTER]);

    $after = (new Interview)->ask($manifest(), true, false);

    expect($after->version)->toBe('1.0.0');
    Prompt::assertOutputContains('Start with a digit');
});
