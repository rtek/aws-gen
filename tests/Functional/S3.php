<?php


namespace Rtek\AwsGen\Tests\Functional;

use Func\S3\S3Client;
use Rtek\AwsGen\Generator\Generator;

class S3 extends FunctionalTestCase
{

    /**
     * @doesNotPerformAssertions
     */
    public function testGenerate()
    {
        $generator = new Generator();
        $generator->setNamespace('Func')
            ->addService('s3');

        $out = 'tests/_files/tmp/';
        foreach($generator() as $cls) {
            $file = $cls->getContainingFileGenerator();
            $str = $file->generate();
            @mkdir($out . dirname($file->getFilename()), 0777, true);
            file_put_contents($path = $out . $file->getFilename(), $str);
            require $path;
        }

        return true;
    }

    /**
     * @depends testGenerate
     * @doesNotPerformAssertions
     */
    public function testCreateClient()
    {
        $config = require 'config.php';
        return new S3Client($config['aws']);
    }

    /**
     * @depends testCreateClient
     */
    public function testCreateBucket()
    {

    }
}
