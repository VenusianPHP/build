<?php

namespace Venusian\Build\Console;

use Closure;
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
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Venusian\Build\App\AppInspector;
use Venusian\Build\App\BuildJson;
use Venusian\Build\App\ManifestReader;
use Venusian\Build\Build;
use Venusian\Build\Hosts\Docker;
use Venusian\Build\Hosts\UserConfig;
use Venusian\Build\Phar\PharBuilder;
use Venusian\Build\Runtime\MacLibraries;
use Venusian\Build\Runtime\MacRuntime;
use Venusian\Build\Sources\Sources;
use Venusian\Build\Targets\BootCheck;
use Venusian\Build\Targets\CodeSigner;
use Venusian\Build\Targets\DebTarget;
use Venusian\Build\Targets\MacAppBundle;
use Venusian\Build\Targets\MacDiskImage;
use Venusian\Build\Targets\MacTarget;

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
        private readonly ?UserConfig $config = null,
        private readonly ?Closure $keychain = null,
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
            $read = (new ManifestReader($dir))->read();
            $manifest = $read;
            $name = $input->getArgument('name');

            if (is_string($name) && $name !== '') {
                $manifest = $manifest->with(['name' => $name]);
            }

            intro("Building {$manifest->name}");
            $build = $this->build ?? self::services();
            $interview = $this->interview ?? new Interview;
            $asked = $interview->ask($manifest, $input->isInteractive());
            $config = $this->config ?? new UserConfig(getenv('HOME') ?: sys_get_temp_dir());

            if (Interview::asksSigning($input->isInteractive(), $build->signs($manifest), $config)) {
                $interview->signing($config, Interview::identities(($this->keychain ?? self::keychain(...))()));
            }

            if ($input->isInteractive()) {
                (new BuildJson($dir))->write(Interview::answers($read, $asked));
            }

            $manifest = $asked;

            $outputs = $build->run($dir, $manifest, fn (string $line) => info($line));
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return Command::FAILURE;
        }

        foreach ($outputs as $output) {
            $size = round(self::size($output) / 1_048_576, 1);
            outro("{$output} ({$size} MB)");
        }

        return Command::SUCCESS;
    }

    /** The real services, wired for this machine. */
    public static function services(): Build
    {
        $files = new Filesystem;
        $exec = static function (array $command, ?string $cwd = null): array {
            $process = new Process($command, $cwd);
            $process->setTimeout(null);
            $process->run();

            return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
        };
        $run = static function (array $command, ?string $cwd = null) use ($exec): string {
            [$code, $out, $err] = $exec($command, $cwd);

            if ($code !== 0) {
                throw new RuntimeException(basename($command[0]).' '.basename($command[1] ?? '')." failed:\n".trim($err !== '' ? $err : $out));
            }

            return $out;
        };
        $home = getenv('HOME') ?: sys_get_temp_dir();
        $sources = Sources::real("{$home}/.venusian/build/sources");
        $docker = Docker::real();
        $config = new UserConfig($home);
        $mac = "{$home}/.venusian/build/macos";

        return new Build(new PharBuilder(PHP_BINARY, $files), [
            new MacTarget(
                new MacRuntime($sources, new MacLibraries($mac, $run), $run, "{$mac}/runtimes"),
                new MacAppBundle($files, $run),
                new CodeSigner($run),
                new MacDiskImage($files, $exec),
                BootCheck::real(),
                $config,
                fn (string $tool): bool => (new ExecutableFinder)->find($tool) !== null,
            ),
            new DebTarget('x86_64', $sources, $docker, $config->hosts()),
            new DebTarget('arm64', $sources, $docker, $config->hosts()),
        ]);
    }

    /** `security find-identity -v -p codesigning`: the keychain's signing identities. */
    private static function keychain(): string
    {
        $process = new Process(['security', 'find-identity', '-v', '-p', 'codesigning']);
        $process->run();

        return $process->getOutput();
    }

    private static function size(string $path): int
    {
        if (is_file($path)) {
            return (int) filesize($path);
        }

        $total = 0;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
            $total += $file->getSize();
        }

        return $total;
    }
}
