<?php declare(strict_types=1);

namespace Rtek\AwsGen\Template;

/**
 * Wraps an iterator to return `$cls(current())`
 */
class CreateObjectIterator extends \IteratorIterator
{
    /** @var string */
    protected $cls;

    /**
     * @param \Traversable $iterator
     * @param string $cls
     */
    public function __construct(\Traversable $iterator, string $cls)
    {
        parent::__construct($iterator);

        $this->cls = $cls;
    }

    /**
     * Instantiate `$cls` with `current()` as the only argument
     * @return object
     */
    public function current()
    {
        return new $this->cls(parent::current());
    }
}
