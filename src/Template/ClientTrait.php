<?php declare(strict_types=1);

namespace Rtek\AwsGen\Template;

use Aws\Result;

/**
 * Provides `InputInterface::toArray()` serialization and `InputInterface::getOutputClass()(Aws\Result::toArray())` unserialization
 *
 * The default `AwsClient` behavior occurs if `InputInterface` is not passed as the input argument
 */
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
        $input = $args[0] ?? null;
        if ($input instanceof InputInterface) {
            $outputCls = $input->getOutputClass();
            $args[0] = $input->toArray();
        }

        /** @var \GuzzleHttp\Promise\Promise|Result $result */
        $result = parent::__call($name, $args);

        if ($outputCls) {
            if ($result instanceof \GuzzleHttp\Promise\Promise) {
                $result = $result->then(function (Result $result) use ($outputCls) {
                    return new $outputCls($result->toArray());
                });
            } else {
                $result = new $outputCls($result->toArray());
            }
        }

        return $result;
    }
}
