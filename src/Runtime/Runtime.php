<?php

namespace Venusian\Build\Runtime;

/** A cached micro.sfx and what it already has compiled in. */
final class Runtime
{
    /**
     * @param  list<string>  $extensions  names from get_loaded_extensions() inside the runtime
     */
    public function __construct(
        public readonly string $sfx,
        public readonly string $tag,
        public readonly array $extensions,
    ) {}
}
