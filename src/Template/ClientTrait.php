<?php

namespace Rtek\AwsGen\Template;


trait ClientTrait
{
    /**
     * @param string $name
     * @param array $args
     * @return \Aws\Result|\GuzzleHttp\Promise\Promise
     */
    public function __call($name, array $args)
    {
        $outputCls = null;
        if (isset($args[0]) && $args[0] instanceof InputInterface) {
            $outputCls = substr(get_class($args[0]), 0, -5) . 'Output';
            $args[0] = $args[0]->toArray();
        }

        /** @var \GuzzleHttp\Promise\Promise|\Aws\Result $result */
        $result = parent::__call($name, $args);

        if ($outputCls) {
            if ($result instanceof \GuzzleHttp\Promise\Promise) {
                $result = $result->then(function () {
                    throw new \LogicException('todo');
                });
            } else {
                $result = new $outputCls($result->toArray());
            }
        }

        return $result;
    }
}

