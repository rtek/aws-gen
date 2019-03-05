<?php declare(strict_types=1);

namespace Rtek\AwsGen\Tests\Generator;

use Rtek\AwsGen\Generator;

class AllTest extends AbstractTestCase
{
    /**
     * @doesNotPerformAssertions
     */
    public function testAll(): void
    {
        $gen = new Generator('All');
        foreach (\Aws\manifest() as $name => $item) {
            $gen->addService($name);
        }
        $this->generate($gen);
    }
}
