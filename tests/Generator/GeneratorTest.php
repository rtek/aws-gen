<?php

namespace Rtek\AwsGen\Tests\Generator;

use Aws\Api\AbstractModel;
use Aws\Api\Operation;
use Aws\Api\Service;
use function Aws\manifest;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Rtek\AwsGen\Generator\Context;
use Rtek\AwsGen\Generator\Generator;

class GeneratorTest extends TestCase
{
    /** @var Generator */
    protected $generator;

    protected function setUp()
    {
        ini_set('memory_limit', '512M');
        $this->generator = new Generator();
    }

    public function testEverything(): void
    {
        $this->markTestSkipped();

        //$this->applyLogger(LogLevel::INFO);

        $this->generator->setNamespace('All');

        foreach (manifest() as $name => $item) {
            $this->generator->addService($name);
        }

        $this->generate();
    }

    public function testMapShape(): void
    {
       // $this->markTestSkipped();

        $this->applyLogger(LogLevel::DEBUG);

        $this->generator->setNamespace('MapShape')
            ->addService('dynamodb')
            ->setFilter(function (Operation $operation, Context $context) {
                return  $operation['name'] === 'BatchGetItem';
            });


        $this->generate();
    }

    public function testListOfString(): void
    {
        $this->markTestSkipped();

        $this->applyLogger(LogLevel::DEBUG);

        $this->generator->setNamespace('ListOfString')
            ->addService('apigateway')
            ->setFilter(function (Operation $operation, Context $context) {
                return true;// $operation['name'] === 'BatchGetItem';
            });


        $this->generate();
    }


    protected function generate()
    {
        $out = 'tests/_files/tmp/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($out, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                @unlink($file);
            }
        }

        $gen = $this->generator;
        foreach ($gen() as $cls) {
            $file = $cls->getContainingFileGenerator();
            $str = $file->generate();

            @mkdir($out . dirname($file->getFilename()), 0777, true);

            if (file_exists($path = $out . $file->getFilename())) {
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

    protected function applyLogger(string $level = LogLevel::DEBUG): void
    {
        $this->generator->setLogger(new class($level) extends AbstractLogger {
            protected $allow = [];
            public function __construct(string $level)
            {
                foreach ((new \ReflectionClass(LogLevel::class))->getConstants() as $name => $value) {
                    $this->allow[] = $value;
                    if ($value === $level) {
                        break;
                    }
                }
            }
            public function log($level, $message, array $context = [])
            {
                if (in_array($level, $this->allow)) {
                    echo $message . "\n";
                    ob_flush();
                }
            }
        });
    }
}
