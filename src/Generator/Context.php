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

    /** @var array */
    protected $classes = [];

    /**
     * @return Service|null
     */
    public function getService(): ?Service
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
     * @return Operation|null
     */
    public function getOperation(): ?Operation
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

    public function registerClassForModel(AbstractModel $model): bool
    {
        $hash = $this->hash($model);

        if($exists = &$this->classes[$hash] ?? null) {
            foreach(['service', 'operation'] as $key) {
                if($exists[$key] !== $this->{$key}) {
                    $exists[$key] = null;
                }
            }
            return true;
        }

        $this->classes[$hash] = [
            'model' => $model,
            'service' => $this->service,
            'operation' => $this->operation,
        ];

        return false;
    }



    public function hash(AbstractModel $model): string
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

    public function getClassOperation(string $hash): ?Operation
    {
        return $this->classes[$hash]['operation'] ?? null;
    }


}
