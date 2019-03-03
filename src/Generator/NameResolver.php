<?php


namespace Rtek\AwsGen\Generator;

use Aws\Api\AbstractModel;
use Aws\Api\Service;
use Aws\Api\Shape;
use Aws\Api\StructureShape;

class NameResolver
{
    const EMPTY_STRUCTURE_SHAPE = 'EmptyStructureShape';

    /** @var Context */
    protected $context;

    /** @var string[] */
    protected $names;


    public function setContext(Context $context): void
    {
        $this->context = $context;
        $this->names = [];
    }

    public function resolve(AbstractModel $model): string
    {
        $raw = $this->raw($model);

        if (!isset($this->names[$raw])) {
            //some apis have case sensitive shapes and php class names are case insensitive
            if (in_array($name = $raw, $this->names)) {
                $name .= '_';
            }

            //php keywords here
            switch (strtolower($name)) {
                case 'function':
                case 'namespace':
                case 'parent':
                case 'trait':
                    $name .= '_';
            }

            $this->names[$raw] = $name;
        }

        return $this->names[$raw];
    }

    protected function raw(AbstractModel $model): string
    {
        if ($model instanceof Service) {
            return $this->service($model);
        } elseif ($model instanceof Shape) {
            return $this->shape($model);
        } elseif ($raw = $model['name']) {
            return $raw;
        }

        throw new \LogicException("Could not get raw name");
    }

    protected function shape(Shape $shape): ?string
    {
        if ($shape instanceof StructureShape && count($shape->getMembers()) === 0) {
            return self::EMPTY_STRUCTURE_SHAPE;
        }

        return ucfirst($shape['name']);
    }

    protected function service(Service $service): string
    {
        if (!$name = $service->getMetadata('namespace')) {
            $name = $service->getMetadata('targetPrefix');

            if (stripos($name, 'DynamoDBStreams') !== false) {
                $name = 'DynamoDbStreams';
            } elseif (stripos($name, 'DynamoDB') !== false) {
                $name = 'DynamoDb';
            } elseif (stripos($name, 'signer') !== false) {
                $name = 'Singer';
            }
        }
        return $name;
    }
}
