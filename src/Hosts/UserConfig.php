<?php

namespace Venusian\Build\Hosts;

use RuntimeException;
use Venusian\Build\App\BuildJson;

/**
 * ~/.venusian/build/config.json: facts about this machine, never about the
 * app. hosts maps a target to the docker context that builds it; a target
 * left out builds on the current context when its CPU matches. No secrets
 * here: contexts carry their own ssh keys.
 */
final class UserConfig
{
    public const FILE = '.venusian/build/config.json';

    public function __construct(private readonly string $home) {}

    /** @return array<string, string> target => docker context */
    public function hosts(): array
    {
        $path = rtrim($this->home, '/').'/'.self::FILE;

        if (! is_file($path)) {
            return [];
        }

        $config = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $hosts = (array) ($config['hosts'] ?? []);

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
}
