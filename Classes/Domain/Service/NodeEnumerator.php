<?php
namespace Networkteam\Neos\ContentApi\Domain\Service;

// TODO 9.0
use Neos\Rector\ContentRepository90\Legacy\LegacyContextStub;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Neos\Domain\Model\Site;

/**
 * @Flow\Scope("singleton")
 */
class NodeEnumerator
{

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $dimensionPresetSource;

    /**
     * @Flow\Inject
     * @var \Neos\Neos\Domain\Repository\SiteRepository
     */
    protected $siteRepository;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * @return Site[]
     */
    public function sites()
    {
        $sites = $this->siteRepository->findAll();

        foreach ($sites as $site) {
            yield $site;
        }
    }

    /**
     * Iterate over the site node in all available presets (if it exists)
     *
     * @param Site $site
     * @param string $workspaceName
	 * @param array $dimensions An explicit set of dimension values to use - no preset iteration will be done if this is specified
     * @return \Generator
     */
    public function siteNodeInContexts(Site $site, string $workspaceName = 'live', array $dimensions = [])
    {
        $presets = $this->dimensionPresetSource->getAllPresets();
        if ($presets === [] || $dimensions !== []) {
            $contentContext = new LegacyContextStub(array(
                    'currentSite' => $site,
                    'workspaceName' => $workspaceName,
                    'dimensions' => $dimensions,
                    'targetDimensions' => []
                ));

            yield $contentContext->getNode('/sites/' . $site->getNodeName());
        } else {
            foreach ($presets as $dimensionIdentifier => $presetsConfiguration) {
                foreach ($presetsConfiguration['presets'] as $presetIdentifier => $presetConfiguration) {
                    $dimensions = [$dimensionIdentifier => $presetConfiguration['values']];

                    $contentContext = new LegacyContextStub(array(
                        'currentSite' => $site,
                        'workspaceName' => $workspaceName,
                        'dimensions' => $dimensions,
                        'targetDimensions' => []
                    ));

                    $siteNode = $contentContext->getNode('/sites/' . $site->getNodeName());

                    if ($siteNode instanceof Node) {
                        yield $siteNode;
                    }
                }
            }
        }
    }

    /**
     * Iterate over the given node and all document child nodes recursively
     *
     * @param Node $node
     * @return Node[]
     */
    public function recurseDocumentChildNodes(Node $node)
    {
        yield $node;
        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);
        // TODO 9.0 migration: Try to remove the iterator_to_array($nodes) call.


        foreach (iterator_to_array($subgraph->findChildNodes($node->aggregateId, FindChildNodesFilter::create(nodeTypeConstraints: 'Neos.Neos:Document'))) as $node) {
            foreach ($this->recurseDocumentChildNodes($node) as $childNode) {
                yield $childNode;
            }
        }
    }
}
