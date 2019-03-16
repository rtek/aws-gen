<?php declare(strict_types=1);

namespace Rtek\AwsGen\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Rtek\AwsGen\Generator;
use Rtek\AwsGen\Tests\TmpTrait;

abstract class AbstractTestCase extends TestCase
{
    use TmpTrait;

    protected function setUp()
    {
        ini_set('memory_limit', '512M');
    }

    protected function generate(Generator $gen)
    {
        $this->cleanTmp();

        foreach ($gen() as $cls) {
            $file = $cls->getContainingFileGenerator();
            $str = $file->generate();

            $this->makeTmpDir(dirname($file->getFilename()));

            if (file_exists($path = $this->tmpDir . '/' . $file->getFilename())) {
                echo "\n---EXISTING---\n";
                echo $existing = file_get_contents($path);
                echo "---NEW---\n";
                echo $str;

                throw new \Exception("$path already exists - does new === existing? " . ($str === $existing ? 'Y' : 'N'));
            }

            file_put_contents($path = $this->tmpDir . '/' . $file->getFilename(), $str);
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
