<?php

namespace Venusian\Build\Targets;

use Closure;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\Manifest;

/**
 * <Name>.app/Contents/{Info.plist, MacOS/<kebab>, MacOS/<kebab>-bin, MacOS/lib/*.so, Resources/AppIcon.icns}
 *
 * The launcher enters its own directory before exec: the embedded
 * extension_dir=lib resolves against the working directory.
 */
final class MacAppBundle
{
    /** iconset members macOS expects: [file name, pixel size] */
    private const ICONSET = [
        ['icon_16x16.png', 16], ['icon_16x16@2x.png', 32],
        ['icon_32x32.png', 32], ['icon_32x32@2x.png', 64],
        ['icon_128x128.png', 128], ['icon_128x128@2x.png', 256],
        ['icon_256x256.png', 256], ['icon_256x256@2x.png', 512],
        ['icon_512x512.png', 512], ['icon_512x512@2x.png', 1024],
    ];

    /**
     * @param  Closure(list<string>, ?string): void  $run  runs a command, throwing when it fails
     */
    public function __construct(
        private readonly Filesystem $files,
        private readonly Closure $run,
    ) {}

    /**
     * @param  array<string, string>  $extensions  name => .so path
     * @return string path to the .app
     */
    public function write(string $output_dir, Manifest $manifest, string $binary, array $extensions): string
    {
        $kebab = $manifest->kebab();
        $app = $output_dir.'/'.$manifest->name.'.app';
        $macos = $app.'/Contents/MacOS';

        $this->files->remove($app);
        $this->files->mkdir([$macos.'/lib', $app.'/Contents/Resources']);

        $this->files->copy($binary, $macos.'/'.$kebab.'-bin', true);
        $this->files->chmod($macos.'/'.$kebab.'-bin', 0755);

        $this->files->dumpFile($macos.'/'.$kebab, self::launcher($kebab));
        $this->files->chmod($macos.'/'.$kebab, 0755);

        foreach ($extensions as $path) {
            $this->files->copy($path, $macos.'/lib/'.basename($path), true);
        }

        $icon = $this->icon($manifest, $app);
        $this->files->dumpFile($app.'/Contents/Info.plist', self::infoPlist($manifest, $icon));

        return $app;
    }

    public static function launcher(string $kebab): string
    {
        return "#!/bin/sh\ncd \"$(dirname \"$0\")\" && exec ./{$kebab}-bin \"$@\"\n";
    }

    public static function infoPlist(Manifest $manifest, bool $icon): string
    {
        $h = fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $kebab = $manifest->kebab();
        $iconLine = $icon ? "    <key>CFBundleIconFile</key>\n    <string>AppIcon</string>\n" : '';

        return <<<PLIST
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0">
        <dict>
            <key>CFBundleName</key>
            <string>{$h($manifest->name)}</string>
            <key>CFBundleDisplayName</key>
            <string>{$h($manifest->name)}</string>
            <key>CFBundleIdentifier</key>
            <string>{$h($manifest->bundle_id)}</string>
            <key>CFBundleVersion</key>
            <string>{$h($manifest->version)}</string>
            <key>CFBundleShortVersionString</key>
            <string>{$h($manifest->version)}</string>
            <key>CFBundleExecutable</key>
            <string>{$kebab}</string>
            <key>CFBundlePackageType</key>
            <string>APPL</string>
            <key>CFBundleInfoDictionaryVersion</key>
            <string>6.0</string>
            <key>LSMinimumSystemVersion</key>
            <string>12.0</string>
            <key>NSHighResolutionCapable</key>
            <true/>
        {$iconLine}</dict>
        </plist>

        PLIST;
    }

    /** Renders Resources/AppIcon.icns from the configured PNG; true when one was written. */
    private function icon(Manifest $manifest, string $app): bool
    {
        if (is_null($manifest->icon) || $manifest->icon === '') {
            return false;
        }

        $png = str_starts_with($manifest->icon, '/') ? $manifest->icon : $manifest->base_path.'/'.$manifest->icon;

        if (! is_file($png)) {
            throw new RuntimeException("The icon {$manifest->icon} is not there (looked at {$png}).");
        }

        $iconset = $app.'/Contents/Resources/AppIcon.iconset';
        $this->files->mkdir($iconset);

        foreach (self::ICONSET as [$name, $size]) {
            ($this->run)(['sips', '-z', (string) $size, (string) $size, $png, '--out', $iconset.'/'.$name], null);
        }

        ($this->run)(['iconutil', '-c', 'icns', $iconset, '-o', $app.'/Contents/Resources/AppIcon.icns'], null);
        $this->files->remove($iconset);

        return true;
    }
}
