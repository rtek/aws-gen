<?php declare(strict_types=1);

namespace Rtek\AwsGen;

use Aws\Api\ApiProvider;
use Aws\Api\Service;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Rtek\AwsGen\Exception\GeneratorException;
use Rtek\AwsGen\Generator\GeneratorHelperTrait;
use Rtek\AwsGen\Generator\NameResolver;
use Rtek\AwsGen\Generator\ServiceGenerator;
use Rtek\AwsGen\Template\ClientTrait;
use Rtek\AwsGen\Template\CreateObjectIterator;
use Rtek\AwsGen\Template\InputInterface;
use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\DocBlock\Tag\ReturnTag;
use Zend\Code\Generator\InterfaceGenerator;
use Zend\Code\Generator\TraitGenerator;
use Zend\Code\Reflection\ClassReflection;
use function Aws\manifest;

/**
 * Yields `Zend\Code\Generator\ClassGenerator` for AWS services
 *
 * An example of the generated classes for S3:
 * * An extended client for the service:
 *   * `\Foo\S3\S3Client extends \Aws\S3Client`
 * * Input (request) and output classes for a service operation:
 *   * `\Foo\S3\S3Client::listObjects(\Foo\S3\ListObjectsRequest $input): \Foo\S3\ListObjectsOutput`
 * * Shared data classes for the service:
 *   * `\Foo\S3\ListObjectsOutput::Contents(): \Foo\S3\ObjectList`
 *   * `\Foo\S3\ObjectList::getIterator(): \Foo\S3\Object[]`
 *   * `\Foo\S3\Object::Owner(): \Foo\S3\Owner`
 *   * `\Foo\S3\Owner::DisplayName(): ?string`
 */
class Generator implements LoggerAwareInterface
{
    use GeneratorHelperTrait;
    use LoggerAwareTrait;

    /** @var ApiProvider */
    protected $provider;

    /** @var Service[] */
    protected $services;

    /** @var string */
    protected $namespace;

    /** @var \Closure */
    protected $serviceGeneratorFactory;

    /** @var NameResolver  */
    protected $nameResolver;

    /** @var int  */
    protected $debugIndent = 0;

    /**
     * Pass the namespace where the service namespaces will be defined
     *
     * For example:
     *
     * If `$namespace = 'Foo\Bar'` and the service is S3, then S3 classes will reside in `Foo\Bar\S3`
     *
     * `$namespace` will also contain the common classes used by the service classes
     *
     * @param string $namespace the namespace where service namespaces will be defined
     * @param ApiProvider|null $provider an AWS ApiProvider - default: `ApiProvider::defaultProvider()`
     */
    public function __construct(string $namespace, ?ApiProvider $provider = null)
    {
        $this->namespace = trim($namespace, '\\');
        $this->provider = $provider ?? ApiProvider::defaultProvider();
        $this->logger = new NullLogger();
        $this->serviceGeneratorFactory = function (Service $service): ServiceGenerator {
            $gen = new ServiceGenerator($this->namespace, $service);
            $gen->setLogger($this->logger);
            return $gen;
        };
        $this->nameResolver = new NameResolver();
    }

    /**
     * A service generator factory with `$this` scope and signature: `(Service $service): ServiceGenerator`
     * @param callable $value
     * @return static
     */
    public function setServiceGeneratorFactory(callable $value)
    {
        $this->serviceGeneratorFactory = \Closure::fromCallable($value);
        return $this;
    }

    /**
     * Adds a AWS service to the generator using the [manifest.json](https://github.com/aws/aws-sdk-php/blob/master/src/data/manifest.json) key name
     *
     * The manifest key is usually the lowercase name of the service but there are exceptions:
     *  * `S3 => s3`
     *  * `EC2 => ec2`
     *  * `DynamoDB => dynamodb`
     *  * `DynamoDbStreams => streams.dynamodb`
     *
     * @param string $name the manifest key name
     * @param string $version the service api version, defaults to `latest`
     * @return static
     */
    public function addService(string $name, string $version = 'latest')
    {
        if (!$api = ApiProvider::resolve($this->provider, 'api', $name, $version)) {
            throw new GeneratorException("API does not exist '$name' at version '$version'");
        }

        $namespace = manifest($name)['namespace'];

        if ($this->services[$namespace] ?? null) {
            throw new GeneratorException("Service '$name' already added");
        }

        $api['metadata']['namespace'] = $namespace;
        $this->services[$namespace] = new Service($api, $this->provider);

        $this->logger->notice(sprintf('Added service: %s %s (%s) at namespace %s', $name, $version, $api['metadata']['protocol'], $namespace));

        return $this;
    }

    /**
     * Yields `Zend\Code\Generator\ClassGenerator` for all specified services and common classes
     * @return \Generator|ClassGenerator[]
     */
    public function __invoke(): \Generator
    {
        yield from new \ArrayIterator($this->createCommonClassGenerators());

        foreach ($this->services as $serviceName => $service) {
            $gen = $this->serviceGeneratorFactory->call($this, $service);
            yield from $gen();
        }
    }

    /**
     * The common classes contain the plumbing to wrap service classes with the AWS API
     *
     * * `InputInterface` allows the derived `<Service>Client` to identify and serialize input objects
     * * `ClientTrait` overrides the service client `__call` to detect input objects and return output objects
     * * `CreateObjectIterator` returns a data object from an AWS result array
     * * `AbstractInput` is the base class for all input classes
     *
     * @return ClassGenerator[]
     */
    protected function createCommonClassGenerators(): array
    {
        $classes = [
            InterfaceGenerator::fromReflection(new ClassReflection(InputInterface::class)),
            TraitGenerator::fromReflection(new ClassReflection(ClientTrait::class)),
            ClassGenerator::fromReflection(new ClassReflection(CreateObjectIterator::class)),
            $this->createClassGenerator([
                'name' => 'AbstractInput',
                'flags' => ClassGenerator::FLAG_ABSTRACT,
                'interfaces' => [$this->namespace . '\\InputInterface'],
                'hasDataTrait' => true,
                'constants' => [
                    ['OUTPUT_CLASS', null]
                ],
                'methods' => [
                    $this->createMethodGenerator([
                        'name' => 'getOutputClass',
                        'body' => 'return static::OUTPUT_CLASS;',
                        'returnType' => '?string',
                        'docBlock' => [
                            'tags' => [new ReturnTag('string|null')]
                        ]
                    ]),
                ],
            ])
        ];

        foreach ($classes as $cls) {
            $cls->setNamespaceName($this->namespace);
            $this->createFileGeneratorForClassGenerator($cls);
        }
        return $classes;
    }
}
