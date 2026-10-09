<?php

namespace Venusian\Build;

use Closure;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\Manifest;
use Venusian\Build\Phar\PharBuilder;
use Venusian\Build\Targets\Target;

/**
 * One build: the phar once, then every target the manifest names that this
 * machine can build. Targets it cannot build here are reported and left to
 * a build elsewhere.
 */
final class Build
{
    /** @var array<string, Target> name => target */
    private readonly array $targets;

    /**
     * @param  list<Target>  $targets  every target this package builds
     * @param  string|null  $host  the target name of this machine; null derives it from the OS and CPU
     */
    public function __construct(
        private readonly PharBuilder $phars,
        array $targets,
        private readonly ?string $host = null,
    ) {
        $this->targets = array_combine(array_map(fn (Target $target): string => $target->name(), $targets), $targets);
    }

    /** macos-arm64, linux-arm64, linux-x86_64; other machines name themselves the same way and have no target. */
    public static function hostName(string $os_family = PHP_OS_FAMILY, ?string $machine = null): string
    {
        $os = match ($os_family) {
            'Darwin' => 'macos',
            'Linux' => 'linux',
            default => strtolower($os_family),
        };
        $arch = match ($machine = strtolower($machine ?? php_uname('m'))) {
            'arm64', 'aarch64' => 'arm64',
            'x86_64', 'amd64' => 'x86_64',
            default => $machine,
        };

        return "{$os}-{$arch}";
    }

    /** @return list<Target> */
    public function targets(Manifest $manifest): array
    {
        $names = $manifest->targets === [] ? [$this->host ?? self::hostName()] : $manifest->targets;
        $known = implode(', ', array_keys($this->targets));

        return array_map(
            fn (string $name): Target => $this->targets[$name] ?? throw new RuntimeException("Target {$name} is not one venusian build knows: {$known}."),
            $names,
        );
    }

    public function signs(Manifest $manifest): bool
    {
        foreach ($this->targets($manifest) as $target) {
            if ($target->available() && $target->signs()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Closure(string): void  $report  one line per step
     * @return list<string> one path per target built
     */
    public function run(string $app_dir, Manifest $manifest, Closure $report): array
    {
        $targets = $this->targets($manifest);
        $buildable = array_values(array_filter($targets, fn (Target $target): bool => $target->available()));

        foreach ($targets as $target) {
            if (! $target->available()) {
                $report("Skipping {$target->name()}: {$target->unavailableReason()}");
            }
        }

        if ($buildable === []) {
            throw new RuntimeException('No target could be built on this machine: '.implode(', ', array_map(
                fn (Target $target): string => "{$target->name()} ({$target->unavailableReason()})", $targets,
            )).'.');
        }

        $files = new Filesystem;
        $work = sys_get_temp_dir().'/venusian-build-'.bin2hex(random_bytes(6));
        $files->mkdir($work);
        $output = rtrim($app_dir, '/').'/build';
        $files->mkdir($output);
        $files->dumpFile("{$output}/.gitignore", "*\n");

        try {
            $report('Packing the phar');
            $phar = "{$work}/{$manifest->kebab()}.phar";
            $this->phars->build($app_dir, $manifest, $phar);

            $outputs = [];
            foreach ($buildable as $target) {
                $outputs[] = $target->build($phar, $manifest, $output, $report);
            }

            return $outputs;
        } finally {
            $files->remove($work);
        }
    }
}
