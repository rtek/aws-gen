<?php declare(strict_types=1);

namespace Rtek\AwsGen\Generator;

use Aws\Api\AbstractModel;
use Aws\Api\ListShape;
use Aws\Api\MapShape;
use Aws\Api\Operation;
use Aws\Api\Service;
use Aws\Api\Shape;
use Aws\Api\StructureShape;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\DocBlock\Tag\GenericTag;
use Zend\Code\Generator\DocBlock\Tag\ParamTag;
use Zend\Code\Generator\DocBlock\Tag\ReturnTag;
use Zend\Code\Generator\DocBlock\Tag\VarTag;
use Zend\Code\Generator\DocBlockGenerator;
use Zend\Code\Generator\MethodGenerator;
use Zend\Code\Generator\ParameterGenerator;

class ServiceGenerator implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    use GeneratorHelperTrait;

    /** @var Service */
    protected $service;

    /** @var Context */
    protected $context;

    /** @var string */
    protected $namespace = '\\';

    /** @var callable */
    protected $filter;

    /** @var NameResolver  */
    protected $nameResolver;

    protected $debugIndent = 0;

    public function __construct(string $namespace, Service $service)
    {
        $this->namespace = trim($namespace, '\\');
        $this->service = $service;
        $this->logger = new NullLogger();
        $this->filter = function () {
            return true;
        };
    }

    /**
     * @param callable $filter
     */
    public function setFilter(callable $filter): void
    {
        $this->filter = $filter;
    }

    /**
     * @return \Generator|ClassGenerator[]
     */
    public function __invoke(): \Generator
    {
        $this->nameResolver = new NameResolver();
        $this->context = new Context($this->service);
        $this->context->setLogger($this->logger);

        $this->visitModel($this->service);

        foreach ($this->context->getClassHashes() as $hash) {
            yield $this->createClassGeneratorForHash($hash);
        }
    }

    protected function visitModel(AbstractModel $model): void
    {
        $this->debugEnter($model);

        if ($model instanceof Service) {
            $this->visitService($model);
        } elseif ($model instanceof Operation) {
            if (($this->filter)($model, $this->context)) {
                $this->visitOperation($model);
            } else {
                $this->debugLog('Skipped: by filter');
            }
        } elseif ($model instanceof StructureShape) {
            $this->visitStructureShape($model);
        } elseif ($model instanceof ListShape) {
            $this->visitListShape($model);
        } elseif ($model instanceof MapShape) {
            $this->visitMapShape($model);
        } else {
            $this->debugLog(sprintf('Did nothing: %s (%s) ', $this->nameResolver->resolve($model), get_class($model)));
        }

        $this->debugExit();
    }

    protected function visitService(Service $service): void
    {
        $this->context->registerClass($service);
        foreach ($service->getOperations() as $operationName => $operation) {
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
        if (count($shape->getMembers()) === 0) {
            $this->debugLog('Skipped: empty StructureShape');
        } elseif ($this->context->registerClass($shape)) {
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

        if ($model instanceof Service) {
            return $this->createClassGeneratorForService($model);
        }
        if ($model instanceof Shape) {
            if ($operation = $this->context->getClassOperation($hash)) {
                if ($model === $operation->getInput()) {
                    return $this->createClassGeneratorForInput($model, $operation->getOutput());
                }
                if ($model === $operation->getOutput()) {
                    return $this->createClassGeneratorForOutput($model);
                }
            }
            return $this->createClassGeneratorForData($model);
        }

        throw new \LogicException('Unexpected shape class: ' . get_class($model));
    }


    protected function createClassGeneratorForService(Service $service): ClassGenerator
    {
        $name = $this->nameResolver->resolve($service);

        $cls = $this->createClassGenerator([
            'name' => $name . 'Client',
            'namespaceName' => $namespace = $this->resolveNamespace($service),
            'extendedClass' => "\\Aws\\$name\\{$name}Client",
            'docBlock' => $docs = $this->createDocBlockGenerator()
        ]);

        $cls->addTrait("\\{$this->namespace}\\ClientTrait");

        foreach ($service->getOperations() as $name => $operation) {
            $paramTypes = ['array'];
            $input = $operation->getInput();
            if (count($input->getMembers()) > 0) {
                $paramTypes[] = $this->resolveFqcn($input);
            }

            $output = $operation->getOutput();
            $returnType = $this->nameResolver->resolve($output) === NameResolver::EMPTY_STRUCTURE_SHAPE ? '\Aws\Result' : $this->resolveFqcn($output);

            $docs->setTags([
                new GenericTag(
                    'method',
                    sprintf('%s %s(%s $input = [])', $returnType, lcfirst($name), implode($paramTypes, '|'))
                ),
                new GenericTag(
                    'method',
                    sprintf('\GuzzleHttp\Promise\Promise %sAsync(%s $input = [])', lcfirst($name), implode($paramTypes, '|'))
                )
            ]);
        }

        return $cls;
    }

    protected function createClassGeneratorForInput(StructureShape $shape, Shape $output): ClassGenerator
    {
        $cls = $this->createClassGeneratorForShape($shape, ['setPrefix' => '']);
        $cls->setExtendedClass($this->namespace . '\\AbstractInput');

        if (NameResolver::EMPTY_STRUCTURE_SHAPE !== $name = $this->nameResolver->resolve($output)) {
            $cls->addConstant('OUTPUT_CLASS', $this->resolveFqcn($output));
        }

        //foreach required member... add it to create()
        $params = [];
        foreach (array_intersect_key($shape->getMembers(), array_flip($shape['required'] ?? [])) as $name => $member) {
            $params[] = $this->createParameterGenerator([
                'name' => $name,
                'type' => $this->isPhpType($member) ? $this->resolvePhpType($member) : $this->resolveFqcn($member)
            ]);
        }

        $setters = array_map(function (ParameterGenerator $param) {
            return sprintf('->%s($%s)', $param->getName(), $param->getName());
        }, $params);

        $body = sprintf('return (new static())%s;', implode('', $setters));

        $tags = array_map(function (ParameterGenerator $param) {
            return new ParamTag($param->getName(), $param->getType());
        }, $params);

        $tags[] = new ReturnTag('static');

        $this->applyMethod($cls, [
            'name' => 'create',
            'flags' => MethodGenerator::FLAG_STATIC,
            'parameters' => $params,
            'body' => $body,
            'docBlock' => [
                'tags' => $tags,
            ],
        ]);

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

    protected function createClassGeneratorForShape(Shape $shape, array $options = []): ClassGenerator
    {
        $cls = $this->createClassGenerator([
            'name' => $this->nameResolver->resolve($shape),
            'namespaceName' => $this->resolveNamespace($shape),
            'docBlock' => $docBlock = (new DocBlockGenerator())->setWordWrap(false),
        ]);

        if ($shape instanceof StructureShape) {
            $this->applyStructureShape($cls, $shape, $options);
        } elseif ($shape instanceof ListShape) {
            $this->applyListShape($cls, $shape, $options);
        } elseif ($shape instanceof MapShape) {
            $this->applyMapShape($cls, $shape, $options);
        } else {
            throw new \LogicException('todo');
        }
        return $cls;
    }

    protected function applyStructureShape(ClassGenerator $cls, StructureShape $shape, array $options): void
    {
        $requiredMembers = $shape['required'] ?? [];
        foreach ($shape->getMembers() as $memberName => $member) {
            $this->applyMember($cls, $shape, $memberName, $member, [
                'required' => in_array($memberName, $requiredMembers)
            ] + $options);
        }
    }

    protected function applyListShape(ClassGenerator $cls, ListShape $shape, array $options): void
    {
        $this->applyMapOrListMember($cls, $member = $shape->getMember(), $options);

        if ($this->isPhpType($member)) {
            $type = $this->resolvePhpType($member);
            $body = "\$this->data[] = \$value;\nreturn \$this;";
        } else {
            $type = $this->resolveFqcn($member);
            $body = "\$this->data[] = \$value->toArray();\nreturn \$this;";
        }

        $this->applyMethod($cls, [
            'name' => 'add',
            'body' => $body,
            'parameters' => $this->createParameterGenerators([
                'name' => 'value',
                'type' => $type
            ])
        ]);
    }

    protected function applyMapShape(ClassGenerator $cls, MapShape $shape, array $options): void
    {
        $this->applyMapOrListMember($cls, $value = $shape->getValue(), $options);

        if ($this->isPhpType($value)) {
            $type = $this->resolvePhpType($value);
            $body = "\$this->data[\$key] = \$value;\nreturn \$this;";
        } else {
            $type = $this->resolveFqcn($value);
            $body = "\$this->data[\$key] = \$value->toArray();\nreturn \$this;";
        }

        $this->applyMethod($cls, [
            'name' => 'add',
            'body' => $body,
            'parameters' => $this->createParameterGenerators(
                ['name' => 'key', 'type' => $this->resolvePhpType($shape->getKey())],
                ['name' => 'value', 'type' => $type]
            )
        ]);
    }

    protected function applyMapOrListMember(ClassGenerator $cls, Shape $member, array $options): void
    {
        $this->applyInterfaces($cls, '\IteratorAggregate', '\ArrayAccess', '\Countable');

        if ($this->isPhpType($member)) {
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


        if ($member instanceof StructureShape) {
            //structure is always a class
            $docTypes = $required ? ['null', $fqcn] : [$fqcn];
            $returnType = ($required ? '' : '?') . $fqcn;

            $getBody = sprintf("return \$this['%s'] ? new %s(\$this['%s']) : null;", $memberName, $fqcn, $memberName);
            $setBody = sprintf("\$this['%s'] = \$value;\nreturn \$this;", $memberName);
        } elseif ($member instanceof ListShape || $member instanceof MapShape) {
            //list+map may be an array of class or an array of php types
            $innerMember = $member instanceof ListShape ? $member->getMember() : $member->getValue();

            if ($this->isPhpType($innerMember)) {
                $phpType = $this->resolvePhpType($innerMember);
                $docTypes = [$phpType . '[]'];
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

        switch (strtolower($memberName)) {
            case 'count':
                $memberName .= '_';
        }

        $this->applyGetter($cls, $memberName, $returnType, $docTypes, $getBody, $options);
        $this->applySetter($cls, $memberName, $returnType, $docTypes, $setBody, $options);
    }

    protected function applyGetter(ClassGenerator $cls, string $name, string $returnType, array $docTypes, string $body, array $options): void
    {
        if (null !== $prefix = $options['getPrefix'] ?? null) {
            $this->applyMethod($cls, [
                    'name' => $prefix ? $prefix . ucfirst($name) : $name,
                    'returnType' => $returnType,
                    'body' =>  $body,
                    'docBlock' => [
                        'tags' => [
                            new ReturnTag($docTypes)
                        ]
                    ]
                ]);
        }
    }

    protected function applySetter(ClassGenerator $cls, string $name, string $returnType, array $docTypes, string $body, array $options): void
    {
        if (null !== $prefix = $options['setPrefix'] ?? null) {
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

    protected function resolveNamespace(AbstractModel $model): string
    {
        return trim($this->namespace, '\\') . '\\' . $this->nameResolver->resolve($this->service);
    }

    protected function resolveFqcn(AbstractModel $model): string
    {
        return '\\' . trim($this->resolveNamespace($model) . '\\' . $this->nameResolver->resolve($model), '\\');
    }


    protected function isPhpType(Shape $shape): bool
    {
        return !in_array($shape->getType(), ['list', 'map', 'structure']);
    }

    protected function resolvePhpType(Shape $shape): string
    {
        switch ($type = (string)$shape['type']) {
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
                throw new \LogicException('Unexpected resolvePhpType: ' . $type);
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
        $this->logger->debug($str, ['context' => $this->debugIndent]);
    }
}
