<?php

namespace Rtek\AwsGen;

chdir(dirname(__DIR__));
require_once 'vendor/autoload.php';


$gen = new Generator\Generator();

$gen->addServices([
       // 'dynamodb' => 'latest',
        'streams.dynamodb' => 'latest',
    ])->setNamespace('Dev');


foreach($gen() as $cls) {
    echo $cls->generate() . "\n";
}
