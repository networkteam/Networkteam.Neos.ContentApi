<?php

namespace Networkteam\Neos\ContentApi\Converter;

use Neos\Flow\Property\PropertyMappingConfigurationInterface;
use Neos\Flow\Property\TypeConverter\AbstractTypeConverter;
use Neos\Utility\TypeHandling;

class SimpleArrayConverter extends AbstractTypeConverter
{
    protected $priority = -1;

    public function convertFrom($source, $targetType, array $convertedChildProperties = [], PropertyMappingConfigurationInterface $configuration = null)
    {
        if (!is_array($source)) {
            return [];
        }

        return $source;
    }
}
