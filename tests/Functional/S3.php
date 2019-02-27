<?php


namespace Rtek\AwsGen\Tests\Functional;

use Func\S3\CreateBucketRequest;
use Func\S3\GetObjectRequest;
use Func\S3\PutObjectRequest;
use Func\S3\S3Client;
use Rtek\AwsGen\Generator\Generator;

class S3 extends FunctionalTestCase
{
    /** @var string */
    static protected $bucket;

    /** @var S3Client */
    static protected $client;

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
    }

    /**
     * @depends testGenerate
     * @doesNotPerformAssertions
     */
    public function testCreateClient()
    {
        $config = require 'config.php';
        self::$client = new S3Client($config['aws']);
    }

    /**
     * @depends testCreateClient
     */
    public function testCreateBucket()
    {
        $input = CreateBucketRequest::create()->bucket(self::$bucket = 'test-'.md5(microtime()));

        $output = self::$client->createBucket($input);

        $this->assertSame('/'.self::$bucket, $output->Location());
    }

    /**
     * @depends testCreateBucket
     * @doesNotPerformAssertions
     */
    public function testPutObject()
    {
        $input = PutObjectRequest::create()
            ->Bucket(self::$bucket)
            ->Key($key = date('c') . '.txt')
            ->Body(date('c'));

        $output = self::$client->putObject($input);
        return $key;
    }


    /**
     * @depends testPutObject
     * @param string $key
     */
    public function testGetObject(string $key)
    {
        $input = GetObjectRequest::create()
            ->Bucket(self::$bucket)
            ->Key($key);

        $output = self::$client->getObject($input);

        $this->assertSame(explode('.', $key)[0], $output->Body());

    }


}
