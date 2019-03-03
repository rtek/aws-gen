<?php declare(strict_types=1);

namespace Rtek\AwsGen\Template;

interface InputInterface
{
    public function toArray();

    public function getOutputClass(): ?string;
}
