<?php


namespace Rtek\AwsGen\Generator;


use Aws\Api\AbstractModel;
use Aws\Api\Operation;
use Aws\Api\Service;
use Aws\Api\Shape;
use Aws\Api\StructureShape;

class Context
{
    /** @var Service */
    protected $service;

    /** @var Operation */
    protected $operation;

    /** @var StructureShape */
    protected $structureShape;

    /** @var Shape */
    protected $shape;

    /** @var array */
    protected $classes = [];

    /**
     * @return Service
     */
    public function getService(): Service
    {
        return $this->service;
    }

    /**
     * @param Service $service
     */
    public function enterService(Service $service): void
    {
        $this->service = $service;
    }

    public function exitService(): void
    {
        $this->service = null;
    }

    /**
     * @return Operation
     */
    public function getOperation(): Operation
    {
        return $this->operation;
    }

    /**
     * @param Operation $operation
     */
    public function enterOperation(Operation $operation): void
    {
        $this->operation = $operation;
    }

    public function exitOperation(): void
    {
        $this->operation = null;
    }

    /**
     * @return StructureShape
     */
    public function getStructureShape(): StructureShape
    {
        return $this->structureShape;
    }

    /**
     * @param StructureShape $structureShape
     */
    public function enterStructureShape(StructureShape $structureShape): void
    {
        $this->structureShape = $structureShape;
    }

    public function exitStructureShape(): void
    {
        $this->structureShape = null;
    }

    /**
     * @return Shape
     */
    public function getShape(): Shape
    {
        return $this->shape;
    }

    /**
     * @param Shape $shape
     */
    public function enterShape(Shape $shape): void
    {
        $this->shape = $shape;
    }

    public function exitShape(): void
    {
        $this->shape = null;
    }


    public function registerClassForShape(Shape $shape): void
    {
        $hash = $this->hash($shape);

        if($exists = &$this->classes[$hash] ?? null) {
            foreach(['service', 'operation', 'structureShape'] as $key) {
                if($exists[$key] !== $this->{$key}) {
                    $exists[$key] = null;
                }
            }
        } else {
            $this->classes[$hash] = [
                'model' => $shape,
                'service' => $this->service,
                'operation' => $this->operation,
                'structureShape' => $this->structureShape,
            ];
        }

    }


    protected function hash(AbstractModel $model): string
    {
        return md5(print_r($model->toArray(), true));
    }

    public function getClassHashes(): array
    {
        return array_keys($this->classes);
    }

    public function getClassModel(string $hash): AbstractModel
    {
        return $this->classes[$hash]['model'];
    }

    public function getClassService(string $hash): ?Service
    {
        return $this->classes[$hash]['service'] ?? null;
    }


}
