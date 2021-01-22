<?php
namespace Networkteam\Neos\ContentApi\Controller;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\Exception\RequiredArgumentMissingException;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\View\FusionView;
use Networkteam\Neos\ContentApi\Domain\Service\SitePropertiesRenderer;
use Networkteam\Neos\ContentApi\Domain\Service\NodeEnumerator;
use Networkteam\Neos\ContentApi\Exception;

class DocumentsController extends ActionController
{

    use ErrorHandlingTrait;

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
     * @var SitePropertiesRenderer
     */
    protected $sitePropertiesRenderer;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * @param string $workspaceName Workspace name for node context
     *
     * @throws \Neos\Flow\Http\Exception
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     * @throws \Neos\Flow\Security\Exception
     * @throws \Neos\Fusion\Exception
     * @throws \Neos\Neos\Domain\Exception
     */
    public function indexAction(string $workspaceName = 'live')
    {
        // TODO Add site parameter for scoping requests per site

        $documents = [];
        $contentProperties = [
            'siteProperties' => []
        ];

        // TODO Check that public access is only granted to live workspace (or content api is completely restricted by API key)

        foreach ($this->nodeEnumerator->sites() as $site) {
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
                                // 'identifier' => (string)$nodeAggregateIdentifier,
                                // 'site' => $siteNodeName,
                                // 'dimensions' => $dimensions,
                                // 'workspaceName' => $workspaceName,
                            ],
                            'Documents',
                            )
                    ];
                }
            }

            // Add extra endpoint for site properties
            $contentProperties['siteProperties'][$siteNodeName] = $this->sitePropertiesRenderer->renderSiteProperties(
                $site,
                $this->controllerContext,
                $workspaceName
            );
        }

        $this->view->assign('value', [
            'documents' => $documents,
            'contentProperties' => $contentProperties
        ]);
    }

    /**
     * @param string $path Node route path
     * @param string $site Site node name (defaults to first site)
     * @param string $workspaceName
     *
     * @throws Exception\NodeNotFoundException
     * @throws Exception\SiteNotFoundException
     * @throws \Neos\Flow\Mvc\Exception
     */
    public function showAction(string $path, string $site = null, string $workspaceName = 'live')
    {
        if ($site !== null) {
            $siteEntity = $this->siteRepository->findOneByNodeName($site);
            if (!$siteEntity instanceof Site) {
                throw new Exception\SiteNotFoundException(sprintf('Site with node name "%s" not found', $site),
                    1611245098);
            }
        } else {
            $siteEntity = $this->siteRepository->findFirstOnline();
        }

        $path = ltrim($path, '/');

        $routePart = new \Neos\Neos\Routing\FrontendNodeRoutePartHandler();
        $routePart->setName('node');

        $matchResult = $routePart->match($path);
        if ($matchResult === false) {
            throw new Exception\NodeNotFoundException('Node with path %s not found', 1611250322);
        }

        $nodeContextPath = $routePart->getValue();

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
        $fusionView->assign('value', $documentNode);

        $fusionView->setFusionPath('contentApi/document');
        $result = $fusionView->render();

        $this->view->assign('value', $result);
    }

    public function processRequest(ActionRequest $request, ActionResponse $response)
    {
        try {
            parent::processRequest($request, $response);
        } catch (RequiredArgumentMissingException $e) {
            $this->respondWithErrors($e);
        }
    }

    /**
     * Prepares the context properties for the nodes based on the given workspace and dimensions
     *
     * @param string $workspaceName
     * @param array $dimensions
     * @return array
     */
    protected function prepareContextProperties($workspaceName, array $dimensions = null)
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
