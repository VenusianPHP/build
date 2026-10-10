<?php

namespace Venusian\Build\Targets;

use Closure;
use PharData;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\Manifest;
use Venusian\Build\Extensions\ExtensionSet;
use Venusian\Build\Hosts\Docker;
use Venusian\Build\Phar\PharBuilder;
use Venusian\Build\Sources\Sources;

/**
 * linux-arm64 and linux-x86_64 as a .deb: PHP compiled in the Ubuntu 24.04
 * build image with the Venusian SAPI and the app's extensions compiled in,
 * linked to the distribution's libraries, packaged with generated Depends.
 *
 * The compile runs on a docker context: the local engine when its CPU
 * matches, else the context the per-user config names for the target.
 * Inputs go into the app's volume as one tar; the compiled runtime is
 * reused while the extension set is the same; the .deb comes back as a file.
 */
final class DebTarget implements Target
{
    /** The image's name; image() adds the Dockerfile's hash, so a changed Dockerfile is a new image on every host. */
    public const IMAGE = 'ghcr.io/venusianphp/build-env:ubuntu24.04';

    /**
     * @param  string  $arch  arm64 or x86_64
     * @param  array<string, string>  $hosts  target => docker context, from the per-user config
     */
    public function __construct(
        private readonly string $arch,
        private readonly Sources $sources,
        private readonly Docker $docker,
        private readonly array $hosts,
        private readonly string $env_dir = __DIR__.'/../../build-env',
    ) {}

    public function name(): string
    {
        return "linux-{$this->arch}";
    }

    public function signs(): bool
    {
        return false;
    }

    public function available(): bool
    {
        return $this->context() !== null;
    }

