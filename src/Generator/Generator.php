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
use function Aws\manifest;
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

    /** @var callable */
    protected $filter;

    /** @var NameResolver  */
    protected $nameResolver;

    protected $debugIndent = 0;

    public function __construct(?ApiProvider $provider = null)
    {
        $this->provider = $provider ?? ApiProvider::defaultProvider();
        $this->logger = new NullLogger();
        $this->filter = function() { return true; };
        $this->nameResolver = new NameResolver();
    }

    /**
     * @param string $namespace
     * @return static
     */
    public function setNamespace(string $namespace)
    {
        $this->namespace = trim($namespace,'\\');
        $this->logger->notice("Set namespace to: {$this->namespace}");
        return $this;
    }

    /**
     * @param callable $filter
     */
    public function setFilter(callable $filter): void
    {
        $this->filter = $filter;
    }

    public function addService(string $name, string $version = 'latest')
    {
        if(!$api = ApiProvider::resolve($this->provider, 'api', $name, $version)) {
            throw new GeneratorException("API does not exist '$name' at version '$version'");
        }

        $namespace = manifest($name)['namespace'];

        if($this->services[$namespace] ?? null) {
            throw new GeneratorException("Service '$name' already added");
        }

        $api['metadata']['namespace'] = $namespace;
        $this->services[$namespace] = new Service($api, $this->provider);

        $this->logger->notice(sprintf('Added service: %s %s (%s) at namespace %s', $name, $version, $api['metadata']['protocol'], $namespace));

        return $this;
    }

    /**
     * @return \Generator|ClassGenerator[]
     */
    public function __invoke(): \Generator
    {
        yield from new \ArrayIterator($this->createOtherClassGenerators());

        foreach ($this->services as $serviceName => $service) {

            $this->context = new Context($service);
            $this->context->setLogger($this->logger);
            $this->nameResolver->setContext($this->context);

            $this->visitModel($service);

            foreach($this->context->getClassHashes() as $hash) {
                yield $this->createClassGeneratorForHash($hash);
            }
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
        $this->context->registerClass($service);

        foreach($service->getOperations() as $operationName => $operation) {
            $this->visitModel($operation);
        }

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
        } else if($this->context->registerClass($structureShape)) {
            $this->debugLog('Skipped: already visited');
        } else {
            foreach ($structureShape->getMembers() as $shape) {
                $this->visitModel($shape);
            }
        }
    }

    protected function visitShape(Shape $shape): void
    {
        if($shape instanceof StructureShape) {
            $this->visitModel($shape);
        } else if($shape instanceof ListShape) {
            $this->context->registerClass($shape);
            $member = $shape->getMember();
            if($member instanceof StructureShape) {
                $this->visitModel($member);
            }
        } else if($shape instanceof MapShape) {
            $this->context->registerClass($shape);
            $this->visitModel($shape->getValue());
        } else {
            $this->debugLog(sprintf('Did nothing: %s (%s) ', $this->nameResolver->resolve($shape), get_class($shape)));
        }
    }

    protected function createClassGeneratorForHash(string $hash): ClassGenerator
    {
        $model = $this->context->getClassModel($hash);

        if($model instanceof Service) {
            return $this->createClassGeneratorForService($model);
        } else if($model instanceof Shape) {
            if ($operation = $this->context->getClassOperation($hash)) {
                if($model === $operation->getInput()) {
                    return $this->createClassGeneratorForInput($model);
                } else if($model === $operation->getOutput()) {
                    return $this->createClassGeneratorForOutput($model);
                }
            }
            return $this->createClassGeneratorForData($model);
        } else {
            throw new \LogicException('TODO: '. get_class($model));
        }
    }

    protected function createClassGeneratorForInput(Shape $shape): ClassGenerator
    {
        $cls = $this->createClassGeneratorForShape($shape, ['setPrefix' => '']);
        $cls->setExtendedClass($this->namespace .'\\AbstractInput');
        return $cls;
    }

    protected function createClassGeneratorForOutput(Shape $shape): ClassGenerator
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
            'name' => $this->nameResolver->resolve($shape),
            'namespaceName' => $this->resolveNamespace($shape),
            'docBlock' => $docBlock = (new DocBlockGenerator())->setWordWrap(false),
        ]);

        if($shape instanceof StructureShape) {
            $this->applyStructureShape($cls, $shape, $options);
        } else if($shape instanceof ListShape) {
            $this->applyListShape($cls, $shape, $options);
        } else if($shape instanceof MapShape) {
            $this->applyMapShape($cls, $shape, $options);
        } else {
            throw new \LogicException('todo');
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

        if($member instanceof StructureShape) {
            $body = sprintf(
                'return new \\%s\\CreateObjectIterator(new \ArrayIterator($this->data), %s::class);',
                $this->namespace,
                $this->resolveFqcn($member)
            );
        } else {
           $body = 'return new \ArrayIterator($this->data);';
        }

        $cls->addMethodFromGenerator(
            MethodGenerator::fromArray([
                'name' => 'getIterator',
                'body' =>  $body
            ])
        );
    }

    protected function applyMapShape(ClassGenerator $cls, MapShape $shape, array $options): void
    {
        $member = $shape->getValue();
        $this->applyInterfaces($cls, '\IteratorAggregate', '\ArrayAccess', '\Countable');

        if($member instanceof StructureShape) {
            $body = sprintf(
                'return new \\%s\\CreateObjectIterator(new \ArrayIterator($this->data), %s::class);',
                $this->namespace,
                $this->resolveFqcn($member)
            );
        } else {
            $body = 'return new \ArrayIterator($this->data);';
        }

        $cls->addMethodFromGenerator(
            MethodGenerator::fromArray([
                'name' => 'getIterator',
                'body' =>  $body
            ])
        );

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
            $listMember = $member->getMember();
            if($listMember instanceof StructureShape) {
                $docTypes = ['array', $fqcn, $this->resolveFqcn($member->getMember()) . '[]'];
                $returnType = $fqcn;

                $getBody = sprintf("return new %s(\$this['%s'] ?? []);", $fqcn, $memberName);
                $setBody = sprintf("\$this['%s'] = \$value;\nreturn \$this;", $memberName);
            } else if($listMember instanceof MapShape) {

                $docTypes = ['array'];
                $returnType = 'array';

                $getBody = sprintf("return \$this['%s'];", $memberName);
                $setBody = sprintf("\$this['%s'] = \$value;\nreturn \$this;", $memberName);

            } else {
                $phpType = $this->resolvePhpType($listMember);
                $docTypes = [$phpType .'[]'];
                $returnType = 'array';

                $getBody = sprintf("return \$this['%s'];", $memberName);
                $setBody = sprintf("\$this['%s'] = \$value;\nreturn \$this;", $memberName);
            }

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

        switch(strtolower($memberName)) {
            case 'count':
                $memberName .= '_';
        }

        $this->applyGetter($cls, $memberName, $returnType, $docTypes, $getBody, $options);
        $this->applySetter($cls, $memberName, $returnType, $docTypes, $setBody, $options);
    }

    protected function applyGetter(ClassGenerator $cls, string $name, string $returnType, array $docTypes, string $body, array $options): void
    {
        if(null !== $prefix = $options['getPrefix'] ?? null) {
            $cls->addMethodFromGenerator(
                MethodGenerator::fromArray([
                    'name' => $prefix ? $prefix . ucfirst($name) : lcfirst($name),
                    'returnType' => $returnType,
                    'body' =>  $body,
                    'docBlock' => DocBlockGenerator::fromArray([
                        'tags' => [
                            new ReturnTag($docTypes)
                        ]
                    ])->setWordWrap(false)
                ])
            );
        }
    }

    protected function applySetter(ClassGenerator $cls, string $name, string $returnType, array $docTypes, string $body, array $options): void
    {
        if(null !== $prefix = $options['setPrefix'] ?? null) {
            $cls->addMethodFromGenerator(
                MethodGenerator::fromArray([
                    'name' => $prefix ? $prefix . ucfirst($name) : lcfirst($name),
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
                    ])->setWordWrap(false)
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
        $name = $this->nameResolver->resolve($service);

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
                        ])->setWordWrap(false)
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

    protected function resolveNamespace(AbstractModel $model): string
    {
        $service = $this->context->getService();
        return trim($this->namespace, '\\') .'\\'. $this->nameResolver->resolve($service);
    }


    protected function resolveFqcn(AbstractModel $model): string
    {
        return '\\'. trim($this->resolveNamespace($model) .'\\'. $this->nameResolver->resolve($model), '\\');
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
            case 'float':
                return 'float';
            case 'blob':
                return 'string';
            case 'boolean':
                return 'bool';
            default:
                throw new \LogicException('TODO resolvePhpType: '. $type);
        }
    }

    protected function debugEnter(AbstractModel $model): void
    {
        $this->debugLog(sprintf('Entering %s (%s)', $this->nameResolver->resolve($model), get_class($model)));
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
