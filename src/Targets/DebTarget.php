<?php

namespace Venusian\Build\Targets;

use Closure;
use PharData;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\Manifest;
use Venusian\Build\Hosts\Docker;
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

    /** php-src's own extensions and the flag that compiles each in; a name outside this list and UNCOMPILED resolves through Packagist. */
    public const CORE = [
        'bcmath' => '--enable-bcmath', 'ctype' => '--enable-ctype', 'curl' => '--with-curl', 'dom' => '--enable-dom', 'fileinfo' => '--enable-fileinfo',
        'filter' => '--enable-filter', 'gmp' => '--with-gmp', 'iconv' => '--with-iconv', 'intl' => '--enable-intl', 'libxml' => '--with-libxml',
        'mbstring' => '--enable-mbstring', 'openssl' => '--with-openssl', 'pcntl' => '--enable-pcntl', 'pdo' => '--enable-pdo',
        'pdo_mysql' => '--with-pdo-mysql', 'pdo_pgsql' => '--with-pdo-pgsql', 'pdo_sqlite' => '--with-pdo-sqlite', 'phar' => '--enable-phar',
        'posix' => '--enable-posix', 'session' => '--enable-session', 'simplexml' => '--enable-simplexml', 'sockets' => '--enable-sockets',
        'sqlite3' => '--with-sqlite3', 'tokenizer' => '--enable-tokenizer', 'xml' => '--enable-xml', 'xmlreader' => '--enable-xmlreader',
        'xmlwriter' => '--enable-xmlwriter', 'zip' => '--with-zip', 'zlib' => '--with-zlib',
        'bz2' => '--with-bz2', 'calendar' => '--enable-calendar', 'exif' => '--enable-exif', 'ffi' => '--with-ffi', 'ftp' => '--enable-ftp',
        'gd' => '--enable-gd', 'gettext' => '--with-gettext', 'ldap' => '--with-ldap', 'mysqli' => '--with-mysqli', 'mysqlnd' => '--enable-mysqlnd',
        'pgsql' => '--with-pgsql', 'readline' => '--with-libedit', 'shmop' => '--enable-shmop', 'soap' => '--enable-soap',
        'sodium' => '--with-sodium', 'sysvmsg' => '--enable-sysvmsg', 'sysvsem' => '--enable-sysvsem', 'sysvshm' => '--enable-sysvshm',
        'tidy' => '--with-tidy', 'xsl' => '--with-xsl',
    ];

    /** php-src's own extensions this build does not compile in: their libraries are not in the image, or (opcache) PHP 8.4 builds it only as a zend_extension the SAPI never loads. */
    private const UNCOMPILED = ['dba', 'dl_test', 'enchant', 'odbc', 'opcache', 'pdo_dblib', 'pdo_firebird', 'pdo_odbc', 'snmp', 'zend_test'];

    /** Always in PHP; never named to configure. */
    private const ALWAYS = ['core', 'date', 'hash', 'json', 'pcre', 'random', 'reflection', 'spl', 'standard'];

    /** What the framework requires of every app, and the loop backend and HTTP on the loop. */
    private const BASE = ['ctype', 'filter', 'mbstring', 'openssl', 'pdo', 'epoll', 'pcurl'];

    /** An extension that pulls another in: declared by PHP_ADD_EXTENSION_DEP in its config.m4. */
    private const NEEDS = [
        'dom' => ['libxml'], 'simplexml' => ['libxml'], 'xml' => ['libxml'], 'xmlreader' => ['libxml'], 'xmlwriter' => ['libxml'],
        'pdo_mysql' => ['pdo', 'mysqlnd'], 'pdo_pgsql' => ['pdo'], 'pdo_sqlite' => ['pdo'], 'pcurl' => ['curl'], 'epoll' => ['sockets'],
        'mysqli' => ['mysqlnd'], 'soap' => ['libxml'], 'xsl' => ['libxml', 'dom'],
    ];

    private const VENDOR = 'php-io-extensions';

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
        [$names, $args, $packages] = $this->resolve($manifest, $report);
        $php = $this->sources->phpSrc();
        $sapi = $this->sources->sapi();
        $hash = sha1(implode("\n", [$this->image(), $php['version'], $sapi['version'], $manifest->zts ? 'zts' : 'nts', ...$args, ...array_map(fn (array $p): string => "{$p['name']}@{$p['version']}", $packages)]));

        $report("Compiling PHP {$php['version']} ".($manifest->zts ? 'ZTS' : 'NTS')." with the Venusian SAPI {$sapi['version']} and ".implode(', ', $names));

        $this->ensureImage($context, $report);

        $files = new Filesystem;
        $stage = sys_get_temp_dir().'/venusian-deb-'.bin2hex(random_bytes(6));
        $files->mkdir(["{$stage}/in/ext", "{$stage}/in/deb"]);

        try {
            $files->copy($this->env_dir.'/recipe.sh', "{$stage}/recipe.sh");
            $files->copy($this->env_dir.'/package.sh', "{$stage}/package.sh");
            $files->copy($php['path'], "{$stage}/in/php-src.tar.xz");
            $files->copy($sapi['path'], "{$stage}/in/sapi.tar.gz");
            foreach ($packages as $package) {
                $files->copy($package['path'], "{$stage}/in/ext/{$package['name']}.zip");
            }
            file_put_contents("{$stage}/in/php.version", $php['version']);
            file_put_contents("{$stage}/in/set.hash", $hash);
            file_put_contents("{$stage}/in/configure.args", implode("\n", $args)."\n");
            file_put_contents("{$stage}/in/extensions.list", implode('', array_map(fn (array $p): string => "{$p['name']}\t{$p['build_path']}\n", $packages)));
            $this->writeDeb("{$stage}/in/deb", $manifest, $phar, $icon);

            $tar = "{$stage}.tar";
            (new PharData($tar))->buildFromDirectory($stage);
            $volume = 'venusian-build-'.$manifest->kebab();

            // The previous build's inputs go first: tar only adds, and a dropped icon or desktop entry must not ride along.
            $this->docker->run($context, $this->image(), $volume, ['rm', '-rf', 'in', 'out', 'pkg']);
            $this->docker->push($context, $this->image(), $volume, $tar);
            $report(trim($this->docker->run($context, $this->image(), $volume, ['sh', 'recipe.sh'])) ?: 'Compiled');
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

    /**
     * The extension set: the base, the app's, and what those need; core ones as flags, the rest from Packagist.
     * A Packagist extension whose php-ext metadata leaves Linux out (appkit, kqueue) is left out and reported.
     *
     * @param  Closure(string): void  $report
     * @return array{0: list<string>, 1: list<string>, 2: list<array{name: string, version: string, path: string, build_path: string}>}
     */
    private function resolve(Manifest $manifest, Closure $report): array
    {
        $names = [];
        $queue = [...self::BASE, ...array_map('strtolower', $manifest->extensions)];

        while ($queue !== []) {
            $name = array_shift($queue);
            if (in_array($name, self::ALWAYS, true) || in_array($name, $names, true)) {
                continue;
            }
            $names[] = $name;
            array_push($queue, ...(self::NEEDS[$name] ?? []));
        }

        $args = ['--disable-all', '--disable-cli', '--disable-cgi', '--disable-phpdbg', '--enable-venusian', '--enable-phar'];
        if ($manifest->zts) {
            $args[] = '--enable-zts';
        }
        $packages = [];

        $built = [];

        foreach ($names as $name) {
            if (in_array($name, self::UNCOMPILED, true)) {
                throw new RuntimeException("{$name} ships with php-src, but venusian build does not compile it in yet; ask for it on VenusianPHP/build.");
            }
            if (isset(self::CORE[$name])) {
                $built[] = $name;
                $args[] = self::CORE[$name];
                continue;
            }
            $package = $this->sources->extension($name);
            $families = $package['os_families'];
            if (in_array('linux', $package['os_families_exclude'], true)) {
                $report("Leaving out {$name}: ".self::VENDOR."/{$name} {$package['version']} does not build on linux");
                continue;
            }
            if ($families !== [] && ! in_array('linux', $families, true)) {
                $report("Leaving out {$name}: ".self::VENDOR."/{$name} {$package['version']} builds on ".implode(', ', $families).' only');
                continue;
            }
            $built[] = $name;
            $packages[] = ['name' => $name, ...$package];
            $args[] = $package['configure'];
        }

        return [$built, $args, $packages];
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

    /** @param  array{path: string, size: int}|null  $icon */
    private function writeDeb(string $dir, Manifest $manifest, string $phar, ?array $icon): void
    {
        $files = new Filesystem;
        $kebab = $manifest->kebab();
        $arch = $this->debianArch();

        $files->copy($phar, "{$dir}/app.phar");
        file_put_contents("{$dir}/kebab", $kebab);
        file_put_contents("{$dir}/id", $manifest->id);
        file_put_contents("{$dir}/arch", $arch);
        file_put_contents("{$dir}/version", $manifest->version);

        $description = $manifest->summary !== '' ? $manifest->summary : $manifest->name;
        $control = ["Package: {$kebab}", "Version: {$manifest->version}", "Architecture: {$arch}", "Maintainer: {$manifest->author}", 'Section: misc', 'Priority: optional'];
        if ($manifest->homepage !== '') {
            $control[] = "Homepage: {$manifest->homepage}";
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
