<?php declare(strict_types=1);

namespace Rtek\AwsGen\Generator;

use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\DocBlockGenerator;
use Zend\Code\Generator\FileGenerator;
use Zend\Code\Generator\MethodGenerator;
use Zend\Code\Generator\ParameterGenerator;

/**
 * QOL factories for `Zend\Code`
 */
trait GeneratorHelperTrait
{

    /**
     * Adds `interfaces`, `hasDataTrait`, `constants` to `fromArray($spec)`, and generates a `FileGenerator` wrapper
     *
     * * `interfaces` redirects to `applyInterfaces`
     * * `hasDataTrait` redirects to `applyHasDataTrait`
     * * `constants` redirects to `ClassGenerator::addConstants()`
     *
     * @param array $spec
     * @param string $type
     * @return ClassGenerator
     */
    protected function createClassGenerator(array $spec = [], string $type = ClassGenerator::class): ClassGenerator
    {
        /** @var ClassGenerator $cls */
        $cls = call_user_func([$type, 'fromArray'], $spec);

        if ($interfaces = $spec['interfaces'] ?? null) {
            $this->applyInterfaces($cls, ...$interfaces);
        }

        if ($spec['hasDataTrait'] ?? false) {
            $this->applyHasDataTrait($cls);
        }

        $cls->addConstants($spec['constants'] ?? []);


        $this->createFileGeneratorForClassGenerator($cls);
        return $cls;
    }

    /**
     * Returns a `FileGenerator` for a single `$classGenerator`  with the same namespace
     * @param ClassGenerator $classGenerator
     * @return FileGenerator
     */
    protected function createFileGeneratorForClassGenerator(ClassGenerator $classGenerator): FileGenerator
    {
        $file = FileGenerator::fromArray([
            'filename' => str_replace('\\', '/', $classGenerator->getNamespaceName() . '\\' . $classGenerator->getName()) . '.php',
            'class' => $classGenerator,
        ]);

        $classGenerator->setContainingFileGenerator($file);
        return $file;
    }

    /**
     * Redirects to `createParameterGenerator`
     * @param array ...$specs
     * @return array
     */
    protected function createParameterGenerators(array ...$specs): array
    {
        return array_map([$this, 'createParameterGenerator'], $specs);
    }

    /**
     * Placeholder
     * @param array $spec
     * @return ParameterGenerator
     */
    protected function createParameterGenerator(array $spec): ParameterGenerator
    {
        return ParameterGenerator::fromArray($spec);
    }

    /**
     * Adds a new `MethodGenerator` to `$cls` and returns it
     * @param ClassGenerator $cls
     * @param array $spec
     * @return MethodGenerator
     */
    protected function applyMethod(ClassGenerator $cls, array $spec = []): MethodGenerator
    {
        $cls->addMethodFromGenerator($gen = $this->createMethodGenerator($spec));
        return $gen;
    }

    /**
     * Adds `docBlock` to `fromArray($spec)`
     *
     * * `docBlock` redirects to `createDocBlockGenerator`
     *
     * @param array $spec
     * @return MethodGenerator
     */
    protected function createMethodGenerator(array $spec = []): MethodGenerator
    {
        if (is_array($spec['docBlock'] ?? null)) {
            $spec['docBlock'] = $this->createDocBlockGenerator($spec['docBlock']);
        }
        return MethodGenerator::fromArray($spec);
    }

    /**
     * Applies interfaces to a `ClassGenerator` without removing existing interfaces
     * @param ClassGenerator $cls
     * @param string ...$interfaces
     */
    protected function applyInterfaces(ClassGenerator $cls, string ...$interfaces)
    {
        $existing = $cls->getImplementedInterfaces();
        foreach ($interfaces as $interface) {
            $existing[] = $interface;
        }
        $cls->setImplementedInterfaces(array_unique($existing));
    }

    /**
     * Adds `Aws\HasDataTrait` to the class
     * @param ClassGenerator $cls
     */
    protected function applyHasDataTrait(ClassGenerator $cls): void
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

    /**
     * Creates a `DocBlockGenerator` with `wordWrap=false`
     * @param array $spec
     * @return DocBlockGenerator
     */
    protected function createDocBlockGenerator(array $spec = []): DocBlockGenerator
    {
        return DocBlockGenerator::fromArray($spec)->setWordWrap(false);
    }
}
