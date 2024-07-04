<?php
namespace Networkteam\Neos\ContentApi\View;

use Neos\Flow\Annotations as Flow;
use GuzzleHttp\Psr7\Message;
use Neos\ContentRepository\Domain\Model\NodeInterface as LegacyNodeInterface;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\Flow\Mvc\View\AbstractView;
use Neos\Flow\Security\Context;
use Neos\Fusion\Core\Runtime;
use Neos\Fusion\Exception\RuntimeException;
use Neos\Neos\Domain\Service\FusionService;
use Neos\Neos\Exception;
use Neos\Neos\View\FusionViewI18nTrait;
use Psr\Http\Message\ResponseInterface;

/**
 * A flexible Fusion view based on Neos FusionView (using the FusionService)
 */
class FusionView extends AbstractView
{
    use FusionViewI18nTrait;

    /**
     * @Flow\Inject
     * @var FusionService
     */
    protected $fusionService;

    /**
     * This contains the supported options, their default values, descriptions and types.
     *
     * @var array
     */
    protected $supportedOptions = [
        'extraContextVariables' => [[], 'Extra context variables to pass to the Fusion runtime', 'array']
    ];

    /**
     * The Fusion path to use for rendering the node given in "value", defaults to "page".
     *
     * @var string
     */
    protected $fusionPath = 'root';

    /**
     * @var Runtime
     */
    protected $fusionRuntime;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected $securityContext;

    /**
     * Renders the view
     *
     * @return string|ResponseInterface The rendered view
     * @throws \Exception if no node is given
     * @throws \Throwable
     * @api
     */
    public function render()
    {
        $currentNode = $this->getCurrentNode();
        $currentSiteNode = $this->getCurrentSiteNode();
        $fusionRuntime = $this->getFusionRuntime($currentSiteNode);

        $this->setFallbackRuleFromDimension($currentNode);

        $extraContextVariables = $this->variables['extraContextVariables'] ?? [];

        $fusionRuntime->pushContextArray(array_merge($extraContextVariables, [
            'node' => $currentNode,
            'documentNode' => $this->getClosestDocumentNode($currentNode) ?: $currentNode,
            'site' => $currentSiteNode,
        ]));
        try {
            $output = $fusionRuntime->render($this->fusionPath);
            $output = $this->parsePotentialRawHttpResponse($output);
        } catch (RuntimeException $exception) {
            throw $exception->getPrevious();
        }
        $fusionRuntime->popContext();

        return $output;
    }

    /**
     * @param string $output
     * @return string|ResponseInterface If output is a string with a HTTP preamble a ResponseInterface otherwise the original output.
     */
    protected function parsePotentialRawHttpResponse($output)
    {
        if ($this->isRawHttpResponse($output)) {
            return Message::parseResponse($output);
        }

        return $output;
    }

    /**
     * Checks if the mixed input looks like a raw HTTTP response.
     *
     * @param mixed $value
     * @return bool
     */
    protected function isRawHttpResponse($value): bool
    {
        if (is_string($value) && strpos($value, 'HTTP/') === 0) {
            return true;
        }

        return false;
    }

    /**
     * Set the Fusion path to use for rendering the output
     *
     * @param string $fusionPath
     * @return void
     */
    public function setFusionPath($fusionPath)
    {
        $this->fusionPath = $fusionPath;
    }

    /**
     * @return string
     */
    public function getFusionPath()
    {
        return $this->fusionPath;
    }

    /**
     * @param TraversableNodeInterface $node
     * @return TraversableNodeInterface
     */
    protected function getClosestDocumentNode(TraversableNodeInterface $node)
    {
        while ($node !== null && !$node->getNodeType()->isOfType('Neos.Neos:Document')) {
            $node = $node->findParentNode();
        }
        return $node;
    }

    /**
     * @return TraversableNodeInterface
     * @throws Exception
     */
    protected function getCurrentSiteNode(): TraversableNodeInterface
    {
        $currentNode = isset($this->variables['site']) ? $this->variables['site'] : null;
        if ($currentNode === null && $this->getCurrentNode() instanceof LegacyNodeInterface) {
            // fallback to Legacy node API
            /* @var $node LegacyNodeInterface */
            $node = $this->getCurrentNode();
            return $node->getContext()->getCurrentSiteNode();
        }
        if (!$currentNode instanceof TraversableNodeInterface) {
            throw new Exception('FusionView needs a variable \'site\' set with a Node object.', 1715164625);
        }
        return $currentNode;
    }

    /**
     * @return TraversableNodeInterface
     * @throws Exception
     */
    protected function getCurrentNode(): TraversableNodeInterface
    {
        $currentNode = isset($this->variables['node']) ? $this->variables['node'] : null;
        if (!$currentNode instanceof TraversableNodeInterface) {
            throw new Exception('FusionView needs a variable \'node\' set with a Node object.', 1715164626);
        }
        return $currentNode;
    }


    /**
     * @param TraversableNodeInterface $currentSiteNode
     * @return \Neos\Fusion\Core\Runtime
     */
    protected function getFusionRuntime(TraversableNodeInterface $currentSiteNode)
    {
        if ($this->fusionRuntime === null) {
            $this->fusionRuntime = $this->fusionService->createRuntime($currentSiteNode, $this->controllerContext);
        }
        return $this->fusionRuntime;
    }

    /**
     * Clear the cached runtime instance on assignment of variables
     *
     * @param string $key
     * @param mixed $value
     * @return \Neos\Neos\View\FusionView
     */
    public function assign($key, $value)
    {
        $this->fusionRuntime = null;
        return parent::assign($key, $value);
    }
}
