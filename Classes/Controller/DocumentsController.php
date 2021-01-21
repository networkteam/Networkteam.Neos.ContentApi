<?php
namespace Networkteam\Neos\ContentApi\Controller;

use Neos\ContentRepository\Domain\Model\NodeInterface;
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
                    $documents[] = [
                        'identifier' => (string)$nodeAggregateIdentifier,
                        'contextPath' => $documentNode->getContextPath(),
                        'dimensions' => $dimensions,
                        'site' => $siteNodeName,
                        'routePath' => $this->uriBuilder->uriFor(
                            'show',
                            [
                                'node' => $documentNode,
                            ],
                            'Frontend\Node',
                            'Neos.Neos',
                            ),
                        'renderUrl' => $this->uriBuilder->uriFor(
                            'show',
                            [
                                'identifier' => (string)$nodeAggregateIdentifier,
                                'site' => $siteNodeName,
                                'dimensions' => $dimensions,
                                'workspaceName' => $workspaceName,
                            ],
                            'Documents',
                            )
                    ];
                }
            }

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
     * @param string $identifier Node identifier
     * @param string $site Site node name
     * @param array $dimensions Dimensions for node context
     * @param string $workspaceName Workspace name for node context
     *
     * @throws Exception\NodeNotFoundException
     * @throws Exception\SiteNotFoundException
     * @throws \Neos\Flow\Mvc\Exception
     * @throws \Exception
     */
    public function showAction(string $identifier, string $site, array $dimensions, string $workspaceName = 'live')
    {
        $siteEntity = $this->siteRepository->findOneByNodeName($site);
        if (!$siteEntity instanceof Site) {
            throw new Exception\SiteNotFoundException(sprintf('Site with node name "%s" not found', $site), 1611245098);
        }

        $contentContext = $this->contentContextFactory->create([
            'workspaceName' => $workspaceName,
            'currentSite' => $siteEntity
        ]);
        $documentNode = $contentContext->getNodeByIdentifier($identifier);
        if (!$documentNode instanceof NodeInterface) {
            throw new Exception\NodeNotFoundException(sprintf('Node with identifier "%s" not found', $identifier), 1611245114);
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

}
