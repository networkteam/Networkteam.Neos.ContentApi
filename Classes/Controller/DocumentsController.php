<?php
namespace Networkteam\Neos\ContentApi\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\Exception\RequiredArgumentMissingException;
use Neos\Flow\Mvc\View\JsonView;
use Networkteam\Neos\ContentApi\Domain\Service\ContentPropertiesRenderer;
use Networkteam\Neos\ContentApi\Domain\Service\NodeEnumerator;

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
     * @var ContentPropertiesRenderer
     */
    protected $contentPropertiesRenderer;

    public function indexAction()
    {
        $documents = [];
        $contentProperties = [
            'siteProperties' => []
        ];

        foreach ($this->nodeEnumerator->sites() as $site) {
            $siteNodeName = $site->getNodeName();
            foreach ($this->nodeEnumerator->siteNodeInContexts($site) as $siteNode) {
                foreach ($this->nodeEnumerator->recurseDocumentChildNodes($siteNode) as $documentNode) {
                    if ($documentNode->getNodeType()->isOfType('Neos.Neos:Shortcut')) {
                        continue;
                    }
                    $documents[] = [
                        'identifier' => $documentNode->getNodeAggregateIdentifier(),
                        'contextPath' => $documentNode->getContextPath(),
                        'dimensions' => $documentNode->getDimensions(),
                        'site' => $siteNodeName,
                        'url' => $this->uriBuilder->uriFor(
                            'show',
                            [
                                'node' => $documentNode,
                            ],
                            'Frontend\Node',
                            'Neos.Neos',
                            )
                    ];
                }
            }

            $contentProperties['siteProperties'][$siteNodeName] = $this->contentPropertiesRenderer->buildSiteContentProperties(
                $site,
                $this->controllerContext,
                );
        }

        $this->view->assign('value', [
            'documents' => $documents,
            'contentProperties' => $contentProperties
        ]);
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
