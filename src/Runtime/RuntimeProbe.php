<?php

namespace Venusian\Build\Runtime;

use Symfony\Component\Process\Process;

/** Learns a micro runtime's built-in extensions by running it once on a probe payload. */
final class RuntimeProbe
{
    public function __construct(private readonly MicroCombiner $combiner) {}

    /** @return list<string> get_loaded_extensions() inside the runtime */
    public function extensions(string $sfx, string $dir): array
    {
        file_put_contents("{$dir}/probe.php", '<?php echo json_encode(get_loaded_extensions());');
        $this->combiner->combine($sfx, new MicroIni('.', [], []), "{$dir}/probe.php", "{$dir}/probe");

        try {
            $process = new Process(["{$dir}/probe"], $dir);
            $process->mustRun();
        } finally {
            unlink("{$dir}/probe.php");
            unlink("{$dir}/probe");
        }

        return array_values(json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR));
    }
}
