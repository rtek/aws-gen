<?php declare(strict_types=1);

namespace Rtek\AwsGen\Writer;

use Rtek\AwsGen\Generator;

class DirWriter implements WriterInterface
{
    /** @var string */
    protected $dir;

    /**
     * @param string $dir where to write generated classes
     * @param bool $create recursively create $dir if it doesnt exist
     */
    public function __construct(string $dir, bool $create = false)
    {
        if (!is_dir($dir)) {
            if ($create) {
                mkdir($dir, 0777, true);
            } else {
                throw new \RuntimeException("\$dir is not a directory: '$dir'");
            }
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException("\$dir is not writable: '$dir'");
        }

        $this->dir = realpath($dir);
    }

    /**
     * @param Generator $generator
     */
    public function write(Generator $generator): void
    {
        foreach ($generator() as $cls) {
            $file = $cls->getContainingFileGenerator();
            file_put_contents($this->dir . '/' . $file->getFilename(), $file->generate());
        }
    }

    /**
     * @param string $dir
     * @param bool $create
     * @return static
     */
    public static function create(string $dir, bool $create = false)
    {
        return new static($dir, $create);
    }
}
