<?php

namespace Networkteam\Neos\ContentApi\Fusion;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Exception\NoMatchingRouteException;
use Neos\Fusion\Exception as FusionException;
use Neos\Fusion\FusionObjects\AbstractFusionObject;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Neos\Exception as NeosException;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Neos\FrontendRouting\NodeUriBuilderFactory;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Model\ImageVariant;
use Neos\Media\Domain\Model\ThumbnailConfiguration;
use Neos\Media\Domain\Service\AssetService;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Neos\FrontendRouting\Options;
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
     * @var NodeUriBuilderFactory
     */
    protected $nodeUriBuilderFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array
     */
    protected $settings = [];
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

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
        /** @var Node $node */
        $node = $context['node'];

        return $this->mapProperties($node);
    }

    protected function mapProperties(Node $node, Int $depth = 0): array
    {
        $result = [];
        foreach ($node->properties as $propertyName => $propertyValue) {
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
        if ($propertyValue instanceof Node) {
            $recursiveReferencePropertyDepth = $this->settings['recursiveReferencePropertyDepth'];
            $referencedNode = $propertyValue;

            if (is_int($recursiveReferencePropertyDepth) && $depth < $recursiveReferencePropertyDepth) {
                $mappedProperties = $this->mapProperties($referencedNode, $depth + 1);
                $contentRepository = $this->contentRepositoryRegistry->get($referencedNode->contentRepositoryId);

                if ($contentRepository->getNodeTypeManager()->getNodeType($referencedNode->nodeTypeName)->isOfType('Neos.Neos:Document')) {
                    // use Implementation from NodeUriImplementation

                    $possibleRequest = $this->runtime->fusionGlobals->get('request');
                    // Since the properties are only called in an Request we can be sure an Action Request exists.
                    $nodeUriBuilder = $this->nodeUriBuilderFactory->forActionRequest($possibleRequest);

                    $nodeAddress = NodeAddress::fromNode($referencedNode);
                    $options = Options::createEmpty();

                    try {
                        $mappedProperties['_nodeUri'] = $nodeUriBuilder->uriFor($nodeAddress, $options);
                    } catch (NoMatchingRouteException $exception) {
                        $this->logger->error(
                            printf('Link to referenced node could not be created: Node ContextPath: %s, Exception: %s', NodeAddress::fromNode($referencedNode)->toJson(), $exception)
                        );
                        return '';
                    }
                }

                return $mappedProperties;
            }

            return null;
        }

        // TODO 9.0: Remove LinkingService - use nodeUriBuilder?
        // Convert node references set by LinkEditor to URIs
        if (is_string($propertyValue) && preg_match('/^node:\/\/[a-z0-9-]+$/', $propertyValue)) {
            $linkingService = $this->linkingService;
            $controllerContext = $this->runtime->getControllerContext();
            $node = $this->runtime->getCurrentContext()['node'];
            $resolvedUri = $linkingService->resolveNodeUri($propertyValue, $node, $controllerContext, false);
            return $resolvedUri;
        }

        // TODO 9.0: Remove LinkingService - use nodeUriBuilder?
        // Convert asset references set by LinkEditor to URIs
        if (is_string($propertyValue) && preg_match('/^asset:\/\/[a-z0-9-]+$/', $propertyValue)) {
            $linkingService = $this->linkingService;
            $resolvedUri = $linkingService->resolveAssetUri($propertyValue);
            return $resolvedUri;
        }

        // TODO 9.0: Remove LinkingService - use nodeUriBuilder?
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
