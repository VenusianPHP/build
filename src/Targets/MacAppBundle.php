<?php

namespace Venusian\Build\Targets;

use Closure;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\Manifest;
use Venusian\Build\Runtime\MacLibraries;

/**
 * <Name>.app/Contents/{Info.plist, MacOS/<kebab>, Resources/<kebab>.phar,
 * Resources/AppIcon.icns, Frameworks/*.dylib, Resources/vulkan/icd.d/MoltenVK_icd.json}
 *
 * The binary is the Venusian SAPI, which finds its phar at
 * ../Resources/<kebab>.phar. Each library it links through @rpath (its
 * rpath is @executable_path/../Frameworks) comes from the library set into
 * Frameworks; the Vulkan loader brings MoltenVK, its driver, with an ICD
 * manifest the loader finds in the bundle's Resources.
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

    /** freedesktop main category => LSApplicationCategoryType */
    public const CATEGORIES = [
        'AudioVideo' => 'public.app-category.entertainment',
        'Development' => 'public.app-category.developer-tools',
        'Education' => 'public.app-category.education',
        'Game' => 'public.app-category.games',
        'Graphics' => 'public.app-category.graphics-design',
        'Network' => 'public.app-category.social-networking',
        'Office' => 'public.app-category.productivity',
        'Science' => 'public.app-category.education',
        'Settings' => 'public.app-category.utilities',
        'System' => 'public.app-category.utilities',
        'Utility' => 'public.app-category.utilities',
    ];

    /** build.json permission => [Info.plist usage key, hardened-runtime entitlement or null] */
    public const PERMISSIONS = [
        'camera' => ['NSCameraUsageDescription', 'com.apple.security.device.camera'],
        'microphone' => ['NSMicrophoneUsageDescription', 'com.apple.security.device.audio-input'],
        'bluetooth' => ['NSBluetoothAlwaysUsageDescription', null],
    ];

    /** @param  Closure(list<string>, ?string): string  $run  runs a command, returning its output, throwing when it fails */
    public function __construct(
        private readonly Filesystem $files,
        private readonly Closure $run,
    ) {}

    /** @return string path to the .app */
    public function write(string $output_dir, Manifest $manifest, string $binary, string $phar, string $prefix): string
    {
        $kebab = $manifest->kebab();
        $app = rtrim($output_dir, '/').'/'.$manifest->name.'.app';
        $contents = $app.'/Contents';

        $this->files->remove($app);
        $this->files->mkdir([$contents.'/MacOS', $contents.'/Resources']);

        $this->files->copy($binary, "{$contents}/MacOS/{$kebab}", true);
        $this->files->chmod("{$contents}/MacOS/{$kebab}", 0755);
        $this->files->copy($phar, "{$contents}/Resources/{$kebab}.phar", true);
        $this->frameworks($binary, $prefix, $contents);

        $icon = $this->icon($manifest, $app);
        $this->files->dumpFile($contents.'/Info.plist', self::infoPlist($manifest, $icon));

        return $app;
    }

    /**
     * The libraries a binary links through @rpath, by file name, from `otool -L` output.
     *
     * @return list<string>
     */
    public static function rpathLibraries(string $otool_output): array
    {
        preg_match_all('#^\s+@rpath/(\S+)#m', $otool_output, $matches);

        return $matches[1];
    }

    public static function infoPlist(Manifest $manifest, bool $icon): string
    {
        $h = fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $strings = [
            'CFBundleName' => $manifest->name,
            'CFBundleDisplayName' => $manifest->name,
            'CFBundleIdentifier' => $manifest->id,
            'CFBundleVersion' => (string) $manifest->build,
            'CFBundleShortVersionString' => $manifest->version,
            'CFBundleExecutable' => $manifest->kebab(),
            'CFBundlePackageType' => 'APPL',
            'CFBundleInfoDictionaryVersion' => '6.0',
            'LSMinimumSystemVersion' => MacLibraries::FLOOR,
            'LSApplicationCategoryType' => self::CATEGORIES[$manifest->category],
        ];
        if ($icon) {
            $strings['CFBundleIconFile'] = 'AppIcon';
        }
        foreach ($manifest->permissions as $permission => $why) {
            $strings[self::PERMISSIONS[$permission][0]] = $why;
        }

        $entries = '';
        foreach ($strings as $key => $value) {
            $entries .= "    <key>{$key}</key>\n    <string>{$h($value)}</string>\n";
        }

        return <<<PLIST
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0">
        <dict>
        {$entries}    <key>NSHighResolutionCapable</key>
            <true/>
        </dict>
        </plist>

        PLIST;
    }

    private function frameworks(string $binary, string $prefix, string $contents): void
    {
        $libraries = self::rpathLibraries(($this->run)(['otool', '-L', $binary], null));

        foreach ($libraries as $library) {
            $source = "{$prefix}/lib/{$library}";
            if (! is_file($source)) {
                throw new RuntimeException("The binary links @rpath/{$library}, which the library set at {$prefix} does not have.");
            }
            $this->files->copy($source, "{$contents}/Frameworks/{$library}", true);
        }

        if (array_filter($libraries, fn (string $library): bool => str_starts_with($library, 'libvulkan')) === []) {
            return;
        }

        // The loader dlopens its driver by the manifest's path, relative to the manifest.
        $this->files->copy("{$prefix}/lib/libMoltenVK.dylib", "{$contents}/Frameworks/libMoltenVK.dylib", true);
        $icd = json_decode((string) file_get_contents("{$prefix}/share/vulkan/icd.d/MoltenVK_icd.json"), true, flags: JSON_THROW_ON_ERROR);
        $icd['ICD']['library_path'] = '../../../Frameworks/libMoltenVK.dylib';
        $this->files->dumpFile("{$contents}/Resources/vulkan/icd.d/MoltenVK_icd.json", json_encode($icd, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
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
