<?php

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\Manifest;
use Venusian\Build\Console\Interview;
use Venusian\Build\Hosts\UserConfig;

/*
 * The interview: name, version, sketch when several, author and summary
 * when empty; signing once per machine. Enter keeps every default. The
 * answers that changed, plus the id, go back to build.json.
 */
$manifest = fn (array $over = []): Manifest => Manifest::fromJson(json_encode([
    'name' => 'Star Gazer', 'id' => 'com.venusian.star-gazer', 'version' => '1.0.0', 'sketch' => 'stargazer', 'sketches' => ['stargazer'],
    'icon' => null, 'extensions' => [], 'targets' => [], 'base_path' => '/app',
    'author' => 'A <a@b.c>', 'summary' => 'Sky', ...$over,
]));

it('keeps every default on Enter', function () use ($manifest) {
    Prompt::fake([Key::ENTER, Key::ENTER]);

    $after = (new Interview)->ask($manifest(), true);

    expect($after->name)->toBe('Star Gazer')->and($after->version)->toBe('1.0.0');
});

it('asks for author and summary only when they are empty', function () use ($manifest) {
    Prompt::fake([Key::ENTER, Key::ENTER, 'B <b@c.d>', Key::ENTER, 'Stars', Key::ENTER]);

    $after = (new Interview)->ask($manifest(['author' => '', 'summary' => '']), true);

    expect($after->name)->toBe('Star Gazer')->and($after->author)->toBe('B <b@c.d>')->and($after->summary)->toBe('Stars');
});

it('lists the changed answers and the id for build.json', function () use ($manifest) {
    $before = $manifest();
    $after = $before->with(['name' => 'Night Sky', 'version' => '2.0.0']);

    expect(Interview::answers($before, $after))->toBe(['id' => 'com.venusian.star-gazer', 'name' => 'Night Sky', 'version' => '2.0.0']);
});

it('refuses several sketches without a terminal', function () use ($manifest) {
    (new Interview)->ask($manifest(['sketch' => null, 'sketches' => ['a', 'b']]), false);
})->throws(RuntimeException::class, 'Several sketches (a, b): set sketch in build.json');

it('refuses a version Debian would not take and asks again', function () use ($manifest) {
    // The default 1.0.0 is in the field; "_" makes it 1.0.0_, refused; backspace restores 1.0.0.
    Prompt::fake([Key::ENTER, '_', Key::ENTER, Key::BACKSPACE, Key::ENTER]);

    $after = (new Interview)->ask($manifest(), true);

    expect($after->version)->toBe('1.0.0');
    Prompt::assertOutputContains('Start with a digit');
});

it('asks how macOS builds sign and saves the identity and profile to the machine', function () {
    $home = sys_get_temp_dir().'/venusian-home-'.bin2hex(random_bytes(4));
    $config = new UserConfig($home);
    Prompt::fake([Key::ENTER, 'venusian', Key::ENTER]);

    (new Interview)->signing($config, ['Developer ID Application: Angel Gonzalez (TEAM123)']);

    expect($config->macos())->toBe(['sign' => 'Developer ID Application: Angel Gonzalez (TEAM123)', 'notary_profile' => 'venusian']);
    (new Filesystem)->remove($home);
});

it('saves ad hoc without asking for a profile', function () {
    $home = sys_get_temp_dir().'/venusian-home-'.bin2hex(random_bytes(4));
    $config = new UserConfig($home);
    Prompt::fake([Key::UP, Key::ENTER]);

    (new Interview)->signing($config, ['Developer ID Application: Angel Gonzalez (TEAM123)']);

    expect($config->macos())->toBe(['sign' => 'adhoc', 'notary_profile' => null]);
    (new Filesystem)->remove($home);
});

it('asks about signing only with a terminal, a signing target and no answer on this machine', function () {
    $home = sys_get_temp_dir().'/venusian-home-'.bin2hex(random_bytes(4));
    $config = new UserConfig($home);

    expect(Interview::asksSigning(true, true, $config))->toBeTrue()
        ->and(Interview::asksSigning(false, true, $config))->toBeFalse()
        ->and(Interview::asksSigning(true, false, $config))->toBeFalse();

    $config->saveMacos('adhoc', null);

    expect(Interview::asksSigning(true, true, $config))->toBeFalse();
    (new Filesystem)->remove($home);
});

it('reads Developer ID Application identities from the keychain listing', function () {
    $listing = "  1) 0A1B \"Apple Development: Angel Gonzalez (DEV1)\"\n  2) 2C3D \"Developer ID Application: Angel Gonzalez (TEAM123)\"\n     2 valid identities found\n";

    expect(Interview::identities($listing))->toBe(['Developer ID Application: Angel Gonzalez (TEAM123)']);
});
