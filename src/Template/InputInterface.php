<?php

namespace Rtek\AwsGen\Template;

interface InputInterface
{
    public function toArray();

    public function getOutputClass(): ?string;
}
