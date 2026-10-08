<?php

namespace Venusian\Build\Console;

use RuntimeException;
use Venusian\Build\App\Manifest;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * The questions before a build, each defaulted from the manifest so a
 * second run is Enter all the way. Without a terminal the defaults stand.
 */
final class Interview
{
    public function ask(Manifest $manifest, bool $interactive): Manifest
    {
        if (! $interactive) {
            return $this->settled($manifest);
        }

        $name = text(label: 'App name', default: $manifest->name, required: true);
        $derived = $manifest->bundle_id === 'com.venusian.'.$manifest->kebab()
            ? 'com.venusian.'.$manifest->with(['name' => $name])->kebab()
            : $manifest->bundle_id;
        $bundle_id = text(label: 'Bundle identifier', default: $derived, required: true);
        $version = text(label: 'Version', default: $manifest->version, required: true);

        $sketch = $manifest->sketch;
        if (is_null($sketch) && count($manifest->sketches) > 1) {
            $sketch = (string) select(label: 'Sketch the app runs', options: $manifest->sketches);
        }

        $sign = $manifest->sign;
        $choice = select(label: 'Signing', options: ['adhoc' => 'Ad hoc (runs on this Mac)', 'identity' => 'Developer ID identity'], default: $sign === 'adhoc' ? 'adhoc' : 'identity');
        if ($choice === 'identity') {
            $sign = text(label: 'codesign identity', default: $sign === 'adhoc' ? '' : $sign, placeholder: 'Developer ID Application: Name (TEAMID)', required: true);
        } else {
            $sign = 'adhoc';
        }

        return $this->settled($manifest->with([
            'name' => $name,
            'bundle_id' => $bundle_id,
            'version' => $version,
            'sketch' => $sketch,
            'sign' => $sign,
        ]));
    }

    private function settled(Manifest $manifest): Manifest
    {
        if (is_null($manifest->sketch)) {
            throw new RuntimeException($manifest->sketches === []
                ? 'No sketch under app/Runner/Sketches; a packaged app runs one sketch.'
                : 'Several sketches ('.implode(', ', $manifest->sketches).'): set build.sketch in config/build.php, or run with a terminal to choose.');
        }

        return $manifest;
    }
}
