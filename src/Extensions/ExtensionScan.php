<?php

namespace Venusian\Build\Extensions;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/**
 * Parses the staged app for the functions and classes it names and resolves
 * each to its extension. The app's own code (everything but vendor/) adds:
 * each extension with the first file and symbol that called it. Vendor code
 * only reports: each extension with the packages that call it, since most of
 * those calls sit behind function_exists or class_exists (Angel, 2026-10-09).
 * A file that does not parse names nothing.
 */
final class ExtensionScan
{
    public function __construct(private readonly SymbolMap $map) {}

    /** @return array{added: array<string, string>, uses: array<string, list<string>>} */
    public function scan(string $stage): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $added = [];
        $uses = [];

        foreach (Finder::create()->files()->name('*.php')->in($stage)->ignoreDotFiles(false)->sortByName() as $file) {
            $path = str_replace('\\', '/', $file->getRelativePathname());
            $symbols = $this->symbols($parser, (string) file_get_contents($file->getPathname()));

            if (str_starts_with($path, 'vendor/')) {
                $parts = explode('/', $path);
                if (count($parts) >= 4 && $parts[1] !== 'composer' && $parts[1] !== 'bin') {
                    foreach (array_keys($symbols) as $extension) {
                        $uses[$extension]["{$parts[1]}/{$parts[2]}"] = true;
                    }
                }

                continue;
            }

            foreach ($symbols as $extension => $symbol) {
                $added[$extension] ??= "{$path} uses {$symbol}";
            }
        }

        ksort($added);
        ksort($uses);

        return ['added' => $added, 'uses' => array_map(function (array $packages): array {
            $names = array_keys($packages);
            sort($names);

            return $names;
        }, $uses)];
    }

    /** @return array<string, string> extension => the first symbol of it the code names */
    private function symbols(Parser $parser, string $code): array
    {
        try {
            $nodes = (new NodeTraverser(new NameResolver))->traverse($parser->parse($code) ?? []);
        } catch (Error) {
            return [];
        }

        $found = [];
        $finder = new NodeFinder;

        foreach ($finder->findInstanceOf($nodes, Node\Expr\FuncCall::class) as $call) {
            if (! $call->name instanceof Node\Name) {
                continue;
            }
            // Fully qualified: that function. Unqualified: in a namespace PHP falls back to the global one, which an extension defines.
            $function = $call->name->toLowerString();
            if (isset($this->map->functions[$function])) {
                $found[$this->map->functions[$function]] ??= $call->name->toString().'()';
            }
        }

        foreach ($finder->findInstanceOf($nodes, Node\Name\FullyQualified::class) as $name) {
            $class = $name->toLowerString();
            if (isset($this->map->classes[$class])) {
                $found[$this->map->classes[$class]] ??= $name->toString();
            }
        }

        return $found;
    }
}
