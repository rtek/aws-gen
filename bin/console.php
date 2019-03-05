<?php

namespace Rtek\AwsGen;


use Dev\DynamoDb\DynamoDbClient;
use Dev\DynamoDb\ListTablesInput;
use Dev\DynamoDbStreams\DynamoDbStreamsClient;
use Dev\DynamoDbStreams\ListStreamsInput;

use Psr\Log\AbstractLogger;

chdir(dirname(__DIR__));
require_once 'vendor/autoload.php';


set_error_handler(function (int $severity, string $message, string $file, int $line) {
    if (!(error_reporting() & $severity)) {
        return;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});


$gen = new \Rtek\AwsGen\Generator();

$gen->setLogger(new class extends AbstractLogger {
    public function log($level, $message, array $context = array()) {
        echo $message ."\n";
    }
});

$gen->addService('dynamodb')
    ->addService('streams.dynamodb')
    ->setNamespace('Dev');

$out = 'data/tmp/output/';
foreach($gen() as $cls) {
    $file = $cls->getContainingFileGenerator();
    $str = $file->generate();


    @mkdir($out . dirname($file->getFilename()), 0777, true);

    file_put_contents($path = $out . $file->getFilename(), $str);
    include $path;
}

$config = require 'config.php';

$client = new DynamoDbClient($config['aws']);

$input = ListTablesInput::create();
$output =  $client->listTables($input);

foreach($output->tableNames() as $tableName) {
    var_dump($tableName);
}

