<?php


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

class Context implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var Service */
    protected $service;

    /** @var Operation */
    protected $operation;

    /** @var array */
    protected $classes = [];

    public function __construct(Service $service)
    {
        $this->service = $service;
        $this->logger = new NullLogger();
    }

    /**
     * @return Service|null
     */
    public function getService(): Service
    {
        return $this->service;
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

    public function registerClass(AbstractModel $model): bool
    {
        if($model instanceof Shape && !$this->operation) {
            throw new \LogicException('Cannot register shape from outside operation context');
        }

        $hash = $this->hash($model);

        if(!$exists = isset($this->classes[$hash])) {
            $this->classes[$hash] = [
                'model' => $model,
                'contexts' => [],
            ];
        }

        $this->classes[$hash]['contexts'][] = [
            'service' => $this->service,
            'operation' => $this->operation,
        ];

        return $exists;
    }

    public function hash(AbstractModel $model): string
    {
         if($model instanceof StructureShape) {
            $toHash = [$model['name']];
            foreach($model->getMembers() as $member) {
                $toHash[] = $member->toArray();
            }
        } else if($model instanceof ListShape) {
            $toHash = [
                $model->getName(),
                $model->getMember()->toArray(),
            ];
        } else if($model instanceof MapShape) {
             $toHash = [
                 $model->getName(),
                 $model->getValue()->toArray(),
             ];
        } else {
            $toHash = $model->toArray();
        }

        return md5(print_r($toHash, true));
    }

    public function getClassHashes(): array
    {
        return array_keys($this->classes);
    }

    public function getClassModel(string $hash): AbstractModel
    {
        return $this->classes[$hash]['model'];
    }

    public function getClassOperation(string $hash): ?Operation
    {
        return $this->classes[$hash]['contexts'][0]['operation'] ?? null;
    }


}
