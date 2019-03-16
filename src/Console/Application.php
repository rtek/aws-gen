<?php declare(strict_types=1);

namespace Rtek\AwsGen\Console;

class Application extends \Symfony\Component\Console\Application
{
    public function __construct()
    {
        parent::__construct('rtek/aws-gen', '0.1');
        $this->setAutoExit(false);
        $this->addCommands([
            new Command\Generate(),
            new Command\Services(),
        ]);
    }
}
