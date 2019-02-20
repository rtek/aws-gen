<?php

namespace Rtek\AwsGen;

use Aws\Api\AbstractModel;
use Aws\Api\Operation;
use Aws\Api\Service;
use Dev\DynamoDbStreams\DynamoDbStreamsClient;
use Dev\DynamoDbStreams\ListStreamsInput;
use Psr\Log\AbstractLogger;
use Rtek\AwsGen\Generator\Context;

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
    public function log($level, $message, array $context = array()) {
        echo $message ."\n";
    }
});

$gen->addServices([
        'ec2' => 'latest',
        'dynamodb' => 'latest',
        'streams.dynamodb' => 'latest',
    ])->setNamespace('Dev');

/*$gen->setFilter(function(AbstractModel $model, Context $context) {
    return $model instanceof Service || ($model instanceof Operation && $model['name'] === 'ListStreams') || $context->getOperation();
});*/

$out = 'data/tmp/output/';
foreach($gen() as $cls) {
    $file = $cls->getContainingFileGenerator();
    $str = $file->generate();


    @mkdir($out . dirname($file->getFilename()), 0777, true);

    file_put_contents($path = $out . $file->getFilename(), $str);
    include $path;
}

$config = require 'config.php';

$client = new DynamoDbStreamsClient($config['aws']);

$input = ListStreamsInput::create()->Limit(10)->TableName('obvius_logs');
$output =  $client->listStreams($input);

foreach($output->Streams() as $stream) {
    var_dump($stream->getTableName());
}

