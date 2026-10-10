<?php

namespace Venusian\Build\Console;

use RuntimeException;
use Venusian\Build\App\BuildJson;
use Venusian\Build\App\Manifest;
use Venusian\Build\Hosts\UserConfig;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * The questions before a build, each defaulted from the manifest so a
 * second run is Enter all the way; signing() asks once per machine how
 * macOS builds sign. Without a terminal the defaults stand.
 * The id is never asked: config/app.php owns it.
 */
final class Interview
{
    public function ask(Manifest $manifest, bool $interactive): Manifest
    {
        if (! $interactive) {
            return $this->settled($manifest);
        }

        $name = text(label: 'App name', default: $manifest->name, required: true);
        $version = text(label: 'Version', default: $manifest->version, required: true, validate: fn (string $v): ?string => preg_match(BuildJson::VERSION, $v) ? null : 'Start with a digit; use only digits, letters, . ~ + and - (Debian takes it as the package version).');

        $sketch = $manifest->sketch;
        if (is_null($sketch) && count($manifest->sketches) > 1) {
            $sketch = (string) select(label: 'Sketch the app runs', options: $manifest->sketches);
        }

        $author = $manifest->author !== '' ? $manifest->author : text(label: 'Author', placeholder: 'Name <email>', required: true);
        $summary = $manifest->summary !== '' ? $manifest->summary : text(label: 'One-line summary', required: true);

        return $this->settled($manifest->with([
            'name' => $name,
            'version' => $version,
            'sketch' => $sketch,
            'author' => $author,
            'summary' => $summary,
        ]));
    }

    /**
     * What to write back to build.json: the id, then every asked key whose answer changed.
     *
     * @return array<string, mixed>
     */
    public static function answers(Manifest $before, Manifest $after): array
    {
        $answers = ['id' => $after->id];

        foreach (['name', 'version', 'sketch', 'author', 'summary'] as $key) {
            if ($before->{$key} !== $after->{$key}) {
                $answers[$key] = $after->{$key};
            }
        }

        return $answers;
    }

    /**
     * Asks how macOS builds sign on this machine: a Developer ID identity from the keychain
     * (then the notarytool profile that notarizes its builds) or ad hoc. Saved to the per-user
     * config, never to build.json: the identity belongs to the machine.
     *
     * @param  list<string>  $identities  Developer ID Application identities in the keychain
     */
    public function signing(UserConfig $config, array $identities): void
    {
        $options = ['adhoc' => 'Ad hoc (opens on this Mac only)'];
        foreach ($identities as $identity) {
            $options[$identity] = $identity;
        }

        $sign = (string) select(label: 'How macOS builds sign on this machine', options: $options, default: $identities[0] ?? 'adhoc', hint: 'Saved to ~/.venusian/build/config.json');
        $profile = null;

        if ($sign !== 'adhoc') {
            $answer = trim(text(label: 'notarytool keychain profile', placeholder: 'venusian', hint: 'xcrun notarytool store-credentials <name> creates one; empty signs without notarizing'));
            $profile = $answer === '' ? null : $answer;
        }

        $config->saveMacos($sign, $profile);
    }

    /** Whether the build asks signing(): a terminal, a target that signs, and no answer saved on this machine. */
    public static function asksSigning(bool $interactive, bool $signs, UserConfig $config): bool
    {
        return $interactive && $signs && ! $config->hasMacos();
    }

    /**
     * Developer ID Application identities from `security find-identity -v -p codesigning`.
     *
     * @return list<string>
     */
    public static function identities(string $security_output): array
    {
        preg_match_all('/"(Developer ID Application: [^"]+)"/', $security_output, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function settled(Manifest $manifest): Manifest
    {
        if (is_null($manifest->sketch)) {
            throw new RuntimeException($manifest->sketches === []
                ? 'No sketch under app/Runner/Sketches; a packaged app runs one sketch.'
                : 'Several sketches ('.implode(', ', $manifest->sketches).'): set sketch in build.json, or run with a terminal to choose.');
        }

        return $manifest;
    }
}
