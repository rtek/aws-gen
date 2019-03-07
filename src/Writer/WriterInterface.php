<?php declare(strict_types=1);

namespace Rtek\AwsGen\Writer;

use Rtek\AwsGen\Generator;

interface WriterInterface
{
    /**
     * Write files yeilded by $generator and returns the number of files written
     * @param Generator $generator
     * @return int
     */
    public function write(Generator $generator): int;
}
