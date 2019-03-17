<?php declare(strict_types=1);

namespace Rtek\AwsGen\Tests\Functional;

use App\AwsGen\S3 as S3;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Psr\Http\Message\RequestInterface;
use Rtek\AwsGen\Console\Application;
use Rtek\AwsGen\Console\Command\Generate;
use Rtek\AwsGen\Generator;
use Rtek\AwsGen\Tests\TmpTrait;
use Rtek\AwsGen\Writer\DirWriter;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ReadmeTest extends FunctionalTestCase
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

    /**
     * @depends testConsoleGen
     */
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

    /**
     * @depends testPhpGen
     */
    public function testUsage(): void
    {
        spl_autoload_register(function ($class) {
            if (strpos($class, 'App\\AwsGen\\') === 0) {
                require_once str_replace('App\\', $this->tmpDir . '/readme/', $class) . '.php';
            }
        });

        $mock = new MockHandler();

        $expect = function (array $input, array $output) use ($mock) {
            $mock->append(function (CommandInterface $cmd) use ($input, $output) {
                $actual = $cmd->toArray();
                unset($actual['@http']);
                $this->assertSame($input, $actual);
                return new Result($output);
            });
        };

        $config = [
            'credentials' => [
                'key' => '*',
                'secret' => '*',
            ],
            'region' => 'us-east-1',
            'handler' => $mock
        ];

        $client = new S3\S3Client($config);


        $expect([
            'Bucket' => $bucket = 'test',
            'ACL' => 'public-read'
        ], [
            'Location' => 'foo'
        ]);
        $input = S3\CreateBucketRequest::create($bucket);
        $input->Bucket($bucket)->ACL('public-read');
        $output = $client->createBucket($input);
        $this->assertSame('foo', $output->Location());


        $expect([
            'Bucket' => $bucket,
            'Key' => $key = 'foo.txt',
            'Body' => $body = 'bar baz',
            'ContentType' => 'text/plain'
        ], [
            'ETag' => 'bar',
        ]);
        $output = $client->putObject(
            S3\PutObjectRequest::create($bucket, $key)
                ->Body($body)->ContentType('text/plain')
        );
        $this->assertSame('bar', $output['ETag']);


        $expect([
            'Bucket' => $bucket,
            'Key' => $key,
        ], [
            'Body' => $body,
        ]);
        $input = new S3\GetObjectRequest([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);
        $output = $client->getObject($input);
        $this->assertSame($body, $output->Body());


        $expect([
            'Bucket' => $bucket,
            'Key' => $key,
        ], [
            'Body' => $body,
        ]);
        $result = $client->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);
        $this->assertSame($body, $output->Body());


        $expect([
            'Bucket' => $bucket,
        ], [
            'Contents' => [
                ['Key' => 'one'],
                ['Key' => 'two'],
            ]
        ]);
        $output = $client->listObjectsV2(S3\ListObjectsV2Request::create($bucket));
        $this->assertCount(2, $output->Contents());


        foreach ($output->Contents() as $object) {
            $expect([
                'Bucket' => $bucket,
                'Key' => $object->getKey(),
            ], []);
            $client->deleteObject(S3\DeleteObjectRequest::create($bucket, $object->getKey()));
        }
    }
}
