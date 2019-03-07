<?php declare(strict_types=1);

namespace Rtek\AwsGen\Generator;

use Aws\Api\AbstractModel;
use Aws\Api\Service;
use Aws\Api\Shape;
use Aws\Api\StructureShape;

/**
 * Resolves PHP class names from AWS `AbstractModel` names
 */
class NameResolver
{
    const EMPTY_STRUCTURE_SHAPE = 'EmptyStructureShape';

    /** @var string[] */
    protected $names = [];

    /**
     * Returns the PHP class name for an `AbstractModel`
     *
     * Invalid names are appended with an underscore:
     * * Reserved PHP words
     * * Names that already exist due to case sensitivity
     *
     * @param AbstractModel $model
     * @return string
     */
    public function resolve(AbstractModel $model): string
    {
        $unique = $this->unique($model);

        if (!isset($this->names[$unique])) {
            if ($model instanceof Shape) {
                $name = $this->shape($model);
            } else {
                $name = $unique;
            }
            //some apis have case sensitive shapes and php class names are case insensitive
            if (in_array($name, $this->names)) {
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

            $this->names[$unique] = $name;
        }

        return $this->names[$unique];
    }

    /**
     * Returns the unique name of the `AbstractModel`
     * @param AbstractModel $model
     * @return string
     */
    protected function unique(AbstractModel $model): string
    {
        if ($raw = (string)$model['name']) {
            return $raw;
        } elseif ($model instanceof Service) {
            return $this->service($model);
        } elseif ($model instanceof Shape) {
            return $this->shape($model);
        }

        throw new \LogicException("Could not get raw name");
    }

    /**
     * Returns the name of a `\Aws\Api\Shape` or `self::EMPTY_STRUCTURE_SHAPE` if its an empty shape
     * @param Shape $shape
     * @return string
     */
    protected function shape(Shape $shape): string
    {
        if ($shape instanceof StructureShape && count($shape->getMembers()) === 0) {
            return self::EMPTY_STRUCTURE_SHAPE;
        }

        return ucfirst($shape['name']);
    }

    /**
     * Returns a normalized name for oddly named services
     *
     * @todo other oddly named services (?)
     *
     * @param Service $service
     * @return string
     */
    protected function service(Service $service): string
    {
        if (!$name = $service->getMetadata('namespace')) {
            $name = $service->getMetadata('targetPrefix');

            //Not ucfirst in SDK
            if (stripos($name, 'signer') !== false) {
                $name = 'Singer';
            }
        }
        return $name;
    }
}
