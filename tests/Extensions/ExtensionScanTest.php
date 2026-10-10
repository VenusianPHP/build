<?php

use Symfony\Component\Filesystem\Filesystem;
use Venusian\Build\Extensions\ExtensionScan;
use Venusian\Build\Extensions\SymbolMap;

/*
 * The scan: the app's own code adds what it calls, with the file and symbol
 * that called it; vendor code is listed per package; names resolve through
 * namespaces and imports.
 */
beforeEach(function () {
    $this->stage = sys_get_temp_dir().'/venusian-scan-'.bin2hex(random_bytes(4));
    $files = new Filesystem;
    $files->dumpFile($this->stage.'/app/Feed.php', <<<'PHP'
    <?php
    namespace App;
    use DOMDocument;
    final class Feed {
        public function read(string $xml): mixed {
            $document = new DOMDocument;
            helper();
            \App\Support\local_fn();
            return \simplexml_load_string($xml) ?: mb_strlen($xml);
        }
    }
    PHP);
    $files->dumpFile($this->stage.'/config/app.php', '<?php return ["x" => ctype_digit("1")];');
    $files->dumpFile($this->stage.'/app/Broken.php', '<?php this is not php');
    $files->dumpFile($this->stage.'/vendor/acme/money/src/Format.php', '<?php namespace Acme\Money; final class Format { public function __invoke() { return new \NumberFormatter("en", 1); } }');
    $files->dumpFile($this->stage.'/vendor/composer/ClassLoader.php', '<?php new \NumberFormatter("en", 1);');
    $this->map = new SymbolMap(
        ['simplexml_load_string' => 'simplexml', 'mb_strlen' => 'mbstring', 'ctype_digit' => 'ctype', 'helper' => 'fake', 'local_fn' => 'fake'],
        ['domdocument' => 'dom', 'numberformatter' => 'intl'],
    );
});

afterEach(function () {
    (new Filesystem)->remove($this->stage);
});

it('adds what the app\'s own code calls and lists what vendor code calls by package', function () {
    $found = (new ExtensionScan($this->map))->scan($this->stage);

    expect($found['added'])->toBe([
        'ctype' => 'config/app.php uses ctype_digit()',
        'dom' => 'app/Feed.php uses DOMDocument',
        'fake' => 'app/Feed.php uses helper()',
        'mbstring' => 'app/Feed.php uses mb_strlen()',
        'simplexml' => 'app/Feed.php uses simplexml_load_string()',
    ])->and($found['uses'])->toBe(['intl' => ['acme/money']]);
});
