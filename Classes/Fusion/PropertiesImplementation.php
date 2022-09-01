<?php

namespace Networkteam\Neos\ContentApi\Fusion;

use Neos\Flow\Annotations as Flow;
use Neos\Fusion\Exception as FusionException;
use Neos\Fusion\FusionObjects\AbstractFusionObject;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Media\Domain\Model\ThumbnailConfiguration;
use Neos\Media\Domain\Service\AssetService;

class PropertiesImplementation extends AbstractFusionObject
{
    /**
     * Resource publisher
     *
     * @Flow\Inject
     * @var AssetService
     */
    protected $assetService;

    /**
     * Evaluate the node properties
     *
     * @return array
     * @throws FusionException
     */
    public function evaluate()
    {
        $context = $this->getRuntime()->getCurrentContext();
        /** @var \Neos\ContentRepository\Domain\Model\NodeInterface $node */
        $node = $context['node'];

        $result = [];
        foreach ($node->getProperties() as $propertyName => $propertyValue) {
            $result[$propertyName] = $this->convertPropertyValue($propertyValue);
        }

        return $result;
    }

    protected function convertPropertyValue(mixed $propertyValue): mixed
    {
        // TODO We might want to expose more metadata for assets
        if ($propertyValue instanceof AssetInterface && $propertyValue instanceof ImageInterface) {
            $thumbnailConfiguration = new ThumbnailConfiguration(
                null,
                $this->fusionValue('imageMaximumWidth'),
                null,
                $this->fusionValue('imageMaximumHeight'),
                false,
                false,
            );

            $request = $this->getRuntime()->getControllerContext()->getRequest();
            $thumbnailData = $this->assetService->getThumbnailUriAndSizeForAsset($propertyValue, $thumbnailConfiguration, $request);
            return $thumbnailData;
        }
        return $propertyValue;
    }

}
