<?php

namespace Networkteam\Neos\ContentApi\Controller;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\ContentRepository\Exception\NodeException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Routing\FrontendNodeRoutePartHandler;
use Neos\Neos\View\FusionView;
use Networkteam\Neos\ContentApi\Domain\Service\NodeEnumerator;
use Networkteam\Neos\ContentApi\Exception;

class DocumentsController extends ActionController
{

    use ErrorHandlingTrait;
    use SiteHandlingTrait;

    /**
     * @var string
     */
    protected $defaultViewObjectName = JsonView::class;

    /**
     * @Flow\Inject
     * @var NodeEnumerator
     */
    protected $nodeEnumerator;

    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * @Flow\Inject
     * @var DomainRepository
     */
    protected $domainRepository;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * List all document nodes
     *
     * @param string $workspaceName Workspace name for node context (defaults to live)
     *
     * @throws NodeException
     * @throws MissingActionNameException
     * @throws \Neos\Flow\Http\Exception
     * @throws \Neos\Neos\Domain\Exception
     */
    public function indexAction(string $workspaceName = 'live'): void
    {
        // TODO Check that public access is only granted to live workspace (or content api is completely restricted by API key)

        $documents = [];

        $site = $this->getActiveSite();
        $siteNodeName = $site->getNodeName();
        foreach ($this->nodeEnumerator->siteNodeInContexts($site, $workspaceName) as $siteNode) {
            foreach ($this->nodeEnumerator->recurseDocumentChildNodes($siteNode) as $documentNode) {
                if ($documentNode->getNodeType()->isOfType('Neos.Neos:Shortcut')) {
                    continue;
                }
                $nodeAggregateIdentifier = $documentNode->getNodeAggregateIdentifier();
                $dimensions = $documentNode->getContext()->getDimensions();
                $routePath = $this->uriBuilder->uriFor(
                    'show',
                    [
                        'node' => $documentNode,
                    ],
                    'Frontend\Node',
                    'Neos.Neos',
                );
                $documents[] = [
                    'identifier' => (string)$nodeAggregateIdentifier,
                    'contextPath' => $documentNode->getContextPath(),
                    'dimensions' => $dimensions,
                    'site' => $siteNodeName,
                    // TODO How to make this extensible? In Fusion?
                    'meta' => [
                        'title' => $documentNode->getProperty('title')
                    ],
                    'routePath' => $routePath,
                    'renderUrl' => $this->uriBuilder->uriFor(
                        'show',
                        [
                            'path' => $routePath,
                        ],
                        'Documents',
                    )
                ];
            }
        }

        $this->view->assign('value', [
            'documents' => $documents,
        ]);
    }

    /**
     * Render a document node for the content API
     *
     * @param string $path Node route path (e.g. "/en/features")
     *
     * @throws Exception\NodeNotFoundException
     * @throws \Neos\Flow\Mvc\Exception
     */
    public function showAction(string $path): void
    {
        $path = ltrim($path, '/');

        $routePart = new FrontendNodeRoutePartHandler();
        $routePart->setName('node');

        $parameters = $this->request->getHttpRequest()->getAttribute(ServerRequestAttributes::ROUTING_PARAMETERS) ?? RouteParameters::createEmpty();
        $matchResult = $routePart->matchWithParameters($path, $parameters);
        if ($matchResult === false) {
            throw new Exception\NodeNotFoundException(sprintf('Node with path %s not found', $path), 1611250322);
        }

        $nodeContextPath = $matchResult === true ? $routePart->getValue() : $matchResult->getMatchedValue();

        $nodePathAndContext = NodePaths::explodeContextPath($nodeContextPath);
        $nodePath = $nodePathAndContext['nodePath'];
        $workspaceName = $nodePathAndContext['workspaceName'];
        $dimensions = $nodePathAndContext['dimensions'];

        $contentContext = $this->contentContextFactory->create($this->prepareContextProperties($workspaceName,
            $dimensions));
        $documentNode = $contentContext->getNode($nodePath);
        if (!$documentNode instanceof NodeInterface) {
            throw new Exception\NodeNotFoundException(sprintf('Node with path "%s" not found', $nodePath),
                1611245114);
        }

        $viewOptions = [];
        $fusionView = new FusionView($viewOptions);
        // TODO Add custom Response and intercept headers from result
        $fusionView->setControllerContext($this->controllerContext);
        $fusionView->assign('site', $contentContext->getCurrentSiteNode());
        $fusionView->assign('value', $documentNode);

        $fusionView->setFusionPath('contentApi/document');
        $result = $fusionView->render();

        $this->view->assign('value', $result);
    }

    /**
     * Prepares the context properties for the nodes based on the given workspace and dimensions
     *
     * @param string $workspaceName
     * @param array $dimensions
     * @return array
     */
    protected function prepareContextProperties($workspaceName, array $dimensions = null): array
    {
        $contextProperties = [
            'workspaceName' => $workspaceName,
            'invisibleContentShown' => false,
            'removedContentShown' => false
        ];

        if ($dimensions !== null) {
            $contextProperties['dimensions'] = $dimensions;
        }

        return $contextProperties;
    }

}
