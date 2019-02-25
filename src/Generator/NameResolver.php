<?php


namespace Rtek\AwsGen\Generator;

use Aws\Api\AbstractModel;
use Aws\Api\Service;
use Aws\Api\Shape;
use Aws\Api\StructureShape;

class NameResolver
{
    /** @var Context */
    protected $context;

    protected $names;


    public function setContext(Context $context): void
    {
        $this->context = $context;
        $this->names = [];
    }

    public function resolve(AbstractModel $model): string
    {
        $raw = $this->raw($model);

        if(!isset($this->names[$raw])) {
            if($model instanceof Service) {
                $name = $this->service($model);
            } else if($model instanceof Shape) {
                $name = $this->shape($model);
            } else  {
                $name = $raw;
            }

            if (in_array($name, $this->names)) {
                $name .= '_';
            }

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

    protected function raw(AbstractModel $model)
    {
        return $model['name'];
    }

    protected function shape(Shape $shape): string
    {
        if($shape instanceof StructureShape && count($shape->getMembers()) === 0) {
            $name = 'EmptyStructureShape';
        } else {
            $name = ucfirst($shape['name']);
        }

        return $name;
    }

    protected function service(Service $service): string
    {
        if(!$name = $service->getMetadata('namespace')) {
            $name = $service->getMetadata('targetPrefix');

            if(stripos($name, 'DynamoDBStreams') !== false) {
                $name = 'DynamoDbStreams';
            } else if(stripos($name, 'DynamoDB') !== false) {
                $name = 'DynamoDb';
            } else if(stripos($name, 'signer') !== false) {
                $name = 'Singer';
            }
        }
        return $name;
    }
}
