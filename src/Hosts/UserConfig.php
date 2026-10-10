<?php

namespace Venusian\Build\Hosts;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\App\BuildJson;

/**
 * ~/.venusian/build/config.json: facts about this machine, never about the
 * app. hosts maps a target to the docker context that builds it; a target
 * left out builds on the current context when its CPU matches. macos says
 * how macOS builds sign (identity, notarytool profile). No secrets here:
 * contexts carry their own ssh keys, and the keychain holds the identity and
 * the notary credentials.
 */
final class UserConfig
{
    public const FILE = '.venusian/build/config.json';

    public function __construct(private readonly string $home) {}

    /** @return array<string, string> target => docker context */
    public function hosts(): array
    {
        $hosts = (array) ($this->read()['hosts'] ?? []);

        foreach ($hosts as $target => $context) {
            if (! in_array($target, BuildJson::TARGETS, true)) {
                throw new RuntimeException(self::FILE.' hosts: '.$target.' is not one of '.implode(', ', BuildJson::TARGETS).'.');
            }
            if (! is_string($context) || $context === '') {
                throw new RuntimeException(self::FILE." hosts: {$target} must name a docker context.");
            }
        }

        return array_map('strval', $hosts);
    }

    /** Whether this machine has answered how macOS builds sign. */
    public function hasMacos(): bool
    {
        return array_key_exists('macos', $this->read());
    }

    /**
     * How macOS builds sign here: ad hoc until a Developer ID identity is named; notarized when a
     * notarytool keychain profile is named too.
     *
     * @return array{sign: string, notary_profile: ?string}
     */
    public function macos(): array
    {
        $macos = (array) ($this->read()['macos'] ?? []);
        $sign = $macos['sign'] ?? 'adhoc';
        $profile = $macos['notary_profile'] ?? null;

        if (! is_string($sign) || $sign === '') {
            throw new RuntimeException(self::FILE.' macos.sign must be "adhoc" or a codesign identity such as "Developer ID Application: Name (TEAMID)".');
        }
        if (! is_null($profile) && (! is_string($profile) || $profile === '')) {
            throw new RuntimeException(self::FILE.' macos.notary_profile must name a notarytool keychain profile (xcrun notarytool store-credentials <name>).');
        }

        return ['sign' => $sign, 'notary_profile' => $profile];
    }

    /** Saves how macOS builds sign, keeping every other key. */
    public function saveMacos(string $sign, ?string $notary_profile): void
    {
        $config = $this->read();
        $config['macos'] = array_filter(['sign' => $sign, 'notary_profile' => $notary_profile], fn (?string $value): bool => $value !== null);
        (new Filesystem)->dumpFile($this->path(), json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    private function path(): string
    {
        return rtrim($this->home, '/').'/'.self::FILE;
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        if (! is_file($this->path())) {
            return [];
        }

        return (array) json_decode((string) file_get_contents($this->path()), true, flags: JSON_THROW_ON_ERROR);
    }
}
