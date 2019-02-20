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
use Aws\AwsClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Rtek\AwsGen\Exception\GeneratorException;
use Rtek\AwsGen\Template\AbstractInput;
use Rtek\AwsGen\Template\ClientTrait;
use Rtek\AwsGen\Template\CreateObjectIterator;
use Rtek\AwsGen\Template\InputInterface;
use Rtek\AwsGen\Template\InputTrait;
use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\DocBlock\Tag\GenericTag;
use Zend\Code\Generator\DocBlock\Tag\PropertyTag;
use Zend\Code\Generator\DocBlock\Tag\ReturnTag;
use Zend\Code\Generator\DocBlock\Tag\VarTag;
use Zend\Code\Generator\DocBlockGenerator;
use Zend\Code\Generator\FileGenerator;
use Zend\Code\Generator\InterfaceGenerator;
use Zend\Code\Generator\MethodGenerator;
use Zend\Code\Generator\ParameterGenerator;
use Zend\Code\Generator\PropertyGenerator;
use Zend\Code\Generator\TraitGenerator;
use Zend\Code\Reflection\ClassReflection;
use Zend\Code\Reflection\MethodReflection;

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

    /** @var callable */
    protected $filter;

    public function __construct(?ApiProvider $provider = null)
    {
        $this->provider = $provider ?? ApiProvider::defaultProvider();
        $this->logger = new NullLogger();
        $this->filter = function() { return true; };
    }

    /**
     * @param string $namespace
     * @return static
     */
    public function setNamespace(string $namespace)
    {
        $this->namespace = trim($namespace,'\\');
        return $this;
    }

    /**
     * @param callable $filter
     */
    public function setFilter(callable $filter): void
    {
        $this->filter = $filter;
    }

    public function addService(string $name, string $namespace, string $version = 'latest')
    {
        if(!$api = ApiProvider::resolve($this->provider, 'api', $name, $version)) {
            throw new GeneratorException("API does not exist '$name' at version '$version'");
        }

        if($this->services[$namespace] ?? null) {
            throw new GeneratorException("Service '$name' already added");
        }

        $api['metadata']['namespace'] = $namespace;
        $this->services[$namespace] = new Service($api, $this->provider);

        return $this;
    }

    /**
     * @return \Generator|ClassGenerator[]
     */
    public function __invoke(): \Generator
    {
        $this->context = new Context();
        foreach ($this->services as $serviceName => $service) {
            $this->visitModel($service);
        }

        yield from new \ArrayIterator($this->createOtherClassGenerators());

        foreach($this->context->getClassHashes() as $hash) {
            yield $this->createClassGeneratorForHash($hash);
        }
    }

    protected function visitModel(AbstractModel $model): void
    {
        $this->debugEnter($model);

        if(($this->filter)($model, $this->context)) {
            if ($model instanceof Service) {
                $this->visitService($model);
            } else if ($model instanceof Operation) {
                $this->visitOperation($model);
            } else if ($model instanceof StructureShape) {
                $this->visitStructureShape($model);
            } else if ($model instanceof Shape) {
                $this->visitShape($model);
            } else {
                throw new \LogicException('TODO');
            }
        } else {
            $this->debugLog('Skipped: by filter');
        }

        $this->debugExit();
    }

    protected function visitService(Service $service): void
    {
        $this->context->enterService($service);

        $this->context->registerClassForModel($service);

        foreach($service->getOperations() as $operationName => $operation) {
            $this->visitModel($operation);
        }

        $this->context->exitService();

    }

    protected function visitOperation(Operation $operation): void
    {
        $this->context->enterOperation($operation);

        $this->visitModel($operation->getInput());
        $this->visitModel($operation->getOutput());

        $this->context->exitOperation();
    }

    protected function visitStructureShape(StructureShape $structureShape): void
    {
        if(count($structureShape->getMembers()) === 0) {
            $this->debugLog('Skipped: empty StructureShape');
        } else if($this->context->registerClassForModel($structureShape)) {
            $this->debugLog('Skipped: already visited');
        } else {
            foreach ($structureShape->getMembers() as $shape) {
                $this->visitModel($shape);
            }
        }
    }

    protected function visitShape(Shape $shape): void
    {
        $this->debugEnter($shape);

        if($shape instanceof StructureShape) {
            $this->visitModel($shape);
        } else if($shape instanceof ListShape) {
            $this->context->registerClassForModel($shape);
            $member = $shape->getMember();
            if($member instanceof StructureShape) {
                $this->visitModel($member);
            }
        } else if($shape instanceof MapShape) {
            $this->context->registerClassForModel($shape);
            $this->visitModel($shape->getValue());
        } else {
            $this->debugLog(sprintf('Did nothing: %s (%s) ', $this->resolveName($shape), get_class($shape)));
        }


        $this->debugExit();
    }

    protected function createClassGeneratorForHash(string $hash): ClassGenerator
    {
        $model = $this->context->getClassModel($hash);

        if($model instanceof Service) {
            return $this->createClassGeneratorForService($model);
        } else if($model instanceof Shape) {
            $service = $this->context->getClassService($hash);

            //service is not null if there is an operation
            if ($operation = $this->context->getClassOperation($hash)) {
                if($model === $operation->getInput()) {
                    return $this->createClassGeneratorForInput($model, $service);
                } else if($model === $operation->getOutput()) {
                    return $this->createClassGeneratorForOutput($model, $service);
                }
            }
            return $this->createClassGeneratorForData($model, $service);
        } else {
            throw new \LogicException('TODO: '. get_class($model));
        }
    }

    protected function createClassGeneratorForInput(Shape $shape, Service $service): ClassGenerator
    {
        $cls = $this->createClassGeneratorForShape($shape, ['setPrefix' => '']);
        $cls->setExtendedClass($this->namespace .'\\AbstractInput');
        return $cls;
    }

    protected function createClassGeneratorForOutput(Shape $shape, Service $service): ClassGenerator
    {
        $cls = $this->createClassGeneratorForShape($shape, ['getPrefix' => '']);
        $cls->setExtendedClass('\\Aws\\Result');
        return $cls;
    }

    protected function createClassGeneratorForData(Shape $shape): ClassGenerator
    {
        $cls = $this->createClassGeneratorForShape($shape, ['setPrefix' => 'set', 'getPrefix' => 'get']);
        $this->applyHasDataTrait($cls);
        return $cls;
    }

    protected function applyHasDataTrait(ClassGenerator $cls):void
    {
        $cls->addTrait('\\Aws\\HasDataTrait');
        $cls->addMethodFromGenerator(MethodGenerator::fromArray([
            'name' => '__construct',
            'parameters' => [
                ParameterGenerator::fromArray([
                    'name' => 'data',
                    'type' => 'array',
                    'defaultValue' => []
                ])
            ],
            'body' => '$this->data = $data;'
        ]));
        $this->applyInterfaces($cls, '\ArrayAccess');
    }

    protected function createClassGeneratorForShape(Shape $shape, array $options = []): ClassGenerator
    {
        $cls = $this->createClassGenerator([
            'name' => $this->resolveName($shape),
            'namespaceName' => $this->resolveNamespace($shape),
            'docBlock' => $docBlock = (new DocBlockGenerator())->setWordWrap(false),
        ]);

        if($shape instanceof StructureShape) {
            $this->applyStructureShape($cls, $shape, $options);
          //  return $cls;
        } else if($shape instanceof ListShape) {
            $this->applyListShape($cls, $shape, $options);
           // return $cls;
          //  $members = [$shape->getName() => $shape];
        } else if($shape instanceof MapShape) {
            $this->applyMapShape($cls, $shape, $options);

           // $shapeValue = $shape->getValue();
            /*if($shapeValue instanceof StructureShape) {
                $members = $shapeValue->getMembers();
            } else {
                throw new \LogicException('todo');
            }*/
        } else {
            throw new \LogicException('todo');
        }
        return $cls;

        $requiredMembers = $shape['required'] ?? [];

        foreach($members as $memberName => $member) {

            $phpType = $fqcn = null;
            $returnType = '';
            $docType = [];

            if(!in_array($memberName, $requiredMembers)) {
                $returnType = '?';
                $docType[] = 'null';
            }

            if($member instanceof StructureShape) {
                $docType[] = $fqcn = $this->resolveFqcn($member);
                $returnType .= $fqcn;
            } else if($member instanceof ListShape) {
                $fqcn = $this->resolveFqcn($member);
                $docType = ['array', $fqcn, $this->resolveFqcn($member->getMember()) .'[]'];
                $returnType = $fqcn;
            } else if($member instanceof MapShape) {
                //todo?
                $fqcn = $this->resolveFqcn($member);
                $docType = ['array', $fqcn . '[]'];
                $returnType = 'array';
            } else {
                $docType[] = $phpType = $this->resolvePhpType($member);
                $returnType .= $phpType;
            }

            $docBlock->setTag(new PropertyTag(
                $memberName,
                $docType
            ));

            if($getPrefix !== null) {
                $cls->addMethodFromGenerator(
                    MethodGenerator::fromArray([
                        'name' => $getPrefix . $memberName,
                        'returnType' => $returnType,
                        'body' => $phpType ?
                            sprintf('return $this[\'%s\'];', $memberName) :
                            sprintf('return $this[\'%1$s\'] ? new %2$s($this[\'%1$s\']) : null;', $memberName, $fqcn),
                        'docBlock' => DocBlockGenerator::fromArray([
                            'tags' => [
                                new ReturnTag($docType)
                            ]
                        ])
                    ])
                );
            }

            if($setPrefix !== null) {
                $cls->addMethodFromGenerator(
                    MethodGenerator::fromArray([
                        'name' => $setPrefix . $memberName,
                        'parameters' => [ParameterGenerator::fromArray([
                            'name' => 'value',
                            'type' => $returnType,
                        ])],
                        'body' => sprintf("\$this['%s'] = \$value;\nreturn \$this;", $memberName),
                        'docBlock' => DocBlockGenerator::fromArray([
                            'tags' => [
                                new VarTag(null, $docType),
                                new ReturnTag('static')
                            ]
                        ])
                    ])
                );
            }
        }

        return $cls;
    }

    protected function applyStructureShape(ClassGenerator $cls, StructureShape $shape, array $options): void
    {
        $requiredMembers = $shape['required'] ?? [];
        foreach($shape->getMembers() as $memberName => $member) {
            $this->applyMember($cls, $shape, $memberName, $member, [
                'required' => in_array($memberName, $requiredMembers)
            ] + $options);
        }
    }

    protected function applyListShape(ClassGenerator $cls, ListShape $shape, array $options): void
    {
        $member = $shape->getMember();

        $this->applyInterfaces($cls, '\IteratorAggregate', '\ArrayAccess', '\Countable');
        $cls->addMethodFromGenerator(
            MethodGenerator::fromArray([
                'name' => 'getIterator',
                'body' => sprintf(
                    'return new \\%s\\CreateObjectIterator(new \ArrayIterator($this->data), %s::class);',
                    $this->namespace, $this->resolveFqcn($member)
                )
            ])
        );
    }

    protected function applyMapShape(ClassGenerator $cls, MapShape $shape, array $options): void
    {
        $this->applyMember($cls, $shape, $shape->getName(), $shape->getValue(), $options);
    }

    protected function applyMember(ClassGenerator $cls, Shape $shape, string $memberName, Shape $member, array $options): void
    {
        $required = $options['required'] ?? false;

        if($member instanceof StructureShape) {
            $fqcn = $this->resolveFqcn($member);
            $docTypes = $required ? ['null', $fqcn] : [$fqcn];
            $returnType = ($required ? '' : '?') . $fqcn;

            $getBody = sprintf("return \$this['%s'] ? new %s(\$this['%s']) : null;", $memberName, $fqcn, $memberName);
            $setBody = sprintf("\$this['%s'] = \$value;\nreturn \$this;", $memberName);

        } else if($member instanceof ListShape) {
            $fqcn = $this->resolveFqcn($member);
            $docTypes = ['array', $fqcn, $this->resolveFqcn($member->getMember()) . '[]'];
            $returnType =  $fqcn;

            $getBody = sprintf("return new %s(\$this['%s'] ?? []);",  $fqcn, $memberName);
            $setBody = sprintf("\$this['%s'] = \$value;\nreturn \$this;", $memberName);

        } else if($member instanceof MapShape) {
            $fqcn = $this->resolveFqcn($member);
            $docTypes = ['array', $this->resolveFqcn($member->getValue()) . '[]'];
            $returnType = 'array';

            $getBody = '//todo';
            $setBody = '//todo';

        } else {
            $phpType = $this->resolvePhpType($member);
            $docTypes = $required ? ['null', $phpType] : [$phpType];
            $returnType = ($required ? '' : '?') . $phpType;

            $getBody = sprintf("return \$this['%s'];", $memberName);
            $setBody = sprintf("\$this['%s'] = \$value;\nreturn \$this;", $memberName);
        }

        $this->applyPropertyTag($cls, $memberName, $docTypes);
        $this->applyGetter($cls, $memberName, $returnType, $docTypes, $getBody, $options);
        $this->applySetter($cls, $memberName, $returnType, $docTypes, $setBody, $options);
    }

    protected function applyPropertyTag(ClassGenerator $cls, string $name, array $types): void
    {
        $cls->getDocBlock()
            ->setTag(new PropertyTag($name, $types));
    }

    protected function applyGetter(ClassGenerator $cls, string $name, string $returnType, array $docTypes, string $body, array $options): void
    {
        if(null !== $prefix = $options['getPrefix'] ?? null) {
            $cls->addMethodFromGenerator(
                MethodGenerator::fromArray([
                    'name' => $prefix . $name,
                    'returnType' => $returnType,
                    'body' =>  $body,
                    'docBlock' => DocBlockGenerator::fromArray([
                        'tags' => [
                            new ReturnTag($docTypes)
                        ]
                    ])
                ])
            );
        }
    }

    protected function applySetter(ClassGenerator $cls, string $name, string $returnType, array $docTypes, string $body, array $options): void
    {
        if(null !== $prefix = $options['setPrefix'] ?? null) {
            $cls->addMethodFromGenerator(
                MethodGenerator::fromArray([
                    'name' => $prefix . $name,
                    'parameters' => [ParameterGenerator::fromArray([
                        'name' => 'value',
                        'type' => $returnType,
                    ])],
                    'body' => $body,
                    'docBlock' => DocBlockGenerator::fromArray([
                        'tags' => [
                            new VarTag(null, $docTypes),
                            new ReturnTag('static')
                        ]
                    ])
                ])
            );
        }
    }

    protected function applyInterfaces(ClassGenerator $cls, string ...$interfaces)
    {
        $existing = $cls->getImplementedInterfaces();
        foreach($interfaces as $interface) {
            $existing[] = $interface;
        }
        $cls->setImplementedInterfaces(array_unique($existing));
    }

    protected function createClassGeneratorForService(Service $service): ClassGenerator
    {
        $name = $this->resolveName($service);

        $cls = $this->createClassGenerator([
            'name' => $name . 'Client',
            'namespaceName' => $namespace = $this->resolveNamespace($service),
            'extendedClass' => "\\Aws\\$name\\{$name}Client",
            'docBlock' => $docs = (new DocBlockGenerator())->setWordWrap(false),
        ]);

        $cls->addTrait("\\{$this->namespace}\\ClientTrait");

        foreach($service->getOperations() as $name => $operation) {

            $paramTypes = ['array'];
            $input = $operation->getInput();
            if(count($input->getMembers()) > 0) {
                $paramTypes[] = $this->resolveFqcn($input);
            }

            $returnType = $this->resolveFqcn($operation->getOutput());

            $docs->setTags([
                new GenericTag('method',
                    sprintf('%s %s(%s $input = [])', $returnType, lcfirst($name), implode($paramTypes, '|'))
                ),
                new GenericTag('method',
                    sprintf('\GuzzleHttp\Promise\Promise %sAsync(%s $input = [])', lcfirst($name), implode($paramTypes, '|'))
                )
            ]);
        }

        return $cls;
    }

    protected function createFileGeneratorForClassGenerator(ClassGenerator $classGenerator): FileGenerator
    {
        $file = FileGenerator::fromArray([
            'filename' => str_replace('\\', '/', $classGenerator->getNamespaceName() .'\\'. $classGenerator->getName()) . '.php',
            'class' => $classGenerator,
        ]);

        $classGenerator->setContainingFileGenerator($file);
        return $file;
    }

    protected function createOtherClassGenerators(): array
    {
        $classes = [
            InterfaceGenerator::fromReflection(new ClassReflection(InputInterface::class)),
            TraitGenerator::fromReflection(new ClassReflection(ClientTrait::class)),
            ClassGenerator::fromReflection(new ClassReflection(CreateObjectIterator::class)),
            $this->createClassGenerator([
                'name' => 'AbstractInput',
                'flags' => ClassGenerator::FLAG_ABSTRACT,
                'interfaces' => [$this->namespace .'\\InputInterface'],
                'hasDataTrait' => true,
                'methods' => [
                    MethodGenerator::fromArray([
                        'name' => 'create',
                        'flags'=> MethodGenerator::FLAG_STATIC,
                        'body' => 'return new static();',
                        'docBlock' => DocBlockGenerator::fromArray([
                            'tags' => [new ReturnTag('static')]
                        ])
                    ])
                ],
            ])
        ];

        foreach($classes as $cls) {
            $cls->setNamespaceName($this->namespace);
            $this->createFileGeneratorForClassGenerator($cls);
        }
        return $classes;
    }

    protected function createClassGenerator(array $params, string $type = ClassGenerator::class): ClassGenerator
    {
        $cls = call_user_func([$type, 'fromArray'], $params);

        if($interfaces = $params['interfaces'] ?? null) {
            $this->applyInterfaces($cls, ...$interfaces);
        }

        if($params['hasDataTrait'] ?? false) {
            $this->applyHasDataTrait($cls);
        }
        $this->createFileGeneratorForClassGenerator($cls);
        return $cls;
    }

    protected function resolveName(AbstractModel $model): string
    {
        if($model instanceof Service) {
            if(!$name = $model->getMetadata('namespace')) {
                $name = $model->getMetadata('targetPrefix');

                if(stripos($name, 'DynamoDBStreams') !== false) {
                    $name = 'DynamoDbStreams';
                } else if(stripos($name, 'DynamoDB') !== false) {
                    $name = 'DynamoDb';
                }
            }
        } else {

            if($model instanceof StructureShape && count($model->getMembers()) === 0) {
                $name = 'Empty Structure Shape';
            } else {
                $name = $model['name'];
            }
        }

        if(!is_string($name)) {
            throw new \LogicException("Could not resolve name for " . get_class($model));
        }

        return $name;
    }

    protected function resolveNamespace(AbstractModel $model): string
    {
        $hash = $this->context->hash($model);
        $service = $this->context->getClassService($hash);
        return trim($this->namespace, '\\') .'\\'. ($service ? $this->resolveName($service) : 'Common');
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
            case 'double':
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
