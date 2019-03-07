<?php declare(strict_types=1);

namespace Rtek\AwsGen\Console;

use Symfony\Component\Console\Style\SymfonyStyle;

class Searcher
{
    protected const AGAIN = 'Search again';
    protected const STOP = 'Stop searching';

    /** @var SymfonyStyle */
    protected $io;

    public function __construct(SymfonyStyle $io)
    {
        $this->io = $io;
    }

    public function search(string $thing, callable $getChoices): string
    {
        do {
            $search = (string)$this->io->ask("Search $thing");
            $choices = $getChoices($search);

            if (count($choices) === 1) {
                $value = $choices[0];
            } else {
                if (count($choices) === 0) {
                    $value = '';
                    $this->io->text('No results found');
                }
                array_unshift($choices, self::STOP, self::AGAIN);
                $value = $this->io->choice("Choose $thing", $choices, self::AGAIN);
            }
        } while ($value !== self::STOP && (!$value || $value === self::AGAIN));

        return (string)$value;
    }
}
