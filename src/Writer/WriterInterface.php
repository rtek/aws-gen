<?php declare(strict_types=1);

namespace Rtek\AwsGen\Writer;

use Rtek\AwsGen\Generator;

interface WriterInterface
{
    public function write(Generator $generator): void;
}
