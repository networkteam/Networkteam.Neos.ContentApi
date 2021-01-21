<?php
namespace Networkteam\Neos\ContentApi\Domain\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;

/**
 * @Flow\Scope("singleton")
 */
class NodeEnumerator
{

    /**
     * @Flow\Inject
     * @var \Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface
     */
    protected $dimensionPresetSource;

    /**
     * @Flow\Inject
     * @var \Neos\Neos\Domain\Repository\SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Domain\Service\ContextFactoryInterface
     */
    protected $contextFactory;

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
     * @return NodeInterface[]
     */
    public function siteNodeInContexts(Site $site)
    {
        $presets = $this->dimensionPresetSource->getAllPresets();
        if ($presets === []) {
            $contentContext = $this->contextFactory->create(array(
                    'currentSite' => $site,
                    'workspaceName' => 'live',
                    'dimensions' => [],
                    'targetDimensions' => []
                ));

            $siteNode = $contentContext->getNode('/sites/' . $site->getNodeName());

            yield $siteNode;
        } else {
            foreach ($presets as $dimensionIdentifier => $presetsConfiguration) {
                foreach ($presetsConfiguration['presets'] as $presetIdentifier => $presetConfiguration) {
                    $dimensions = [$dimensionIdentifier => $presetConfiguration['values']];

                    $contentContext = $this->contextFactory->create(array(
                        'currentSite' => $site,
                        'workspaceName' => 'live',
                        'dimensions' => $dimensions,
                        'targetDimensions' => []
                    ));

                    $siteNode = $contentContext->getNode('/sites/' . $site->getNodeName());

                    if ($siteNode instanceof NodeInterface) {
                        yield $siteNode;
                    }
                }
            }
        }
    }

    /**
     * Iterate over the given node and all document child nodes recursively
     *
     * @param NodeInterface $node
     * @return NodeInterface[]
     */
    public function recurseDocumentChildNodes(NodeInterface $node)
    {
        yield $node;

        foreach ($node->getChildNodes('Neos.Neos:Document') as $node) {
            foreach ($this->recurseDocumentChildNodes($node) as $childNode) {
                yield $childNode;
            }
        }
    }
}
