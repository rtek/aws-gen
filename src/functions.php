<?php declare(strict_types=1);

namespace Rtek\AwsGen;

/**
 * Returns a string with all \ replaced with /
 * @param $string
 * @return string
 */
function path($string): string
{
    $path = str_replace('\\', '/', (string) $string);
    return $path;
}

/**
 * Returns a path()'d realpath that exists
 * @param $string
 * @return string
 */
function existing_path($string): string
{
    if (false === $rp = realpath($string = (string)$string)) {
        throw new \RuntimeException("Path does not exist: '$string'");
    }
    return path($string);
}