    public function unavailableReason(): string
    {
        $named = $this->hosts[$this->name()] ?? null;

        if ($named !== null) {
            try {
                return "docker context {$named} is {$this->docker->arch($named)}, not {$this->arch}";
            } catch (RuntimeException $e) {
                return $e->getMessage();
            }
        }

        try {
            $this->docker->arch($this->docker->current());
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return "no docker host for {$this->name()}: name a context under hosts in ~/.venusian/build/config.json, or run on an {$this->arch} machine with docker";
    }

    public function build(string $phar, Manifest $manifest, string $output_dir, Closure $report): string
    {
        $context = $this->context() ?? throw new RuntimeException($this->unavailableReason());
        $report("Building {$this->name()} on docker context {$context}");

        if ($manifest->author === '') {
            throw new RuntimeException('A .deb needs a maintainer: set author in build.json as "Name <email>".');
        }

        $icon = $this->icon($manifest);
        [$names, $args, $packages] = (new ExtensionSet($this->sources, 'linux'))->resolve($manifest->extensions, $manifest->zts, $report, $manifest->uses);
        $php = $this->sources->phpSrc();
        $sapi = $this->sources->sapi();
        $hash = sha1(implode("\n", [$this->image(), $php['version'], $sapi['version'], $manifest->zts ? 'zts' : 'nts', ...$args, ...array_map(fn (array $p): string => "{$p['name']}@{$p['version']}#{$p['reference']}", $packages)]));

        $report("Compiling PHP {$php['version']} ".($manifest->zts ? 'ZTS' : 'NTS')." with the Venusian SAPI {$sapi['version']} and ".implode(', ', $names));
        if ($packages !== []) {
            $report('From Packagist: '.implode(', ', array_map(fn (array $p): string => $p['name'].(str_starts_with($p['package'], 'php-io-extensions/') ? '' : " ({$p['package']})")." {$p['version']} (".substr($p['reference'], 0, 7).')', $packages)));
        }

        $this->ensureImage($context, $report);

        $files = new Filesystem;
        $stage = sys_get_temp_dir().'/venusian-deb-'.bin2hex(random_bytes(6));
        $files->mkdir(["{$stage}/in/ext", "{$stage}/in/deb"]);

        try {
            $files->copy($this->env_dir.'/recipe.sh', "{$stage}/recipe.sh");
            $files->copy($this->env_dir.'/package.sh', "{$stage}/package.sh");
            $files->copy($this->env_dir.'/apt-build.sh', "{$stage}/apt-build.sh");
            $files->copy($php['path'], "{$stage}/in/php-src.tar.xz");
            $files->copy($sapi['path'], "{$stage}/in/sapi.tar.gz");
            foreach ($packages as $package) {
                $files->copy($package['path'], "{$stage}/in/ext/{$package['name']}.zip");
            }
            file_put_contents("{$stage}/in/php.version", $php['version']);
            file_put_contents("{$stage}/in/set.hash", $hash);
            file_put_contents("{$stage}/in/configure.args", implode("\n", $args)."\n");
            file_put_contents("{$stage}/in/extensions.list", implode('', array_map(fn (array $p): string => "{$p['name']}\t{$p['build_path']}\n", $packages)));
            $apt = fn (string $key): array => array_values(array_unique(array_merge([], ...array_map(fn (array $p): array => $p[$key], $packages))));
            $build = $apt('apt_build');
            sort($build);
            file_put_contents("{$stage}/in/apt.build", implode('', array_map(fn (string $p): string => "{$p}\n", $build)));
            $this->writeDeb("{$stage}/in/deb", $manifest, $phar, $icon, $apt('apt_depends'), $apt('apt_recommends'));

            $tar = "{$stage}.tar";
            (new PharData($tar))->buildFromDirectory($stage);
            $volume = 'venusian-build-'.$manifest->kebab();

            // The previous build's inputs go first: tar only adds, and a dropped icon or desktop entry must not ride along.
            $this->docker->run($context, $this->image(), $volume, ['rm', '-rf', 'in', 'out', 'pkg']);
            $this->docker->push($context, $this->image(), $volume, $tar);
            $report(trim($this->docker->run($context, $this->image(), $volume, ['sh', 'recipe.sh'])) ?: 'Compiled');
            $report('Starting it once to check it boots');
            // The binary runs the <binary>.phar beside it, as installed: both go to a scratch directory, never into out/, which package.sh ships.
            try {
                $this->docker->run($context, $this->image(), $volume, ['sh', '-c', 'mkdir -p /tmp/boot/bin && cp out/venusian /tmp/boot/bin/venusian && cp in/deb/app.phar /tmp/boot/bin/venusian.phar && HOME=/tmp/boot XDG_DATA_HOME=/tmp/boot/.local/share /tmp/boot/bin/venusian phar:///tmp/boot/bin/venusian.phar/'.PharBuilder::BOOT_CHECK]);
            } catch (RuntimeException $e) {
                throw new RuntimeException(BootCheck::failure($manifest->name, $e->getMessage()), 0, $e);
            }
            $deb = "out/{$manifest->kebab()}_{$manifest->version}_{$this->debianArch()}.deb";
            $report('Packaging '.basename($deb));
            $this->docker->run($context, $this->image(), $volume, ['sh', 'package.sh']);

            $out = rtrim($output_dir, '/').'/'.basename($deb);
            $this->docker->fetch($context, $this->image(), $volume, $deb, $out);

            return $out;
        } finally {
            $files->remove([$stage, "{$stage}.tar"]);
        }
    }

    /** The build image's tag: IMAGE and the first 12 hex of the Dockerfile's sha1, as the build-env workflow tags it. */
    public function image(): string
    {
        return self::IMAGE.'-'.substr((string) sha1_file($this->env_dir.'/Dockerfile'), 0, 12);
    }

    /** Debian's name for the architecture. */
    private function debianArch(): string
    {
        return $this->arch === 'arm64' ? 'arm64' : 'amd64';
    }

    /** The context that builds this target: the named one when its CPU matches, else the current context (the local engine) when its CPU does. */
    private function context(): ?string
    {
        $named = $this->hosts[$this->name()] ?? null;

        try {
            if ($named !== null) {
                return $this->docker->arch($named) === $this->arch ? $named : null;
            }

            $local = $this->docker->current();

            return $this->docker->arch($local) === $this->arch ? $local : null;
        } catch (RuntimeException) {
            return null;
        }
    }

    private function ensureImage(string $context, Closure $report): void
    {
        if ($this->docker->hasImage($context, $this->image()) || $this->docker->pull($context, $this->image())) {
            return;
        }

        $report('Building the build image from build-env/ (the registry has none); this happens once per host');
        $this->docker->buildImage($context, $this->image(), $this->env_dir);
    }

    /** @return array{path: string, size: int}|null */
    private function icon(Manifest $manifest): ?array
    {
        if ($manifest->icon === null || $manifest->icon === '' || ! $manifest->windowed) {
            return null;
        }

        $path = str_starts_with($manifest->icon, '/') ? $manifest->icon : $manifest->base_path.'/'.$manifest->icon;
        $size = @getimagesize($path);

        if ($size === false) {
            throw new RuntimeException("The icon {$manifest->icon} is not an image venusian build can read (looked at {$path}).");
        }
        // package.sh renders every hicolor size up to 512 px that the source covers.
        if ($size[2] !== IMAGETYPE_PNG || $size[0] !== $size[1] || $size[0] < 16) {
            throw new RuntimeException("{$manifest->icon} is a {$size[0]}x{$size[1]} ".strtoupper(image_type_to_extension($size[2], false)).'; the icon must be a square PNG of at least 16 px.');
        }

        return ['path' => $path, 'size' => $size[0]];
    }

    /**
     * @param  array{path: string, size: int}|null  $icon
     * @param  list<string>  $depends  run-time packages the extensions declare, beside what dpkg-shlibdeps finds
     * @param  list<string>  $recommends
     */
    private function writeDeb(string $dir, Manifest $manifest, string $phar, ?array $icon, array $depends, array $recommends): void
    {
        $files = new Filesystem;
        $kebab = $manifest->kebab();
        $arch = $this->debianArch();

        $files->copy($phar, "{$dir}/app.phar");
        file_put_contents("{$dir}/kebab", $kebab);
        file_put_contents("{$dir}/id", $manifest->id);
        file_put_contents("{$dir}/arch", $arch);
        file_put_contents("{$dir}/version", $manifest->version);
        file_put_contents("{$dir}/depends", implode(', ', $depends));

        $description = $manifest->summary !== '' ? $manifest->summary : $manifest->name;
        $control = ["Package: {$kebab}", "Version: {$manifest->version}", "Architecture: {$arch}", "Maintainer: {$manifest->author}", 'Section: misc', 'Priority: optional'];
        if ($manifest->homepage !== '') {
            $control[] = "Homepage: {$manifest->homepage}";
        }
        if ($recommends !== []) {
            $control[] = 'Recommends: '.implode(', ', $recommends);
        }
        $control[] = "Description: {$description}";
        foreach (explode("\n", $manifest->description) as $line) {
            if ($manifest->description === '') {
                break;
            }
            $control[] = trim($line) === '' ? ' .' : ' '.$line;
        }
        file_put_contents("{$dir}/control", implode("\n", $control)."\n");

        $year = date('Y');
        file_put_contents("{$dir}/copyright", "Format: https://www.debian.org/doc/packaging-manuals/copyright-format/1.0/\nUpstream-Name: {$manifest->name}\n".($manifest->homepage !== '' ? "Source: {$manifest->homepage}\n" : '')."\nFiles: *\nCopyright: {$year} {$manifest->author}\nLicense: ".($manifest->license !== '' ? $manifest->license : 'proprietary')."\n");

        if (! $manifest->windowed) {
            return;
        }

        $desktop = ['[Desktop Entry]', 'Type=Application', "Name={$manifest->name}", "Comment={$description}", "Exec=/usr/bin/{$kebab}"];
        if ($icon !== null) {
            $desktop[] = "Icon={$manifest->id}";
        }
        array_push($desktop, 'Terminal=false', "Categories={$manifest->category};", 'StartupNotify=true', "StartupWMClass={$manifest->id}");
        file_put_contents("{$dir}/desktop", implode("\n", $desktop)."\n");

        $h = fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $homepage = $manifest->homepage !== '' ? "\n  <url type=\"homepage\">{$h($manifest->homepage)}</url>" : '';
        $paragraphs = implode('', array_map(fn (string $p): string => '    <p>'.$h(trim($p)).'</p>'."\n", array_filter(preg_split('/\n\s*\n/', $manifest->description) ?: [], fn (string $p): bool => trim($p) !== '')));
        file_put_contents("{$dir}/metainfo.xml", <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <component type="desktop-application">
          <id>{$h($manifest->id)}</id>
          <name>{$h($manifest->name)}</name>
          <summary>{$h($description)}</summary>
          <metadata_license>CC0-1.0</metadata_license>
          <project_license>{$h($manifest->license !== '' ? $manifest->license : 'LicenseRef-proprietary')}</project_license>
          <developer id="{$h($manifest->id)}"><name>{$h(trim((string) preg_replace('/\s*<[^>]*>\s*$/', '', $manifest->author)))}</name></developer>
          <description>
        {$paragraphs}  </description>
          <launchable type="desktop-id">{$h($manifest->id)}.desktop</launchable>{$homepage}
          <categories><category>{$h($manifest->category)}</category></categories>
        </component>

        XML);

        if ($icon !== null) {
            $files->copy($icon['path'], "{$dir}/icon.png");
            file_put_contents("{$dir}/icon.size", (string) $icon['size']);
        }
    }
}
