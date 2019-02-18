<?php


namespace Rtek\AwsGen\Generator;

use Aws\Api\AbstractModel;
use Aws\Api\ApiProvider;
use Aws\Api\ListShape;
use Aws\Api\MapShape;
use Aws\Api\Operation;
use Aws\Api\Service;
use Aws\Api\Shape;
use Aws\Api\StructureShape;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Rtek\AwsGen\Exception\GeneratorException;
use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\DocBlock\Tag\ReturnTag;
use Zend\Code\Generator\DocBlock\Tag\VarTag;
use Zend\Code\Generator\DocBlockGenerator;
use Zend\Code\Generator\FileGenerator;
use Zend\Code\Generator\MethodGenerator;
use Zend\Code\Generator\ParameterGenerator;
use Zend\Code\Generator\PropertyGenerator;

class Generator implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var ApiProvider */
    protected $provider;

    /** @var Service[] */
    protected $services;

    /** @var Context */
    protected $context;

    /** @var string */
    protected $namespace = '\\';

    protected $debugIndent = 0;

    public function __construct(?ApiProvider $provider = null)
    {
        $this->provider = $provider ?? ApiProvider::defaultProvider();
        $this->logger = new NullLogger();
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
        $this->debugEnter($service);

        $this->context->enterService($service);

        $this->context->registerClassForModel($service);

        foreach($service->getOperations() as $operationName => $operation) {
            $this->visitOperation($operation);
        }

        $this->context->exitService();

        $this->debugExit();
    }

    protected function visitOperation(Operation $operation): void
    {
        $this->debugEnter($operation);

        $this->context->enterOperation($operation);

        $this->visitStructureShape($operation->getInput());
        $this->visitStructureShape($operation->getOutput());

        $this->context->exitOperation();

        $this->debugExit();
    }

    protected function visitStructureShape(StructureShape $structureShape): void
    {
        $this->debugEnter($structureShape);

        if($this->context->registerClassForModel($structureShape)) {
            $this->debugLog('Did nothing: already vistied');
        } else {
            foreach ($structureShape->getMembers() as $shape) {
                $this->visitShape($shape);
            }
        }

        $this->debugExit();
    }

    protected function visitShape(Shape $shape): void
    {
        $this->debugEnter($shape);

        if($shape instanceof StructureShape) {
            $this->visitStructureShape($shape);
        } else if($shape instanceof ListShape) {
            $this->context->registerClassForModel($shape);
            $member = $shape->getMember();
            if($member instanceof StructureShape) {
                $this->visitShape($member);
            }
        } else if($shape instanceof MapShape) {
            $this->context->registerClassForModel($shape);
            $this->visitShape($shape->getValue());
        } else {
            $this->debugLog(sprintf('Did nothing: %s (%s) ', $this->resolveName($shape), get_class($shape)));
        }


        $this->debugExit();
    }

    protected function classGeneratorForHash(string $hash): ClassGenerator
    {
        $model = $this->context->getClassModel($hash);

        if($model instanceof Service) {
            return $this->classGeneratorForService($model);
        } else if($model instanceof Shape) {
            return $this->classGeneratorForShape($model, $this->context->getClassService($hash));
        } else {
            throw new \LogicException('TODO: '. get_class($model));
        }
    }

    protected function classGeneratorForShape(Shape $shape, ?Service $service): ClassGenerator
    {
        $cls = ClassGenerator::fromArray([
            'name' => $shape->getName(),
            'namespaceName' => $this->resolveNamespace($service)
        ]);
        $file = $this->fileGeneratorForClassGenerator($cls);

        if($shape instanceof StructureShape) {
            $members = $shape->getMembers();
        } else if($shape instanceof ListShape) {
            $members = [$shape->getName() => $shape->getMember()];
        } else if($shape instanceof MapShape) {
            $shapeValue = $shape->getValue();
            if($shapeValue instanceof StructureShape) {
                $members = $shapeValue->getMembers();
            } else {
                throw new \LogicException('todo');
            }
        } else {
            throw new \LogicException('todo');
        }

        foreach($members as $memberName => $member) {
            //todo nulls

            $defaultValue = null;

            if($member instanceof StructureShape) {
                $docType = $this->resolveFqcn($member);
                $type = $docType;
            } else if($member instanceof ListShape || $member instanceof MapShape) {
                $docType = ['array', $this->resolveFqcn($member) .'[]'];
                $type = 'array';
                $defaultValue = [];
            } else {
                $type = $docType = $this->resolvePhpType($member);
            }

            $cls->addPropertyFromGenerator(
                PropertyGenerator::fromArray([
                    'name' => $memberName,
                    'defaultValue' => $defaultValue,
                    'flags' => PropertyGenerator::FLAG_PROTECTED,
                    'docBlock' => DocBlockGenerator::fromArray([
                        'tags' => [
                            new VarTag(null, $docType)
                        ]
                    ])
                ])
            )->addMethodFromGenerator(
                MethodGenerator::fromArray([
                    'name' => 'get'.$memberName,
                    'returnType' => $type,
                    'body' => sprintf('return $this->%s;', $memberName),
                    'docBlock' => DocBlockGenerator::fromArray([
                        'tags' => [
                            new ReturnTag($docType)
                        ]
                    ])
                ])
            )->addMethodFromGenerator(
                MethodGenerator::fromArray([
                    'name' => 'set'.$memberName,
                    'parameters' => [ParameterGenerator::fromArray([
                        'name' => 'input',
                        'type' => $type,
                    ])],
                    'body' => sprintf("\$this->%s = \$input;\nreturn \$this;", $memberName),
                    'docBlock' => DocBlockGenerator::fromArray([
                        'tags' => [
                            new VarTag(null, $docType),
                            new ReturnTag('static')
                        ]
                    ])
                ])
            );
        }

        return $cls;
    }

    protected function classGeneratorForService(Service $service): ClassGenerator
    {
        $name = $this->resolveName($service);

        $cls = ClassGenerator::fromArray([
            'name' => $name . 'Client',
            'namespaceName' => $namespace = $this->resolveNamespace($service),
            'extendedClass' => "\\Aws\\$name\\{$name}Client",
        ]);

        $file = $this->fileGeneratorForClassGenerator($cls);

        foreach($service->getOperations() as $name => $operation) {

            $lcName = lcfirst($name);

            $input = $operation->getInput();
            $params = count($input->getMembers()) === 0 ? [] : [
                ParameterGenerator::fromArray([
                    'name' => 'input',
                    'type' => $this->resolveFqcn($input)
                ])
            ];

            $returnType = $this->resolveFqcn($operation->getOutput());

            $cls->addMethodFromGenerator(
                MethodGenerator::fromArray([
                    'name' => $lcName,
                    'parameters' => $params,
                    'returnType' => $returnType,
                    'body' => sprintf('return new %s(parent::%s($input->toArray()));', $returnType, $lcName)
                ])
            )->addMethodFromGenerator(
                MethodGenerator::fromArray([
                    'name' => "{$lcName}Async",
                    'parameters' => $params,
                    'returnType' => PromiseInterface::class,
                    'body' => sprintf('return parent::%sAsync($input->toArray());', $lcName)
                ])
            );
        }

        return $cls;
    }

    protected function fileGeneratorForClassGenerator(ClassGenerator $classGenerator): FileGenerator
    {
        $file = FileGenerator::fromArray([
            'filename' => str_replace('\\', '/', $classGenerator->getNamespaceName() .'\\'. $classGenerator->getName()) . '.php',
            'class' => $classGenerator,
        ]);

        $classGenerator->setContainingFileGenerator($file);
        return $file;
    }

    protected function resolveName(AbstractModel $model): string
    {
        if($model instanceof Service) {
            if(!$name = $model->getMetadata('serviceIdentifier')) {
                $name = $model->getMetadata('targetPrefix');

                if(stripos($name, 'DynamoDBStreams') !== false) {
                    $name = 'DynamoDbStreams';
                }
            }
        } else {
            $name = $model['name'];
        }
        return $name;
    }

    protected function resolveNamespace(AbstractModel $model): string
    {
        $hash = $this->context->hash($model);
        $service = $this->context->getClassService($hash);
        return $this->namespace . ($service ? $this->resolveName($service) : 'Common');
    }


    protected function resolveFqcn(AbstractModel $model): string
    {
        return '\\'. trim($this->resolveNamespace($model) .'\\'. $this->resolveName($model), '\\');
    }

    protected function resolvePhpType(Shape $shape): string
    {
        switch($type = $shape['type']) {
            case 'string':
                return $type;
            case 'timestamp':
                return '\DateTime';
            case 'integer':
                return 'int';
            case 'long':
                return 'float';
            case 'blob':
                return 'string';
            case 'boolean':
                return 'bool';
            default:
                throw new \LogicException('TODO: '. $type);
        }
    }

    protected function debugEnter(AbstractModel $model): void
    {
        $this->debugLog(sprintf('Entering %s (%s)', $this->resolveName($model), get_class($model)));
        $this->debugIndent++;
    }

    protected function debugExit(): void
    {
        $this->debugIndent--;
    }

    protected function debugLog(string $str): void
    {
        $this->logger->debug(str_repeat(' ', $this->debugIndent) . $str);
    }
}
