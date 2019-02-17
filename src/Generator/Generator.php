<?php


namespace Rtek\AwsGen\Generator;


use Aws\Api\ApiProvider;
use Aws\Api\ListShape;
use Aws\Api\MapShape;
use Aws\Api\Operation;
use Aws\Api\Service;
use Aws\Api\Shape;
use Aws\Api\StructureShape;
use Aws\Api\TimestampShape;
use Rtek\AwsGen\Exception\GeneratorException;
use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\DocBlock\Tag\VarTag;
use Zend\Code\Generator\DocBlockGenerator;
use Zend\Code\Generator\PropertyGenerator;

class Generator
{
    /** @var ApiProvider */
    protected $provider;

    /** @var Service[] */
    protected $services;

    /** @var Context */
    protected $context;

    /** @var string */
    protected $namespace = '\\';

    public function __construct(?ApiProvider $provider = null)
    {
        $this->provider = $provider ?? ApiProvider::defaultProvider();
    }

    /**
     * @param string $namespace
     * @return static
     */
    public function setNamespace(string $namespace)
    {
        $this->namespace = trim($namespace,'\\') . '\\';
        return $this;
    }

    public function addService(string $name, string $version = 'latest')
    {
        if(!$api = ($this->provider)('api', $name = strtolower($name), $version)) {
            throw new GeneratorException("API does not exist '$name' at version '$version'");
        }

        if($this->services[$name] ?? null) {
            throw new GeneratorException("Service '$name' already added");
        }

        $this->services[$name] = new Service($api, $this->provider);

        return $this;
    }

    public function addServices(array $services)
    {
        foreach($services as $name => $version) {
            $this->addService($name, $version);
        }
        return $this;
    }

    /**
     * @return \Generator|ClassGenerator[]
     */
    public function __invoke(): \Generator
    {
        $this->context = new Context();
        foreach ($this->services as $serviceName => $service) {
            $this->visitService($service);
        }

        foreach($this->context->getClassHashes() as $hash) {
            yield $this->classGeneratorForHash($hash);
        }
    }

    protected function visitService(Service $service): void
    {
        $this->context->enterService($service);

        foreach($service->getOperations() as $operationName => $operation) {
            $this->visitOperation($operation);
        }

        $this->context->exitService();
    }

    protected function visitOperation(Operation $operation): void
    {
        $this->context->enterOperation($operation);

        $this->visitStructureShape($operation->getInput());
        $this->visitStructureShape($operation->getOutput());

        $this->context->exitOperation();
    }

    protected function visitStructureShape(StructureShape $structureShape): void
    {
        $this->context->enterStructureShape($structureShape);

        foreach($structureShape->getMembers() as $shape) {
            $this->visitShape($shape);
        }

        $this->context->exitStructureShape();
    }

    protected function visitShape(Shape $shape): void
    {
        $this->context->enterShape($shape);

        if($shape instanceof StructureShape || $shape instanceof ListShape || $shape instanceof MapShape) {
            $this->context->registerClassForShape($shape);
        }

        $this->context->exitShape();
    }

    protected function classGeneratorForHash(string $hash): ClassGenerator
    {
        $model = $this->context->getClassModel($hash);

        if($model instanceof Shape) {
            return $this->classGeneratorForShape($model, $this->context->getClassService($hash));
        } else {
            throw new \LogicException('TODO: '. get_class($model));
        }
    }

    protected function classGeneratorForShape(Shape $shape, ?Service $service): ClassGenerator
    {

        $subNs = $service ? $this->resolveServiceName($service) : 'Common';
        $cls = new ClassGenerator($shape->getName(), $this->namespace . $subNs);


        if($shape instanceof StructureShape) {
            foreach($shape->getMembers() as $name => $member) {
                $cls->addPropertyFromGenerator($gen = new PropertyGenerator($name, null, PropertyGenerator::FLAG_PROTECTED));
                $gen->setDocBlock($doc = new DocBlockGenerator());
                $doc->setTag(new VarTag($name, $member->getName()));
            }
        } else {
            $cls->setImplementedInterfaces([\IteratorAggregate::class]);

            if($shape instanceof ListShape) {
                $member = $shape->getMember();
                $cls->addPropertyFromGenerator($gen = new PropertyGenerator('items', [], PropertyGenerator::FLAG_PROTECTED));
                $gen->setDocBlock($doc = new DocBlockGenerator());
                $doc->setTag(new VarTag('items', $member->getName()));

            } else if($shape instanceof MapShape) {
                throw new \LogicException('TODO');
            }
        }


        return $cls;
    }

    protected function resolveServiceName(Service $service): string
    {
        if(!$name = $service->getMetadata('serviceIdentifier')) {
            $name = $service->getMetadata('targetPrefix');

            if(stripos($name, 'DynamoDBStreams') !== false) {
                $name = 'DynamoDbStreams';
            }
        }
        return $name;
    }


}
