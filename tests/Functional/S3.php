<?php declare(strict_types=1);

namespace Rtek\AwsGen\Tests\Functional;

use Func\S3\CreateBucketRequest;
use Func\S3\DeleteBucketRequest;
use Func\S3\DeleteObjectRequest;
use Func\S3\GetObjectRequest;
use Func\S3\HeadObjectRequest;
use Func\S3\ListObjectsRequest;
use Func\S3\ListObjectsV2Request;
use Func\S3\PutObjectRequest;
use Func\S3\S3Client;
use Rtek\AwsGen\Generator;

class S3 extends FunctionalTestCase
{
    protected static $cleanup = [];

    public static function tearDownAfterClass()
    {

        //work around for https://github.com/sebastianbergmann/phpunit/issues/3564
        try {
            while (is_callable($fn = array_pop(static::$cleanup))) {
                call_user_func($fn);
            }
        } catch (\Throwable $e) {
            var_dump($e);
            exit();
        }
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testGenerate(): void
    {
        $gen = new Generator('Func');
        $gen->addService('s3');

        $out = 'tests/_files/tmp/';
        foreach ($gen() as $cls) {
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
     * @return S3Client
     */
    public function testCreateClient(): S3Client
    {
        $config = require 'config.php';
        return new S3Client($config['aws']);
    }

    /**
     * @depends testCreateClient
     * @param S3Client $client
     */
    public function testCreateBucket(S3Client $client): string
    {
        $config = require 'config.php';
        $bucket = $config['test_bucket'];
        $input = CreateBucketRequest::create($bucket);

        $output = $client->createBucket($input);

        $this->assertSame('/' . $bucket, $output->Location());

        self::$cleanup[] = function () use ($client, $bucket) {
            $objects = $client->listObjectsV2(ListObjectsV2Request::create($bucket));

            foreach ($objects->Contents() as $object) {
                $client->deleteObject(DeleteObjectRequest::create($bucket, $object->getKey()));
            }
            $input = DeleteBucketRequest::create($bucket);
            $client->deleteBucket($input);
        };

        return $bucket;
    }

    /**
     * @depends testCreateClient
     * @depends testCreateBucket
     * @param S3Client $client
     * @param string $bucket
     */
    public function testPutObject(S3Client $client, string $bucket): string
    {
        $input = PutObjectRequest::create($bucket, $key = date('c') . '.txt')
            ->Body(date('c'))
            ->ContentType('text/plain');

        $output = $client->putObject($input);

        $this->assertIsString($output->ETag());
        return $key;
    }


    /**
     * @depends testCreateClient
     * @depends testCreateBucket
     * @depends testPutObject
     * @param S3Client $client
     * @param string $bucket
     * @param string $key
     */
    public function testGetObject(S3Client $client, string $bucket, string $key): void
    {
        $input = GetObjectRequest::create($bucket, $key);

        $output = $client->getObject($input);

        $this->assertSame(explode('.', $key)[0], $output->Body());
    }

    /**
     * @depends testCreateClient
     * @depends testCreateBucket
     * @depends testPutObject
     * @param S3Client $client
     * @param string $bucket
     * @param string $key
     */
    public function testHeadObject(S3Client $client, string $bucket, string $key): void
    {
        $input = HeadObjectRequest::create($bucket, $key);

        $output = $client->headObject($input);

        $this->assertSame('text/plain', $output->ContentType());
    }
}
