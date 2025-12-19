<?php

namespace Networkteam\Neos\ContentApi\Controller;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\ContentRepository\Exception\NodeException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\Exception\NoSuchArgumentException;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Utility\Now;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\Neos\Routing\FrontendNodeRoutePartHandlerInterface;
use Networkteam\Neos\ContentApi\Converter\SimpleArrayConverter;
use Networkteam\Neos\ContentApi\Domain\Service\NodeEnumerator;
use Networkteam\Neos\ContentApi\Exception;
use Networkteam\Neos\ContentApi\Http\DimensionsHelper;
use Networkteam\Neos\ContentApi\View\FusionView;

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
     * @var ContentDimensionPresetSourceInterface
     * @Flow\Inject
     */
    protected $contentDimensionPresetSource;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var Now
     */
    protected $now;

    /**
     * @Flow\InjectConfiguration(path="documentList.ignoredNodeTypes")
     * @var array
     */
    protected $ignoredNodeTypes = [];

    /**
     * @Flow\InjectConfiguration(path="checkRedirects")
     * @var boolean
     */
    protected $checkRedirects = false;

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
        $availableDimensions = [];

        // Make sure to check for dimension values from routing parameters to support Flowpack.Neos.DimensionResolver
        $dimensionValues = DimensionsHelper::getDimensionValuesFromRequest($this->request->getHttpRequest());

        $site = $this->getActiveSite();
        $siteNodeName = $site->getNodeName();
        foreach ($this->nodeEnumerator->siteNodeInContexts($site, $workspaceName, $dimensionValues) as $siteNode) {
            foreach ($this->nodeEnumerator->recurseDocumentChildNodes($siteNode) as $documentNode) {
                $nodeType = $documentNode->getNodeType();
                if ($this->isIgnoredNodeType($nodeType)) {
                    continue;
                }

                $nodeAggregateIdentifier = $documentNode->getNodeAggregateIdentifier();
                $creationDateTime = $documentNode->getCreationDateTime();
                $lastPublicationDateTime = $documentNode->getLastPublicationDateTime();
                $availableDimensions = $this->contentDimensionPresetSource->getAllPresets();
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
                    'routePath' => $routePath,
                    'creationDateTime' => $creationDateTime,
                    'lastPublicationDateTime' => $lastPublicationDateTime
                ];
            }
        }

        $this->view->assign('value', [
            'documents' => $documents,
            'dimensions' => $availableDimensions,
        ]);
    }

    /**
     * Render properties of a document node for the content API
     *
     * Either provide a path (public frontend) or context path (preview / editing) to fetch the node.
     *
     * @param string|null $path Node route path (e.g. "/en/features")
     * @param string|null $contextPath Node context path (e.g. "/sites/neosdemo@user-admin;language=en_US")
     *
     * @throws Exception\NodeNotFoundException
     * @throws \Neos\Flow\Mvc\Exception
     */
    public function documentAction(?string $path = null, ?string $contextPath = null): void
    {
        if ($path !== null) {
            $path = ltrim($path, '/');

            $routePart = $this->objectManager->get(FrontendNodeRoutePartHandlerInterface::class);
            $routePart->setName('node');

            $parameters = $this->request->getHttpRequest()->getAttribute(ServerRequestAttributes::ROUTING_PARAMETERS) ?? RouteParameters::createEmpty();
            $matchResult = $routePart->matchWithParameters($path, $parameters);
            if ($matchResult === false) {
                $redirect = $this->findPossibleRedirect($path, $this->request->getHttpRequest()->getUri()->getHost());
                if ($redirect !== null) {
                    $this->view->assign('value', [
                        'redirect' => $redirect
                    ]);
                    return;
                }

                throw new Exception\NodeNotFoundException(sprintf('Node with path %s not found', $path), 1611250322);
            }

            $nodeContextPath = $matchResult === true ? $routePart->getValue() : $matchResult->getMatchedValue();
        } elseif ($contextPath !== null) {
            $nodeContextPath = $contextPath;
        } else {
            throw new Exception\NodeNotFoundException('No path or context path provided', 1662111662);
        }

        $nodePathAndContext = NodePaths::explodeContextPath($nodeContextPath);
        $nodePath = $nodePathAndContext['nodePath'];
        $workspaceName = $nodePathAndContext['workspaceName'];
        $dimensions = $nodePathAndContext['dimensions'];

        $contentContext = $this->contentContextFactory->create($this->prepareContextProperties($workspaceName,
            $dimensions));
        $documentNode = $contentContext->getNode($nodePath);
        if (!$documentNode instanceof NodeInterface) {
            if ($path !== null) {
                $redirect = $this->findPossibleRedirect($path, $this->request->getHttpRequest()->getUri()->getHost());
                if ($redirect !== null) {
                    $this->view->assign('value', [
                        'redirect' => $redirect
                    ]);
                    return;
                }
            }

            throw new Exception\NodeNotFoundException(sprintf('Node with node path "%s" not found', $nodePath),
                1611245114);
        }

        $viewOptions = [];
        $fusionView = new FusionView($viewOptions);
        // TODO Add custom Response and intercept headers from result
        $fusionView->setControllerContext($this->controllerContext);
        $fusionView->assign('site', $contentContext->getCurrentSiteNode());
        $fusionView->assign('node', $documentNode);
        $fusionView->setFusionPath('contentApi/document');
        $result = $fusionView->render();
        $this->view->assign('value', $result);
    }

    /**
     * Render properties of a single node for the content API
     *
     * @param string $contextPath Node context path (e.g. "/sites/neosdemo@user-admin;language=en_US")
     *
     * @throws Exception\NodeNotFoundException
     * @throws \Neos\Flow\Mvc\Exception
     */
    public function nodeAction(string $contextPath): void
    {
        $nodePathAndContext = NodePaths::explodeContextPath($contextPath);
        $nodePath = $nodePathAndContext['nodePath'];
        $workspaceName = $nodePathAndContext['workspaceName'];
        $dimensions = $nodePathAndContext['dimensions'];

        $contentContext = $this->contentContextFactory->create($this->prepareContextProperties($workspaceName,
            $dimensions));
        $node = $contentContext->getNode($nodePath);
        if (!$node instanceof NodeInterface) {
            throw new Exception\NodeNotFoundException(sprintf('Node with path "%s" not found', $nodePath),
                1611245114);
        }

        $viewOptions = [];
        $fusionView = new FusionView($viewOptions);
        // TODO Add custom Response and intercept headers from result
        $fusionView->setControllerContext($this->controllerContext);
        $fusionView->assign('site', $contentContext->getCurrentSiteNode());
        $fusionView->assign('node', $node);
        $fusionView->setFusionPath('contentApi/node');
        $result = $fusionView->render();
        $this->view->assign('value', $result);
    }

    const QUERY_NAME_PATTERN = '/^[a-z0-9-]+$/';

    /**
     * @throws NoSuchArgumentException
     */
    public function initializeQueryAction(): void
    {
        $this->arguments->getArgument('params')->getPropertyMappingConfiguration()->setTypeConverter(new SimpleArrayConverter());
    }

    /**
     * Query nodes or other data for the content API
     *
     * @param string $queryName Name of a query that was defined in contentApi.queries via Fusion
     * @param array $params Parameters for the query (filter, sorting, pagination, etc.)
     * @param string|null $workspaceName Workspace name for node context (defaults to live)
     * @param array|null $dimensions Dimensions for node context
     *
     * @throws Exception
     * @throws \Neos\Flow\Mvc\Exception
     * @throws \Throwable
     */
    public function queryAction(string $queryName, array $params = [], ?string $workspaceName = null, ?array $dimensions = null): void
    {
        if (!preg_match(self::QUERY_NAME_PATTERN, $queryName)) {
            throw new Exception('Invalid query name', 1715086845);
        }

        $contentContext = $this->contentContextFactory->create($this->prepareContextProperties($workspaceName ?? 'live', $dimensions));

        $siteNode = $contentContext->getCurrentSiteNode();
        $node = $siteNode;

        $viewOptions = [];
        $fusionView = new FusionView($viewOptions);
        // TODO Add custom Response and intercept headers from result
        $fusionView->setControllerContext($this->controllerContext);
        $fusionView->assign('site', $contentContext->getCurrentSiteNode());
        $fusionView->assign('node', $node);
        $fusionView->assign('extraContextVariables', [
            'params' => $params,
        ]);

        $fusionView->setFusionPath('contentApi/queries/' . $queryName);
        $result = $fusionView->render();

        if (!is_array($result)) {
            throw new Exception('Query result must be an array', 1715173499);
        }
        if (!isset($result['data'])) {
            throw new Exception('Query result must contain a "data" key', 1715173500);
        }

        $this->view->assign('value', $result);
    }

    /**
     * Prepares the context properties for the nodes based on the given workspace and dimensions
     *
     * @param string $workspaceName
     * @param array|null $dimensions
     * @return array
     */
    protected function prepareContextProperties(string $workspaceName, array $dimensions = null): array
    {
        $contextProperties = [
            'workspaceName' => $workspaceName,
            'invisibleContentShown' => $workspaceName !== 'live',
            'inaccessibleContentShown' => $workspaceName !== 'live',
            'removedContentShown' => false
        ];

        if ($dimensions !== null) {
            $contextProperties['dimensions'] = $dimensions;
        }

        return $contextProperties;
    }

    /**
     * @return array
     * @throws \Networkteam\Neos\ContentApi\Exception
     */
    private function getIgnoredNodeTypes(): array
    {
        $ignoredNodeTypes = $this->ignoredNodeTypes ?? [];
        if (!is_array($ignoredNodeTypes)) {
            throw new Exception('The "ignoredNodeTypes" setting must be an array of node type names', 1669719500);
        }
        return $ignoredNodeTypes;
    }

    private function isIgnoredNodeType(NodeType $nodeType): bool
    {
        foreach ($this->getIgnoredNodeTypes() as $ignoredNodeType) {
            if ($nodeType->isOfType($ignoredNodeType)) {
                return true;
            }
        }
        return false;
    }

    private function findPossibleRedirect(string $path, string $host): ?array
    {
        if (!$this->checkRedirects) {
            return null;
        }

        // Check if RedirectHandler is available
        if (!$this->objectManager->has('Neos\RedirectHandler\Storage\RedirectStorageInterface')) {
            return null;
        }

        $redirectStorage = $this->objectManager->get('Neos\RedirectHandler\Storage\RedirectStorageInterface');
        $redirect = $redirectStorage->getOneBySourceUriPathAndHost($path, $host);
        if ($redirect === null) {
            return null;
        }

        // Check if redirect ist still valid
        if ($redirect->getStartDateTime() !== null && $redirect->getStartDateTime()->getTimestamp() > $this->now->getTimestamp()) {
            return null;
        }
        if ($redirect->getEndDateTime() !== null && $redirect->getEndDateTime()->getTimestamp() <= $this->now->getTimestamp()) {
            return null;
        }

        return [
            'targetPath' => '/' . ltrim($redirect->getTargetUriPath(), '/'),
            'statusCode' => $redirect->getStatusCode(),
        ];
    }
}
