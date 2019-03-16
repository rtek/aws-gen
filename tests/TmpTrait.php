<?php declare(strict_types=1);

namespace Rtek\AwsGen\Tests;

trait TmpTrait
{
    protected $tmpDir = 'tests/_files/tmp';

    protected function cleanTmp(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                @unlink((string)$file);
            }
        }
    }

    protected function makeTmpDir(string $name): string
    {
        @mkdir($dir = $this->tmpDir . '/' . $name, 0777, true);

        if (!is_dir($dir)) {
            throw new \RuntimeException("Failed to make tmp dir: '$dir'");
        }

        return $dir;
    }
}
