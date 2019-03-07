<?php declare(strict_types=1);

namespace Rtek\AwsGen\Console\Command;

use Rtek\AwsGen\Console\Searcher;
use Rtek\AwsGen\Generator;
use Rtek\AwsGen\Writer\DirWriter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

class Generate extends AbstractCommand
{
    protected static $defaultName = 'generate';

    protected function configure()
    {
        $this->setDescription('Generate classes for AWS services')
            ->addOption(
                'services',
                's',
                InputOption::VALUE_REQUIRED,
                'A comma separated set of service_name[:version] to generate, see list-services for service names'
            )->addOption(
                'namespace',
                'ns',
                InputOption::VALUE_REQUIRED,
                'The namespace which generated services will reside'
            )->addOption(
                'output_dir',
                'o',
                InputOption::VALUE_REQUIRED,
                'The directory where the namespace will be created relative to your composer.json'
            );
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $searcher = new Searcher($this->io);

        if (!$input->getOption('services')) {
            $getChoices = function (string $search) {
                $choices = [];
                foreach (Services::search($search) as $service) {
                    $choices[] = $service['name'];
                    foreach ($service['versions'] as $version) {
                        $choices[] = $service['name'] . ':' . $version;
                    }
                }
                return array_slice($choices, 0, 8);
            };
            $input->setOption('services', $searcher->search('Service', $getChoices));
        }

        if (!$input->getOption('namespace')) {
            $input->setOption('namespace', $this->io->ask('What namespace?', 'AwsGen'));
        }

        if (!$input->getOption('output_dir')) {
            $input->setOption('output_dir', $this->io->ask('What output directory?', 'src'));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $rawServices = explode(',', $input->getOption('services'));
        $this->io->title('Generating: ' . implode(' ', $rawServices));

        $generator = new Generator($namespace = $input->getOption('namespace'));
        $generator->setLogger(new ConsoleLogger($output));

        $writer = DirWriter::create($dir = $input->getOption('output_dir'));

        foreach ($rawServices as $rawService) {
            $parts = explode(':', $rawService);

            $name = $parts[0] ?? null;
            $version = $parts[1] ?? 'latest';

            $generator->addService((string)$name, (string)$version);
            $this->io->text("Added $name:$version");
        }

        $this->io->text('Generating...');

        $count = $writer->write($generator);

        $this->io->text('...Complete');

        $this->io->success("Wrote $count files to $dir/$namespace");
    }
}
