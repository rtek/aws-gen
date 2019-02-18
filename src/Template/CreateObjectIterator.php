<?php


namespace Rtek\AwsGen\Template;

class CreateObjectIterator extends \IteratorIterator
{
    protected $cls;

    public function __construct(\Traversable $iterator, string $cls)
    {
        parent::__construct($iterator);

        $this->cls = $cls;
    }
    public function current()
    {
        return new $this->cls(parent::current());
    }
}
