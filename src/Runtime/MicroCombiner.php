<?php

namespace Venusian\Build\Runtime;

use RuntimeException;

/** micro.sfx + ini block + phar = the executable. */
final class MicroCombiner
{
    private const MAGIC = "\xfd\xf6\x69\xe6";

    /** The bytes phpmicro reads as its ini: magic, big-endian length, text. */
    public static function block(string $ini): string
    {
        return self::MAGIC.pack('N', strlen($ini)).$ini;
    }

    public function combine(string $sfx, MicroIni $ini, string $phar, string $output): void
    {
        $out = fopen($output, 'wb') ?: throw new RuntimeException("Cannot write {$output}");

        try {
            $this->append($out, $sfx);
            fwrite($out, self::block($ini->render()));
            $this->append($out, $phar);
        } finally {
            fclose($out);
        }

        chmod($output, 0755);
    }

    /** @param  resource  $out */
    private function append($out, string $path): void
    {
        $in = fopen($path, 'rb') ?: throw new RuntimeException("Cannot read {$path}");
        stream_copy_to_stream($in, $out);
        fclose($in);
    }
}
