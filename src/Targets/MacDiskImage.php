<?php

namespace Venusian\Build\Targets;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\Manifest;

/**
 * The .dmg a Mac user downloads: the .app beside an Applications link,
 * compressed. With a Developer ID the image is signed, sent to Apple's notary
 * service, stapled, and assessed by Gatekeeper as a downloaded file is.
 */
final class MacDiskImage
{
    /** @param  Closure(list<string>, ?string): array{0: int, 1: string, 2: string}  $exec  runs a command: exit code, stdout, stderr; never throws */
    public function __construct(
        private readonly Filesystem $files,
        private readonly Closure $exec,
    ) {}

    /**
     * hdiutil's own -srcfolder copy sizes its image from an estimate and failed 5 runs in 6 on
     * Stargazer's 46 MB phar ("No space left on device"); a read-write image of a size this picks,
     * mounted where this says, then compressed, does not.
     *
     * @return string the .dmg
     */
    public function create(string $app, Manifest $manifest, string $output_dir): string
    {
        $output_dir = rtrim($output_dir, '/');
        $dmg = "{$output_dir}/{$manifest->kebab()}-{$manifest->version}-macos-arm64.dmg";
        $work = "{$output_dir}/.dmg-{$manifest->kebab()}";
        $image = "{$work}/rw.dmg";
        $mount = self::mountPoint($work);
        if (is_dir($mount)) {
            // A run killed while the image was attached left it mounted here.
            ($this->exec)(['hdiutil', 'detach', '-force', $mount], null);
        }
        $this->files->remove([$work, $mount, $dmg]);
        $this->files->mkdir([$work, $mount]);

        try {
            $megabytes = (int) ceil(self::megabytes($app) * 1.25) + 16;
            $this->must(['hdiutil', 'create', '-size', "{$megabytes}m", '-fs', 'HFS+', '-volname', $manifest->name, '-ov', $image]);
            $this->must(['hdiutil', 'attach', $image, '-nobrowse', '-noautoopen', '-mountpoint', $mount]);

            try {
                // ditto keeps the signature's extended attributes and symlinks as they are.
                $this->must(['ditto', $app, $mount.'/'.basename($app)]);
                $this->files->symlink('/Applications', $mount.'/Applications');
            } catch (RuntimeException $copying) {
                $this->detach($mount);

                throw $copying;
            }

            $this->detach($mount, strict: true);

            $this->must(['hdiutil', 'convert', $image, '-format', 'UDZO', '-ov', '-o', $dmg]);
        } finally {
            $this->files->remove($work);
            // Empty once detached; still mounted after a failed detach, which the next run forces.
            @rmdir($mount);
        }

        return $dmg;
    }

    /**
     * Where the image mounts: the boot volume's temp dir, named by the work dir so a mount a killed
     * run left is found again. hdiutil refuses a mount point on a volume with owners off, as an
     * external APFS or HFS+ disk mounts by default ("attach failed - Permission denied").
     */
    public static function mountPoint(string $work): string
    {
        return rtrim(sys_get_temp_dir(), '/').'/venusian-dmg-'.substr(sha1($work), 0, 12);
    }

    public function sign(string $dmg, string $identity): void
    {
        $this->must(['codesign', '--force', '--timestamp', '--sign', $identity, $dmg]);
    }

    /** Submits and waits; staples and assesses an accepted image; throws with Apple's issues otherwise. */
    public function notarize(string $dmg, string $profile): void
    {
        [, $out, $err] = ($this->exec)(['xcrun', 'notarytool', 'submit', $dmg, '--keychain-profile', $profile, '--wait', '--output-format', 'json'], null);
        $result = json_decode(trim($out), true);

        if (! is_array($result) || ! isset($result['status'])) {
            throw new RuntimeException('notarytool gave no result for '.basename($dmg).': '.trim($out.' '.$err));
        }

        if ($result['status'] !== 'Accepted') {
            [, $log] = ($this->exec)(['xcrun', 'notarytool', 'log', (string) ($result['id'] ?? ''), '--keychain-profile', $profile], null);
            $issues = array_map(
                fn (array $issue): string => ($issue['path'] ?? '').': '.($issue['message'] ?? ''),
                (array) ((json_decode($log, true) ?? [])['issues'] ?? []),
            );

            throw new RuntimeException('Apple did not notarize '.basename($dmg)." ({$result['status']})".($issues === [] ? '.' : ":\n".implode("\n", $issues)));
        }

        $this->must(['xcrun', 'stapler', 'staple', $dmg]);
        $this->must(['spctl', '--assess', '--type', 'open', '--context', 'context:primary-signature', '--verbose', $dmg]);
    }

    /** Detaches, forcing when asked nicely fails; strict, a mount that stays attached throws. */
    private function detach(string $mount, bool $strict = false): void
    {
        [$code] = ($this->exec)(['hdiutil', 'detach', $mount], null);
        if ($code === 0) {
            return;
        }

        [$code, $out, $err] = ($this->exec)(['hdiutil', 'detach', '-force', $mount], null);
        if ($code !== 0 && $strict) {
            throw new RuntimeException("hdiutil detach -force {$mount} failed:\n".trim($err !== '' ? $err : $out));
        }
    }

    /** The size of a directory's files, in MB. */
    private static function megabytes(string $path): float
    {
        $bytes = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
            $bytes += $file->isLink() ? 0 : $file->getSize();
        }

        return $bytes / 1_048_576;
    }

    /** @param  list<string>  $command */
    private function must(array $command): string
    {
        [$code, $out, $err] = ($this->exec)($command, null);

        if ($code !== 0) {
            throw new RuntimeException(implode(' ', $command)." failed:\n".trim($err !== '' ? $err : $out));
        }

        return $out;
    }
}
