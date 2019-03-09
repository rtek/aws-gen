<?php declare(strict_types=1);

namespace Rtek\AwsGen\Writer;

use Rtek\AwsGen\Generator;
use function Rtek\AwsGen\existing_path;
use function Rtek\AwsGen\path;

/**
 * Write generated classes to a directory with psr-4 support
 */
class DirWriter implements WriterInterface
{
    /** @var string */
    protected $dir;

    /** @var string */
    protected $psr4Prefix = '';

    /** @var string */
    protected $resolvedDir;

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

        $this->dir = $this->resolvedDir = existing_path($dir);
    }

    /**
     * @param string|null $value
     * #return static
     */
    public function setPsr4Prefix(?string $value)
    {
        $this->psr4Prefix = path($value);
        return $this;
    }

    /**
     * @param Generator $generator
     * @param int
     */
    public function write(Generator $generator): int
    {

        if ($psr4 = $this->psr4Prefix) {
            $namespace = path($generator->getNamespace() . '\\');
            $this->resolvedDir = $this->dir . '/' . str_replace($psr4, '', $namespace);
        }

        foreach ($generator() as $i => $cls) {
            $file = $cls->getContainingFileGenerator();

            $filename = path($file->getFilename());

            $path = $this->dir . '/' . str_replace($psr4, '', $filename);

            if (!is_dir($dir = dirname($path))) {
                mkdir($dir, 0777, true);
            }

            file_put_contents($path, $file->generate());
        }

        return $i ?? 0;
    }

    /**
     * @return string
     */
    public function getResolvedDir(): string
    {
        return $this->resolvedDir;
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
