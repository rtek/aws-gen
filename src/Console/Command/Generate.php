<?php declare(strict_types=1);

namespace Rtek\AwsGen\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Generate extends AbstractCommand
{
    protected static $defaultName = 'generate';

    protected function configure()
    {
        $this->addOption(
            'services',
            's',
            InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
            'A set of service_name[:version] to generate'
        )->addOption(
            'namespace',
            'n',
            InputOption::VALUE_REQUIRED,
            'The namespace which generated services will reside',
            'AwsGen'
        )->addOption(
            'output_dir',
            'o',
            InputOption::VALUE_REQUIRED,
            'The directory where the namespace will be created relative to your composer.json',
            'src'
        );
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
    }
}
