<?php declare(strict_types=1);

namespace Rtek\AwsGen\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Rtek\AwsGen\Generator;

abstract class AbstractTestCase extends TestCase
{

    protected function setUp()
    {
        ini_set('memory_limit', '512M');
    }

    protected function generate(Generator $gen)
    {
        $out = 'tests/_files/tmp/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($out, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                @unlink((string)$file);
            }
        }

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

    protected function applyLogger(Generator $generator, string $level = LogLevel::DEBUG): void
    {
        $generator->setLogger(new class($level) extends AbstractLogger {
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
