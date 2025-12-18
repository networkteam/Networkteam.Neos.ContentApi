<?php

namespace Networkteam\Neos\ContentApi\Fusion;

use Neos\Flow\Annotations as Flow;
use Neos\Fusion\Exception as FusionException;
use Neos\Fusion\FusionObjects\AbstractFusionObject;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Neos\Exception as NeosException;
use Neos\Neos\Service\LinkingService;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Model\ImageVariant;
use Neos\Media\Domain\Model\ThumbnailConfiguration;
use Neos\Media\Domain\Service\AssetService;
use Neos\Flow\ResourceManagement\ResourceManager;
use Psr\Log\LoggerInterface;

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
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var LinkingService
     */
    protected $linkingService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array
     */
    protected $settings = [];

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
        // Return asset metadata combined with additional data from imageRenderer/assetRenderer
        if ($propertyValue instanceof Asset) {
            $assetData = [];

            $assetData['title'] = $propertyValue->getTitle();
            $assetData['caption'] = $propertyValue->getCaption();
            $assetData['copyrightNotice'] = $propertyValue->getCopyrightNotice();
            $assetData['byteSize'] = $propertyValue->getResource()->getFileSize(); // bytes
            $assetData['fileName'] = $propertyValue->getResource()->getFilename();
            $assetData['fileExtension'] = $propertyValue->getFileExtension();
            $assetData['lastModified'] = $propertyValue->getLastModified()->format('c');

            $fusionPath = $propertyValue instanceof Image || $propertyValue instanceof ImageVariant ? 'imageRenderer' : 'assetRenderer';

            $this->runtime->pushContext('asset', $propertyValue);
            $assetData = array_merge($assetData, $this->runtime->evaluate($this->path . '/' . $fusionPath, $this));
            $this->runtime->popContext();

            return $assetData;
        }

        // Get properties of referenced nodes
        if ($propertyValue instanceof NodeInterface) {
            $recursiveReferencePropertyDepth = $this->settings['recursiveReferencePropertyDepth'];
            $referencedNode = $propertyValue;

            if (is_int($recursiveReferencePropertyDepth) && $depth < $recursiveReferencePropertyDepth) {
                $mappedProperties = $this->mapProperties($referencedNode, $depth + 1);

                if ($referencedNode->getNodeType()->isOfType('Neos.Neos:Document')) {
                    // use Implementation from Neos.Neos:NodeUri
                    $controllerContext = $this->runtime->getControllerContext();

                    try {
                        $mappedProperties['_linkToReference'] = $this->linkingService->createNodeUri(
                            $controllerContext,
                            $referencedNode,
                            null,
                            'html'
                        );
                    } catch (NeosException $exception) {
                        $this->logger->error(
                            printf('Link to referenced node could not be created: Nodeidentifier: %s, Exception: %s', $referencedNode->getContextPath(), $exception)
                        );
                        return '';
                    }
                }

                return $mappedProperties;
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

        // Recursively map properties inside of arrays
        if (is_array($propertyValue) && count($propertyValue) > 0) {
            return array_map(function ($value) use ($depth) {
                return $this->convertPropertyValue($value, $depth);
            }, $propertyValue);
        }

        // Recursively map properties of other iterable objects
        if (is_iterable($propertyValue)) {
            $result = [];
            foreach ($propertyValue as $key => $value) {
                $result[$key] = $this->convertPropertyValue($value, $depth);
            }
            return $result;
        }

        return $propertyValue;
    }
}
