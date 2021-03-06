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

/**
 * Maintains the set of `AbstractModel` that need to be generated as service classes
 */
class Context implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var Service */
    protected $service;

    /** @var Operation */
    protected $operation;

    /** @var array */
    protected $classes = [];

    /**
     * @param Service $service
     */
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
     * Sets the visiting operation
     * @param Operation $operation
     */
    public function enterOperation(Operation $operation): void
    {
        $this->operation = $operation;
    }

    /**
     * Clears the visiting operation
     */
    public function exitOperation(): void
    {
        $this->operation = null;
    }

    /**
     * Registers an `AbstractModel` for generation
     *
     * A list of the service/operation contexts are kept but are not used yet
     *
     * @todo remove: this is probably useless
     *
     * @param AbstractModel $model
     * @return bool true if the $model is already registered
     */
    public function registerClass(AbstractModel $model): bool
    {
        if ($model instanceof Shape && !$this->operation) {
            throw new \LogicException('Cannot register shape from outside operation context');
        }

        $hash = $this->hash($model);

        if (!$exists = isset($this->classes[$hash])) {
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

    /**
     * Returns a unique hash for an `AbstractModel` to avoid duplicate class generation
     * @param AbstractModel $model
     * @return string
     */
    public function hash(AbstractModel $model): string
    {
        if ($model instanceof StructureShape) {
            $toHash = [$model['name']];
            foreach ($model->getMembers() as $member) {
                $toHash[] = $member->toArray();
            }
        } elseif ($model instanceof ListShape) {
            $toHash = [
               $model->getName(),
               $model->getMember()->toArray(),
            ];
        } elseif ($model instanceof MapShape) {
            $toHash = [
                $model->getName(),
                $model->getValue()->toArray(),
            ];
        } else {
            $toHash = $model->toArray();
        }

        return md5(print_r($toHash, true));
    }

    /**
     * Returns the class hashes to generate
     * @return array
     */
    public function getClassHashes(): array
    {
        return array_keys($this->classes);
    }

    /**
     * Returns the `AbstractModel` for a given `$hash`
     * @param string $hash
     * @return AbstractModel
     */
    public function getClassModel(string $hash): AbstractModel
    {
        return $this->classes[$hash]['model'];
    }

    /**
     * Returns the context `Operation` for a given `$hash`
     * @param string $hash
     * @return Operation|null
     */
    public function getClassOperation(string $hash): ?Operation
    {
        return $this->classes[$hash]['contexts'][0]['operation'] ?? null;
    }
}
