<?php

namespace Rtek\AwsGen\Tests\Generator;

use function Aws\manifest;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Rtek\AwsGen\Generator\Generator;

class GeneratorTest extends TestCase
{
    public function testEverything(): void
    {
        $gen = new Generator();
        foreach (manifest() as $name => $item) {
            if(in_array($name, ['apigateway', 'apigatewayv2'])) {
                continue;
            }
            $gen->addService($name, $item['namespace']);
        }

        $this->generate($gen);
    }


    protected function generate(Generator $gen)
    {
       /* $gen->setLogger(new class extends AbstractLogger {
            public function log($level, $message, array $context = array()) {
                echo $message ."\n";
            }
        });*/

        $gen->setNamespace('Foo');
        $out = 'tests/_files/tmp/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($out, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach($files as $file) {
            @unlink($file);
        }

        foreach($gen() as $cls) {
            $file = $cls->getContainingFileGenerator();
            $str = $file->generate();

            @mkdir($out . dirname($file->getFilename()), 0777, true);

            if(file_exists($path = $out . $file->getFilename())) {
                echo "\n---EXISTING---\n";
                echo $existing = file_get_contents($path);
                echo "---NEW---\n";
                echo $str;

                throw new \Exception("$path already exists - does new === existing? " . ($str === $existing ? 'Y' : 'N'));
            }

            file_put_contents($path = $out . $file->getFilename(), $str);
            require $path;
        }
    }


}
