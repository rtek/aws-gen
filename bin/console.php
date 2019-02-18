<?php

namespace Rtek\AwsGen;

use Psr\Log\AbstractLogger;
use Psr\Log\Test\TestLogger;

chdir(dirname(__DIR__));
require_once 'vendor/autoload.php';


set_error_handler(function (int $severity, string $message, string $file, int $line) {
    if (!(error_reporting() & $severity)) {
        return;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});


$gen = new Generator\Generator();

$gen->setLogger(new class extends AbstractLogger {
    public function log($level, $message, array $context = array())
    {
        echo $message ."\n";
    }
});

$gen->addServices([
       // 'dynamodb' => 'latest',
        'streams.dynamodb' => 'latest',
    ])->setNamespace('Dev');


foreach($gen() as $cls) {
    $file = $cls->getContainingFileGenerator();
    $str = $file->generate();

    $out = 'data/tmp/output/';
    @mkdir($out . dirname($file->getFilename()), 0777, true);

    file_put_contents($out . $file->getFilename(), $str);
}
