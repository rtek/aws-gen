<?php declare(strict_types=1);

namespace Rtek\AwsGen\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListServices extends AbstractCommand
{
    protected static $defaultName = 'list-services';

    protected function configure()
    {
        $this->addOption(
            'search',
            null,
            InputOption::VALUE_REQUIRED,
            'A search string that the service must contain'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $search = $input->getOption('search');
        $services = [];

        foreach(\Aws\manifest() as $name => $item) {
            var_dump($item);
        }
    }
}
