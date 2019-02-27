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

        if ($model instanceof Service) {
            $this->visitService($model);
        } else if ($model instanceof Operation) {
            if(($this->filter)($model, $this->context)) {
                $this->visitOperation($model);
            } else {
                $this->debugLog('Skipped: by filter');
            }
        } else if ($model instanceof StructureShape) {
            $this->visitStructureShape($model);
        } else if($model instanceof ListShape) {
            $this->visitListShape($model);
        } else if($model instanceof MapShape) {
            $this->visitMapShape($model);
        } else {
            $this->debugLog(sprintf('Did nothing: %s (%s) ', $this->nameResolver->resolve($model), get_class($model)));
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

    protected function visitStructureShape(StructureShape $shape): void
    {
        if(count($shape->getMembers()) === 0) {
            $this->debugLog('Skipped: empty StructureShape');
        } else if($this->context->registerClass($shape)) {
            $this->debugLog('Skipped: already visited');
        } else {
            foreach ($shape->getMembers() as $member) {
                $this->visitModel($member);
            }
        }
    }

    protected function visitMapShape(MapShape $shape): void
    {
        $this->context->registerClass($shape);
        $this->visitModel($shape->getValue());
    }

    protected function visitListShape(ListShape $shape): void
    {
        $this->context->registerClass($shape);
        $this->visitModel($shape->getMember());
    }

    protected function createClassGeneratorForHash(string $hash): ClassGenerator
    {
        $model = $this->context->getClassModel($hash);

        if($model instanceof Service) {
            return $this->createClassGeneratorForService($model);
        } else if($model instanceof Shape) {
            if ($operation = $this->context->getClassOperation($hash)) {
                if($model === $operation->getInput()) {
                    return $this->createClassGeneratorForInput($model, $operation->getOutput());
                } else if($model === $operation->getOutput()) {
                    return $this->createClassGeneratorForOutput($model);
                }
            }
            return $this->createClassGeneratorForData($model);
        } else {
            throw new \LogicException('TODO: '. get_class($model));
        }
    }

    protected function createClassGeneratorForInput(Shape $shape, Shape $output): ClassGenerator
    {
        $cls = $this->createClassGeneratorForShape($shape, ['setPrefix' => '']);
        $cls->setExtendedClass($this->namespace .'\\AbstractInput');

        if(NameResolver::EMPTY_STRUCTURE_SHAPE !== $name = $this->nameResolver->resolve($output)) {
            $cls->addConstant('OUTPUT_CLASS', $this->resolveFqcn($output));
        }
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
        $this->applyMapOrListMember($cls, $member = $shape->getMember(), $options);

        if($this->isPhpType($member)) {
            $type = $this->resolvePhpType($member);
            $body = "\$this->data[] = \$value;\nreturn \$this;";
        } else {
            $type = $this->resolveFqcn($member);
            $body = "\$this->data[] = \$value->toArray();\nreturn \$this;";
        }

        $this->applyMethod($cls, [
            'name' => 'add',
            'body' => $body,
            'parameters' =>$this->createParameterGenerators(
                ['name' => 'value', 'type' => $type]
            )
        ]);
    }

    protected function applyMapShape(ClassGenerator $cls, MapShape $shape, array $options): void
    {
        $this->applyMapOrListMember($cls, $value = $shape->getValue(), $options);

        if($this->isPhpType($value)) {
            $type = $this->resolvePhpType($value);
            $body = "\$this->data[\$key] = \$value;\nreturn \$this;";

        } else {
            $type = $this->resolveFqcn($value);
            $body = "\$this->data[\$key] = \$value->toArray();\nreturn \$this;";

        }

        $this->applyMethod($cls, [
            'name' => 'add',
            'body' => $body,
            'parameters' =>$this->createParameterGenerators(
                ['name' => 'key', 'type' => $this->resolvePhpType($shape->getKey())],
                ['name' => 'value', 'type' => $type]
            )
        ]);
    }

    protected function applyMapOrListMember(ClassGenerator $cls, Shape $member, array $options): void
    {
        $this->applyInterfaces($cls, '\IteratorAggregate', '\ArrayAccess', '\Countable');

        if($this->isPhpType($member)) {
            $body = 'return new \ArrayIterator($this->data);';
        } else {
            $body = sprintf(
                'return new \\%s\\CreateObjectIterator(new \ArrayIterator($this->data), %s::class);',
                $this->namespace,
                $this->resolveFqcn($member)
            );
        }

        $this->applyMethod($cls, [
            'name' => 'getIterator',
            'body' =>  $body
        ]);
    }

    protected function applyMember(ClassGenerator $cls, Shape $shape, string $memberName, Shape $member, array $options): void
    {
        $required = $options['required'] ?? false;

        $fqcn = $this->resolveFqcn($member);


        if($member instanceof StructureShape) {
            //structure is always a class
            $docTypes = $required ? ['null', $fqcn] : [$fqcn];
            $returnType = ($required ? '' : '?') . $fqcn;

            $getBody = sprintf("return \$this['%s'] ? new %s(\$this['%s']) : null;", $memberName, $fqcn, $memberName);
            $setBody = sprintf("\$this['%s'] = \$value;\nreturn \$this;", $memberName);

        } else if($member instanceof ListShape || $member instanceof MapShape) {
            //list+map may be an array of class or an array of php types
            $innerMember = $member instanceof ListShape ? $member->getMember() : $member->getValue();

            if($this->isPhpType($innerMember)) {
                $phpType = $this->resolvePhpType($innerMember);
                $docTypes = [$phpType .'[]'];
                $returnType = 'array';

                $getBody = sprintf("return new \$this['%s'];", $memberName);
                $setBody = sprintf("\$this['%s'] = \$value;\nreturn \$this;", $memberName);
            } else {
                $docTypes = ['array', $fqcn, $this->resolveFqcn($innerMember) . '[]'];
                $returnType = $fqcn;

                $getBody = sprintf("return new %s(\$this['%s'] ?? []);", $fqcn, $memberName);
                $setBody = sprintf("\$this['%s'] = \$value;\nreturn \$this;", $memberName);
            }

        } else {
            //if its not struct or list or map, its a php type
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
            $this->applyMethod($cls, [
                    'name' => $prefix ? $prefix . ucfirst($name) : $name,
                    'returnType' => $returnType,
                    'body' =>  $body,
                    'docBlock' => [
                        'tags' => [
                            new ReturnTag($docTypes)
                        ]
                    ]
                ]
            );
        }
    }

    protected function applySetter(ClassGenerator $cls, string $name, string $returnType, array $docTypes, string $body, array $options): void
    {
        if(null !== $prefix = $options['setPrefix'] ?? null) {
            $this->applyMethod($cls, [
                'name' => $prefix ? $prefix . ucfirst($name) : $name,
                'parameters' => $this->createParameterGenerators([
                    'name' => 'value',
                    'type' => $returnType,
                ]),
                'body' => $body,
                'docBlock' => [
                    'tags' => [
                        new VarTag(null, $docTypes),
                        new ReturnTag('static')
                    ]
                ]
            ]);
        }
    }

    protected function applyMethod(ClassGenerator $cls, array $spec): MethodGenerator
    {
        $cls->addMethodFromGenerator($gen = $this->createMethodGenerator($spec));
        return $gen;
    }

    protected function createMethodGenerator(array $spec): MethodGenerator
    {
        if(is_array($spec['docBlock'] ?? null)) {
            $spec['docBlock'] = DocBlockGenerator::fromArray($spec['docBlock'])->setWordWrap(false);
        }
        return MethodGenerator::fromArray($spec);
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
                'constants' => [
                    ['OUTPUT_CLASS', null]
                ],
                'methods' => [
                    $this->createMethodGenerator([
                        'name' => 'create',
                        'flags'=> MethodGenerator::FLAG_STATIC,
                        'body' => 'return new static();',
                        'docBlock' => [
                            'tags' => [new ReturnTag('static')]
                        ]
                    ]),
                    $this->createMethodGenerator([
                        'name' => 'getOutputClass',
                        'body' => 'return static::OUTPUT_CLASS;',
                        'returnType' => 'string',
                        'docBlock' => [
                            'tags' => [new ReturnTag('string')]
                        ]
                    ]),
                ],
            ])
        ];

        foreach($classes as $cls) {
            $cls->setNamespaceName($this->namespace);
            $this->createFileGeneratorForClassGenerator($cls);
        }
        return $classes;
    }

    protected function createClassGenerator(array $spec, string $type = ClassGenerator::class): ClassGenerator
    {
        /** @var ClassGenerator $cls */
        $cls = call_user_func([$type, 'fromArray'], $spec);

        if($interfaces = $spec['interfaces'] ?? null) {
            $this->applyInterfaces($cls, ...$interfaces);
        }

        if($spec['hasDataTrait'] ?? false) {
            $this->applyHasDataTrait($cls);
        }

        $cls->addConstants($spec['constants'] ?? []);


        $this->createFileGeneratorForClassGenerator($cls);
        return $cls;
    }

    protected function createParameterGenerators(array ...$specs): array
    {
        return array_map([$this, 'createParameterGenerator'], $specs);
    }

    protected function createParameterGenerator(array $spec): ParameterGenerator
    {
        return ParameterGenerator::fromArray($spec);
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

    protected function isPhpType(Shape $shape): bool
    {
        return !in_array($shape->getType(), ['list', 'map', 'structure']);
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
