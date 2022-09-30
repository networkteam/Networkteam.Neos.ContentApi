<?php

namespace Networkteam\Neos\ContentApi\Fusion;

use Neos\Flow\Annotations as Flow;
use Neos\Fusion\Exception as FusionException;
use Neos\Fusion\FusionObjects\AbstractFusionObject;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Neos\Service\LinkingService;
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
     * @Flow\Inject
     * @var LinkingService
     */
    protected $linkingService;

    /**
     * @param array $settings
     * @return void
     */
    public function injectSettings(array $settings)
    {
        $this->settings = $settings;
    }

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

        return $this->mapProperties($node);
    }

    protected function mapProperties(NodeInterface $node, Int $depth = 0): array
    {
        $result = [];
        foreach ($node->getProperties() as $propertyName => $propertyValue) {
            $result[$propertyName] = $this->convertPropertyValue($propertyValue, $depth);
        }

        return $result;
    }

    protected function convertPropertyValue(mixed $propertyValue, Int $depth): mixed
    {
        // Extract asset URI and metadata
        // TODO We might want to expose more metadata for assets
        if ($propertyValue instanceof AssetInterface) {
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

            return [
                'width' => $thumbnailData['width'],
                'height' => $thumbnailData['height'],
                'src' => $thumbnailData['src'],
                'title' => $propertyValue->getTitle(),
                'caption' => $propertyValue->getCaption(),
                'copyrightNotice' => $propertyValue->getCopyrightNotice(),
            ];
        }

        // Recursively map properties inside of arrays
        if (is_array($propertyValue) && count($propertyValue) > 0) {
            return array_map(function ($value) use ($depth) {
                return $this->convertPropertyValue($value, $depth);
            }, $propertyValue);
        }

        // Get properties of referenced nodes
        if ($propertyValue instanceof NodeInterface) {
            $recursiveReferencePropertyDepth = $this->settings['recursiveReferencePropertyDepth'];

            if (is_int($recursiveReferencePropertyDepth) && $depth < $recursiveReferencePropertyDepth) {
                return $this->mapProperties($propertyValue, $depth + 1);
            }

            return null;
        }

        // Convert node references set by LinkEditor to URIs
        if (is_string($propertyValue) && preg_match('/^node:\/\/[a-z0-9-]+$/', $propertyValue)) {
            $linkingService = $this->linkingService;
            $controllerContext = $this->runtime->getControllerContext();
            $node = $this->runtime->getCurrentContext()['node'];
            $resolvedUri = $linkingService->resolveNodeUri($propertyValue, $node, $controllerContext, false);
            return $resolvedUri;
        }

        // Convert asset references set by LinkEditor to URIs
        if (is_string($propertyValue) && preg_match('/^asset:\/\/[a-z0-9-]+$/', $propertyValue)) {
            $linkingService = $this->linkingService;
            $resolvedUri = $linkingService->resolveAssetUri($propertyValue);
            return $resolvedUri;
        }

        // Convert node and asset references inside other strings to URIs
        if (is_string($propertyValue)) {
            $linkingService = $this->linkingService;
            $controllerContext = $this->runtime->getControllerContext();
            $node = $this->runtime->getCurrentContext()['node'];

            $processedContent = preg_replace_callback(LinkingService::PATTERN_SUPPORTED_URIS, function (array $matches) use ($node, $linkingService, $controllerContext) {
                switch ($matches[1]) {
                    case 'node':
                        $resolvedUri = $linkingService->resolveNodeUri($matches[0], $node, $controllerContext, false);
                        break;
                    case 'asset':
                        $resolvedUri = $linkingService->resolveAssetUri($matches[0]);
                        break;
                    default:
                        $resolvedUri = null;
                }
                return $resolvedUri;
            }, $propertyValue);

            return $processedContent;
        }

        return $propertyValue;
    }
}
