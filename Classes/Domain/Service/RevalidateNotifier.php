<?php

namespace Networkteam\Neos\ContentApi\Domain\Service;

use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Eel\FlowQuery\FlowQuery;
use GuzzleHttp\Client;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Mvc\Routing\Dto\ResolveResult;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Neos\Routing\FrontendNodeRoutePartHandler;
use Neos\Neos\Service\LinkingService;
use Psr\Log\LoggerInterface;

/**
 * @Flow\Scope("singleton")
 */
class RevalidateNotifier
{

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var string
     */
    protected $revalidateUrl = 'http://localhost:3000/api/revalidate';

    /**
     * @var string
     */
    protected $token = 'a-secret-token';

    /**
     * @var array
     */
    protected $documentNodesToRevalidate = [];

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $systemLogger;

    /**
     * @var array
     */
    protected $settings = [];

    /**
     * @var string
     */
    protected $removedDocumentsRoutePaths = [];

    /**
     * @param array $settings
     * @return void
     */
    public function injectSettings(array $settings)
    {
        $this->settings = $settings;

        $this->revalidateUrl = $settings['revalidateUrl'];
        $this->token = $settings['revalidateToken'];
    }

    public function __construct()
    {
        $this->client = new Client();
    }

    public function nodeWillBePublished(NodeInterface $node, Workspace $targetWorkspace = null): void
    {
        $this->systemLogger->debug('Handling nodeWillBePublished for node ' . $node->getContextPath() . ' in target workspace ' . ($targetWorkspace ? $targetWorkspace->getName() : '<none>'));

        if ($targetWorkspace !== null && $targetWorkspace->isPublicWorkspace()) {
            $this->addNodeToRevalidate($node);
        }
    }

    public function nodeWasPublished(NodeInterface $node, Workspace $targetWorkspace = null): void
    {

    }

    private function addNodeToRevalidate(NodeInterface $node): void
    {
        $q = new FlowQuery([$node]);
        /** @var NodeInterface $documentNode */
        $documentNode = $q->closest('[instanceof Neos.Neos:Document]')->get(0);

        if ($documentNode === null) {
            $this->systemLogger->warning('Document node for' . $node->getContextPath() . ' not found');
            return;
        }

        $documentContextPath = $documentNode->getContextPath();
        if (isset($this->documentNodesToRevalidate[$documentContextPath])) {
            return;
        }

        $this->documentNodesToRevalidate[$documentContextPath] = true;

        // Remember the route path for the document node that will be removed after publishing!
        if ($documentNode->isRemoved()) {

            $this->systemLogger->debug('Document node is removed, so we better find the route path for it - NOW!');
            $this->removedDocumentsRoutePaths[$documentContextPath] = $this->getRoutePath($documentContextPath);
        }
    }

    public function shutdownObject(): void
    {
        $this->commit();
    }

    /**
     * Notify the revalidation service about the changed nodes
     */
    private function commit(): void
    {
        if (count($this->documentNodesToRevalidate) === 0) {
            return;
        }

        $nodeInfos = [];

        foreach ($this->documentNodesToRevalidate as $contextPath => $_) {
            // The route path might be already generated, because the node was removed.
            // Otherwise, we generate it now. Because if a node is created, moved or URI segment changed, it has to be
            // published for the routing to be complete.
            $routePath = $this->removedDocumentsRoutePaths[$contextPath] ?? $this->getRoutePath($contextPath);
            if ($routePath !== null) {
                $nodeInfos[] = [
                    'routePath' => $routePath
                ];
            }
        }

        $this->systemLogger->debug('Notifying revalidate API about ' . count($this->documentNodesToRevalidate) . ' changed nodes', [
            'documents' => $nodeInfos
        ]);

        try {
            $this->client->post($this->revalidateUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token
                ],
                'json' => [
                    'documents' => $nodeInfos
                ]
            ]);
        } catch (\Exception $e) {
            $this->systemLogger->error('Error notifying revalidate API', [
                'exception' => $e
            ]);
        }
    }

    private function getRoutePath(string $contextPath): ?string
    {
        $routePartHandler = new FrontendNodeRoutePartHandler();
        $routePartHandler->setName('node');

        // TODO Set host by domain for site of node
        $routeParameters = RouteParameters::createEmpty()->withParameter('requestUriHost', 'localhost');

        $contextPathParts = NodePaths::explodeContextPath($contextPath);

        // TODO We might want to revalidate _all_ fallbacks for the dimension value (or all dimension combinations to be sure)
        $liveContextPath = NodePaths::generateContextPath($contextPathParts['nodePath'], 'live', $contextPathParts['dimensions']);

        $values = [
            'node' => $liveContextPath
        ];

        // TODO Check if we can construct an ad-hoc ControllerContext and use uriFor
        $result = $routePartHandler->resolveWithParameters($values, $routeParameters);

        $this->systemLogger->debug('Building URL for Node with context path ' . $liveContextPath, [
            'result' => $result?->getResolvedValue()
        ]);

        if (!($result instanceof ResolveResult)) {
            return null;
        }
        $routePath = '/' . $result->getResolvedValue();
        return $routePath;
    }
}
