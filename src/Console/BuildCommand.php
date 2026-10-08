<?php

namespace Venusian\Build\Console;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Venusian\Build\App\AppInspector;
use Venusian\Build\App\ManifestReader;
use Venusian\Build\Build;
use Venusian\Build\Phar\PharBuilder;
use Venusian\Build\Runtime\GitHubReleases;
use Venusian\Build\Runtime\MicroCombiner;
use Venusian\Build\Runtime\RuntimeStore;
use Venusian\Build\Targets\CodeSigner;
use Venusian\Build\Targets\MacAppBundle;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;

#[AsCommand(
    name: 'build',
    description: 'Compile the Venusian app in this directory into a native executable',
)]
class BuildCommand extends Command
{
    public function __construct(
        private ?Build $build = null,
        private ?Interview $interview = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'App name; the manifest name otherwise')
            ->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'App directory', getcwd() ?: '.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Prompt::setOutput($output);
        $dir = rtrim((string) $input->getOption('dir'), '/');
        $problems = (new AppInspector($dir))->problems();

        if ($problems !== []) {
            error("{$dir} is not a Venusian app:");
            foreach ($problems as $problem) {
                $output->writeln("  - {$problem}");
            }

            return Command::FAILURE;
        }

        try {
            $manifest = (new ManifestReader($dir))->read();
            $name = $input->getArgument('name');

            if (is_string($name) && $name !== '') {
                $derived = $manifest->bundle_id === 'com.venusian.'.$manifest->kebab();
                $manifest = $manifest->with(['name' => $name]);
                $manifest = $derived ? $manifest->with(['bundle_id' => 'com.venusian.'.$manifest->kebab()]) : $manifest;
            }

            intro("Building {$manifest->name}");
            $manifest = ($this->interview ?? new Interview)->ask($manifest, $input->isInteractive());

            $app = ($this->build ?? self::services())->run($dir, $manifest, fn (string $line) => info($line));
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return Command::FAILURE;
        }

        $size = round(self::size($app) / 1_048_576, 1);
        outro("{$app} ({$size} MB)");

        return Command::SUCCESS;
    }

    /** The real services, wired for this machine. */
    public static function services(): Build
    {
        $files = new Filesystem;
        $run = static function (array $command, ?string $cwd = null): void {
            (new Process($command, $cwd))->setTimeout(600)->mustRun();
        };
        $home = getenv('HOME') ?: sys_get_temp_dir();

        return new Build(
            new RuntimeStore($home.'/.venusian/build/runtimes', new GitHubReleases, new MicroCombiner),
            new PharBuilder(PHP_BINARY, $files),
            new MicroCombiner,
            new MacAppBundle($files, $run),
            new CodeSigner($run),
        );
    }

    private static function size(string $path): int
    {
        $total = 0;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
            $total += $file->getSize();
        }

        return $total;
    }
}
