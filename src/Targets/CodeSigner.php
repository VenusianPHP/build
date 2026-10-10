<?php

namespace Venusian\Build\Targets;

use Closure;
use RuntimeException;

/**
 * codesign for the bundle: each Frameworks dylib, then the bundle (which
 * signs the binary with the entitlements), all under the hardened runtime,
 * then a strict check. Nothing is appended to the binary, so the seal covers
 * the whole bundle (fact 24). An identity signs with a secure timestamp,
 * which notarization requires; ad hoc signs with "-". A name is signed by its
 * certificate's SHA-1, since codesign refuses a name two valid certificates
 * share, as a renewed Developer ID and the one it replaces do.
 */
final class CodeSigner
{
    /** @param  Closure(list<string>, ?string): string  $run  runs a command, returning its output, throwing when it fails */
    public function __construct(private readonly Closure $run) {}

    /**
     * @param  string  $identity  'adhoc', or a codesign identity such as "Developer ID Application: Name (TEAMID)"
     * @param  array<string, string>  $permissions  build.json permissions
     */
    public function sign(string $app, string $identity, array $permissions = []): void
    {
        $adhoc = self::adhoc($identity);
        $base = ['codesign', '--force', '--options', 'runtime', ...($adhoc ? [] : ['--timestamp']), '--sign', $adhoc ? '-' : $identity];

        foreach (glob($app.'/Contents/Frameworks/*.dylib') ?: [] as $library) {
            ($this->run)([...$base, $library], null);
        }

        $entitlements = tempnam(sys_get_temp_dir(), 'venusian-entitlements');
        file_put_contents($entitlements, self::entitlements($permissions, $adhoc));

        try {
            ($this->run)([...$base, '--entitlements', $entitlements, $app], null);
        } finally {
            unlink($entitlements);
        }

        ($this->run)(['codesign', '--verify', '--strict', '--deep', '--verbose=2', $app], null);
    }

    /**
     * The SHA-1 of the valid certificate an identity names; when two share the name, the one that
     * expires last. 'adhoc' and a SHA-1 come back as they are.
     */
    public function resolve(string $identity): string
    {
        if (self::adhoc($identity) || preg_match('/^[0-9A-Fa-f]{40}$/', $identity) === 1) {
            return $identity;
        }

        preg_match_all('/^\s*\d+\) ([0-9A-F]{40}) "(.+)"$/m', ($this->run)(['security', 'find-identity', '-v', '-p', 'codesigning'], null), $matches, PREG_SET_ORDER);
        $hashes = array_values(array_unique(array_map(fn (array $m): string => $m[1], array_filter($matches, fn (array $m): bool => $m[2] === $identity))));

        if ($hashes === []) {
            throw new RuntimeException("No valid signing identity named \"{$identity}\" in the keychain; security find-identity -v -p codesigning lists those there are.");
        }

        if (count($hashes) === 1) {
            return $hashes[0];
        }

        preg_match_all('/^SHA-1 hash: ([0-9A-F]{40})\n(-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----)/ms', ($this->run)(['security', 'find-certificate', '-a', '-c', $identity, '-Z', '-p'], null), $certificates, PREG_SET_ORDER);
        $expiry = [];
        foreach ($certificates as [, $hash, $pem]) {
            $parsed = openssl_x509_parse($pem);
            if (in_array($hash, $hashes, true) && is_array($parsed)) {
                $expiry[$hash] = (int) $parsed['validTo_time_t'];
            }
        }

        if ($expiry === []) {
            throw new RuntimeException("security find-certificate gave no certificate for \"{$identity}\".");
        }
        arsort($expiry);

        return (string) array_key_first($expiry);
    }

    public static function adhoc(string $identity): bool
    {
        return $identity === 'adhoc' || $identity === '';
    }

    /**
     * allow-jit for PCRE's JIT, which the hardened runtime otherwise denies; each declared
     * permission's device entitlement, without which the hardened runtime refuses the device
     * before macOS asks the user; and, ad hoc, library validation off: an ad hoc signature
     * carries no Team ID for the bundled dylibs to match.
     *
     * @param  array<string, string>  $permissions
     */
    public static function entitlements(array $permissions, bool $adhoc): string
    {
        $keys = ['com.apple.security.cs.allow-jit'];
        if ($adhoc) {
            $keys[] = 'com.apple.security.cs.disable-library-validation';
        }
        foreach (array_keys($permissions) as $permission) {
            $entitlement = MacAppBundle::PERMISSIONS[$permission][1];
            if ($entitlement !== null) {
                $keys[] = $entitlement;
            }
        }

        $entries = implode('', array_map(fn (string $key): string => "    <key>{$key}</key>\n    <true/>\n", $keys));

        return <<<PLIST
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0">
        <dict>
        {$entries}</dict>
        </plist>

        PLIST;
    }
}
