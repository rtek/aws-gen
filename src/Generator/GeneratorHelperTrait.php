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
     * Adds `interfaces`, `constants`, `traits` to `fromArray($spec)`, and generates a `FileGenerator` wrapper
     *
     * * `interfaces` redirects to `applyInterfaces`
     * * `constants` redirects to `ClassGenerator::addConstants()`
     *
     * @param array $spec
     * @param string $type
     * @return ClassGenerator
     */
    protected function createClassGenerator(array $spec = [], string $type = ClassGenerator::class): ClassGenerator
    {
        if ($methods = $spec['methods'] ?? []) {
            foreach ($methods as $i => $method) {
                if (is_array($method)) {
                    $spec['methods'][$i] = $this->createMethodGenerator($method);
                }
            }
        }
        /** @var ClassGenerator $cls */
        $cls = call_user_func([$type, 'fromArray'], $spec);

        if ($interfaces = $spec['interfaces'] ?? []) {
            $this->applyInterfaces($cls, ...$interfaces);
        }

        $cls->addConstants($spec['constants'] ?? []);

        if ($traits = $spec['traits'] ?? []) {
            $this->applyTraits($cls, ...$traits);
        }

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
     * Adds traits to a `ClassGenerator`
     * @param ClassGenerator $cls
     * @param string ...$traits
     */
    protected function applyTraits(ClassGenerator $cls, string ...$traits)
    {
        foreach ($traits as $trait) {
            $cls->addTrait($trait);
        }
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
