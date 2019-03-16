<?php declare(strict_types=1);

namespace Rtek\AwsGen\Tests\Functional;

use App\AwsGen\S3;
use Rtek\AwsGen\Console\Application;
use Rtek\AwsGen\Console\Command\Generate;
use Rtek\AwsGen\Generator;
use Rtek\AwsGen\Tests\TmpTrait;
use Rtek\AwsGen\Writer\DirWriter;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

class Readme extends FunctionalTestCase
{
    use TmpTrait;

    public function testConsoleGen(): void
    {
        $app = new Application();

        $this->cleanTmp();
        $dir = $this->makeTmpDir('readme');

        //double escape to get \\ into string
        $input = sprintf(
            'generate --%s=s3 --%s=App\\\\AwsGen\\\\ --%s=%s --%s=App\\\\',
            Generate::OPT_SERVICES,
            Generate::OPT_NAMESPACE,
            Generate::OPT_OUTPUT_DIR,
            $dir,
            Generate::OPT_PSR4_PREFIX
        );

        $app->run(new StringInput($input), $output = new BufferedOutput());
        $output = $output->fetch();
        $this->assertContains('Added s3:latest', $output);
        $this->assertContains("Wrote 294 files to {$this->tmpDir}/readme/AwsGen/", $output);
    }

    public function testPhpGen(): void
    {
        $this->cleanTmp();
        $dir = $this->makeTmpDir('readme');

        $gen = new Generator('App\\AwsGen\\');
        $gen->addService('s3', '2006-03-01');
        $count = DirWriter::create($dir = "{$this->tmpDir}/readme")
                    ->setPsr4Prefix('App\\')
                    ->write($gen);

        $this->assertSame(294, $count);
        $this->assertFileExists("$dir/AwsGen/InputInterface.php");
    }

    public function testUsage(): void
    {
        $this->testPhpGen();

        spl_autoload_register(function ($class) {
            if (strpos($class, 'App\\AwsGen\\') === 0) {
                require_once str_replace('App\\', $this->tmpDir . '/readme/', $class) . '.php';
            }
        });

        //todo import real credentials
        $config = [
            'credentials' => [
                'key' => '***',
                'secret' => '***',
            ],
            'region' => 'us-east-1',
        ];

        $client = new S3\S3Client($config);

        $input = S3\CreateBucketRequest::create($bucket = 'test');

        $input->Bucket($bucket)->ACL('public-read');

        $output = $client->createBucket($input);

        echo "Bucket created at: {$output->Location()}\n";

        $output = $client->putObject(
            S3\PutObjectRequest::create($bucket, $key = 'foo.txt')
                ->Body('bar baz')->ContentType('text/plain')
        );

        echo "Created object {$key} with ETag {$output['ETag']}\n";

        $input = new S3\GetObjectRequest([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);
        $output = $client->getObject($input);
        echo "The object has a body of: {$output->Body()}\n";

        $result = $client->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);
        echo "The object still has a body of: {$result['Body']}\n";

        $output = $client->listObjectsV2(S3\ListObjectsV2Request::create($bucket));
        foreach ($output->Contents() as $object) {
            $client->deleteObject(S3\DeleteObjectRequest::create($bucket, $object->getKey()));
        }
    }
}
