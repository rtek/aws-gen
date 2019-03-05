<?php declare(strict_types=1);

namespace Rtek\AwsGen\Tests\Generator;

use Rtek\AwsGen\Generator;

class OneTest extends AbstractTestCase
{
    /**
     * @doesNotPerformAssertions
     */
    public function testOneThing(): void
    {
        $gen = new Generator('One');
        $gen->addService('s3');
        $this->generate($gen);
    }
}
