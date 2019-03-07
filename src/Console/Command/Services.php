<?php declare(strict_types=1);

namespace Rtek\AwsGen\Console\Command;

use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Services extends AbstractCommand
{
    protected static $defaultName = 'services';

    protected function configure()
    {
        $this->setDescription('List and search for AWS services')
            ->addArgument(
                'search',
                InputArgument::OPTIONAL,
                'A search string that the service name must contain'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $services = self::search($input->getArgument('search'));

        $rows = [];
        $wasMultiple = false;
        $sep = new TableSeparator();
        foreach ($services as $service) {
            $newRows = [[
                $service['name'],
                $service['namespace'],
                implode("\n", $versions = $service['versions'])
            ]];

            if (count($versions) > 1) {
                if (count($rows) > 0) {
                    array_unshift($newRows, $sep);
                }
                $wasMultiple = true;
            } elseif ($wasMultiple) {
                array_unshift($newRows, $sep);
                $wasMultiple = false;
            }

            $rows = array_merge($rows, $newRows);
        }

        $this->io->table(['Name', 'Namespace', 'Versions'], $rows);
    }

    public static function search(?string $search): array
    {
        $services = [];
        foreach (\Aws\manifest() as $name => $item) {
            $include = true;
            if ($search) {
                $include = stripos($name, $search) !== false || stripos($item['namespace'], $search) !== false;
            }

            if ($include) {
                $services[] =  [
                    'name' => $name,
                    'versions' => array_unique($item['versions']),
                ] + $item;
            }
        }

        return $services;
    }
}
