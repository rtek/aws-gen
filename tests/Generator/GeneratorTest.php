<?php

namespace Rtek\AwsGen\Tests\Generator;

use function Aws\manifest;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Rtek\AwsGen\Generator\Generator;

class GeneratorTest extends TestCase
{
    public function testGenerateAndIncludeEverything(): void
    {

        $gen = new Generator();
        $gen->setNamespace('Foo');

        foreach(manifest() as $name => $item) {
            $gen->addService($name, $item['namespace']);
        }

        $gen->setLogger(new class extends AbstractLogger {
            public function log($level, $message, array $context = array()) {
                echo $message ."\n";
            }
        });


        $out = 'tests/_files/tmp/';
        foreach($gen() as $cls) {
            $file = $cls->getContainingFileGenerator();
            $str = $file->generate();

            @mkdir($out . dirname($file->getFilename()), 0777, true);

            file_put_contents($path = $out . $file->getFilename(), $str);
            require $path;
        }
    }
}
