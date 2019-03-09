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

    const OPT_NAMESPACE = 'namespace';
    const OPT_SERVICES = 'services';
    const OPT_OUTPUT_DIR = 'output-dir';
    const OPT_PSR4_PREFIX = 'psr4-prefix';

    protected function configure()
    {
        $this->setDescription('Generate classes for AWS services')
            ->addOption(
                self::OPT_SERVICES,
                's',
                InputOption::VALUE_REQUIRED,
                'A comma separated set of service_name[:version] to generate, see list-services for service names'
            )->addOption(
                self::OPT_NAMESPACE,
                'ns',
                InputOption::VALUE_REQUIRED,
                'The namespace which generated services will reside'
            )->addOption(
                self::OPT_OUTPUT_DIR,
                'o',
                InputOption::VALUE_REQUIRED,
                'The directory where the namespace will be created relative to your composer.json'
            )->addOption(
                self::OPT_PSR4_PREFIX,
                'psr4',
                InputOption::VALUE_REQUIRED,
                'The PSR-4 namespace prefix to add to the namespace'
            );
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $searcher = new Searcher($this->io);

        if (!$input->getOption(self::OPT_SERVICES)) {
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
            $input->setOption(self::OPT_SERVICES, $searcher->search('Service', $getChoices));
        }

        //detect defaults from composer.json
        $src = 'src';
        $namespace = 'AwsGen';
        $psr4 = '';
        if ($composer = @json_decode(file_get_contents('composer.json'), true)) {
            if ($psr4Autoload = $composer['autoload']['psr-4'] ?? null) {
                $psr4 = key($psr4Autoload);
                $namespace = $psr4 . $namespace;
                $src = $psr4Autoload[$psr4];
            }
        }


        if (!$input->getOption(self::OPT_NAMESPACE)) {
            $input->setOption(self::OPT_NAMESPACE, $this->io->ask('What namespace?', $namespace));
        }

        if (!$input->getOption(self::OPT_OUTPUT_DIR)) {
            $input->setOption(self::OPT_OUTPUT_DIR, $this->io->ask('What output directory?', $src));
        }

        if (!$input->getOption(self::OPT_PSR4_PREFIX)) {
            $choices = [''];
            $last = '';
            foreach (explode('\\', $input->getOption(self::OPT_NAMESPACE)) as $ns) {
                $choices[] = $last .= $ns . '\\';
            }

            $prefix = $this->io->choice('What PSR-4 namespace prefix?', $choices, $psr4);

            if ($prefix) {
                $input->setOption(self::OPT_PSR4_PREFIX, $prefix);
            }
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $rawServices = explode(',', $input->getOption(self::OPT_SERVICES));
        $this->io->title('Generating: ' . implode(' ', $rawServices));

        $generator = new Generator($namespace = $input->getOption(self::OPT_NAMESPACE));
        $generator->setLogger(new ConsoleLogger($output));

        $writer = DirWriter::create($dir = $input->getOption(self::OPT_OUTPUT_DIR));
        $writer->setPsr4Prefix($psr4 = $input->getOption(self::OPT_PSR4_PREFIX));


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

        $this->io->success("Wrote $count files to {$writer->getResolvedDir()}");
    }
}
