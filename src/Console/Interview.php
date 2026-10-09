<?php

namespace Venusian\Build\Console;

use RuntimeException;
use Venusian\Build\App\BuildJson;
use Venusian\Build\App\Manifest;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * The questions before a build, each defaulted from the manifest so a
 * second run is Enter all the way. Without a terminal the defaults stand.
 * The id is never asked: config/app.php owns it.
 */
final class Interview
{
    /** @param  bool  $signs  whether a target being built is signed, so the signing question applies */
    public function ask(Manifest $manifest, bool $interactive, bool $signs = true): Manifest
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

        $sign = $manifest->sign;
        if ($signs) {
            $choice = select(label: 'Signing', options: ['adhoc' => 'Ad hoc (runs on this Mac)', 'identity' => 'Developer ID identity'], default: $sign === 'adhoc' ? 'adhoc' : 'identity');
            $sign = $choice === 'identity'
                ? text(label: 'codesign identity', default: $sign === 'adhoc' ? '' : $sign, placeholder: 'Developer ID Application: Name (TEAMID)', required: true)
                : 'adhoc';
        }

        return $this->settled($manifest->with([
            'name' => $name,
            'version' => $version,
            'sketch' => $sketch,
            'author' => $author,
            'summary' => $summary,
            'sign' => $sign,
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

        foreach (['name', 'version', 'sketch', 'author', 'summary', 'sign'] as $key) {
            if ($before->{$key} !== $after->{$key}) {
                $answers[$key] = $after->{$key};
            }
        }

        return $answers;
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
