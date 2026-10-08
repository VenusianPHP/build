<?php

namespace Venusian\Build\Targets;

use Closure;

/**
 * codesign for the bundle.
 *
 * The micro binary carries the phar after its Mach-O image, so codesign
 * cannot seal it once combined (strict validation fails) and --deep is
 * never used: files under Contents/MacOS stay outside the bundle seal and
 * the binary keeps the signature it had. Ad hoc keeps the linker signature
 * micro.sfx shipped with. An identity signs the runtime before the payload
 * is appended, each .so, then the bundle, under the hardened runtime.
 */
final class CodeSigner
{
    /**
     * @param  Closure(list<string>, ?string): void  $run  runs a command, throwing when it fails
     */
    public function __construct(private readonly Closure $run) {}

    /** Signs the runtime image before the payload is appended; ad hoc leaves the linker signature. */
    public function signRuntime(string $sfx, string $identity): void
    {
        if (self::adhoc($identity)) {
            return;
        }

        $this->withEntitlements(fn (string $entitlements) => ($this->run)(
            ['codesign', '--force', '--options', 'runtime', '--entitlements', $entitlements, '--sign', $identity, $sfx], null,
        ));
    }

    /**
     * Signs the bundle, and with an identity each bundled .so first.
     *
     * @param  list<string>  $libraries  .so paths inside the bundle
     * @param  string  $identity  'adhoc', or a codesign identity such as "Developer ID Application: Name (TEAMID)"
     */
    public function sign(string $app, string $identity, array $libraries = []): void
    {
        if (self::adhoc($identity)) {
            ($this->run)(['codesign', '--force', '--sign', '-', $app], null);

            return;
        }

        foreach ($libraries as $library) {
            ($this->run)(['codesign', '--force', '--sign', $identity, $library], null);
        }

        $this->withEntitlements(fn (string $entitlements) => ($this->run)(
            ['codesign', '--force', '--options', 'runtime', '--entitlements', $entitlements, '--sign', $identity, $app], null,
        ));
    }

    public static function adhoc(string $identity): bool
    {
        return $identity === 'adhoc' || $identity === '';
    }

    /** JIT and unsigned executable memory for opcache, library validation off for the bundled .so files. */
    public static function entitlements(): string
    {
        return <<<'PLIST'
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0">
        <dict>
            <key>com.apple.security.cs.allow-jit</key>
            <true/>
            <key>com.apple.security.cs.allow-unsigned-executable-memory</key>
            <true/>
            <key>com.apple.security.cs.disable-library-validation</key>
            <true/>
        </dict>
        </plist>

        PLIST;
    }

    /** @param  Closure(string): void  $sign */
    private function withEntitlements(Closure $sign): void
    {
        $entitlements = tempnam(sys_get_temp_dir(), 'venusian-entitlements').'.plist';
        file_put_contents($entitlements, self::entitlements());

        try {
            $sign($entitlements);
        } finally {
            unlink($entitlements);
        }
    }
}
