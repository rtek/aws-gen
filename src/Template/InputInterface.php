<?php declare(strict_types=1);

namespace Rtek\AwsGen\Template;

/**
 * Input classes require serialization to the AWS input array and the output class to unserialize the result
 */
interface InputInterface
{
    /**
     * @return array
     */
    public function toArray();

    /**
     * @return string|null
     */
    public function getOutputClass(): ?string;
}
