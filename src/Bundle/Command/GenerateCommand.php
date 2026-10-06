<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Bundle\Command;

use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use MSSTC4PHP\DtoGenerator\DtoGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The core's `generate` command under the application's console: the same options, output and exit codes, with
 * `--config` defaulting to the bundle's `config` and resolved against the project directory.
 */
final class GenerateCommand extends Command
{
    public const NAME = 'dto-generator:generate';

    private string $config;

    private string $projectDir;

    /**
     * @param string $config relative to the project directory, or absolute
     */
    public function __construct(string $config, string $projectDir)
    {
        $this->config = Path::resolve($projectDir, $config);
        $this->projectDir = $projectDir;
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $core = $this->core();
        $definition = new InputDefinition();
        foreach ($core->getDefinition()->getOptions() as $option) {
            $definition->addOption($option->getName() === 'config'
                ? new InputOption('config', null, InputOption::VALUE_REQUIRED, sprintf('Config file, relative to the project directory (default: %s)', $this->config))
                : $option);
        }

        $this->setDescription($core->getDescription())->setDefinition($definition);
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        return DtoGenerator::guard($input, $output, fn (): int => parent::run($input, $output));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Command::run() binds the input anew, which would drop an option set here: the core gets its own input.
        $core = $this->core();
        $parameters = ['--config' => $this->config];
        foreach ($input->getOptions() as $name => $value) {
            if (!in_array($value, [null, false, ''], true) && $core->getDefinition()->hasOption((string) $name)) {
                $parameters['--' . $name] = $value;
            }
        }

        return $core->run(new ArrayInput($parameters), $output);
    }

    private function core(): Command
    {
        return DtoGenerator::console($this->projectDir)->find('generate');
    }
}
