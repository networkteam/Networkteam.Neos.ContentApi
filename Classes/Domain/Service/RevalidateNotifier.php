<?php

namespace Networkteam\Neos\ContentApi\Domain\Service;

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
     * TODO Inject configuration
     */
    protected $revalidateUrl = 'http://localhost:3000/api/revalidate';

    /**
     * @var string
     * TODO Inject configuration
     */
    protected $token = 'a-secret-token';

    /**
     * @var array
     */
    protected $documentNodesToRevalidate = [];


    /**
     * @Flow\Inject
     * @var LinkingService
     */
    protected $linkingService;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $systemLogger;

    public function __construct()
    {
        $this->client = new Client();
    }

    public function registerNodeChange(NodeInterface $node, Workspace $targetWorkspace = null): void
    {
        $this->systemLogger->debug('registerNodeChange for node ' . $node->getContextPath() . ' in target workspace ' . ($targetWorkspace ? $targetWorkspace->getName() : '<none>'));

        if ($targetWorkspace !== null && $targetWorkspace->isPublicWorkspace()) {
            $this->addNodeToRevalidate($node);
        }
    }

    private function addNodeToRevalidate(NodeInterface $node): void
    {
        $q = new FlowQuery([$node]);
        $documentNode = $q->closest('[instanceof Neos.Neos:Document]')->get(0);

        if ($documentNode === null) {
            $this->systemLogger->warning('Document node for' . $node->getContextPath() . ' not found');
            return;
        }

        $this->documentNodesToRevalidate[$documentNode->getContextPath()] = true;
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

        $routePartHandler = new FrontendNodeRoutePartHandler();
        $routePartHandler->setName('node');

        // TODO Set host by domain for site of node
        $routeParameters = RouteParameters::createEmpty()->withParameter('requestUriHost', 'localhost');

        $nodeInfos = [];

        $this->systemLogger->debug('Notifying revalidate API about ' . count($this->documentNodesToRevalidate) . ' changed nodes', [
            'nodes' => $this->documentNodesToRevalidate
        ]);

        foreach ($this->documentNodesToRevalidate as $contextPath => $value) {
            $contextPathParts = NodePaths::explodeContextPath($contextPath);

            // TODO We might want to revalidate _all_ fallbacks for the dimension value (or all dimension combinations to be sure)
            $liveContextPath = NodePaths::generateContextPath($contextPathParts['nodePath'], 'live', $contextPathParts['dimensions']);

            $values = [
                'node' => $liveContextPath
            ];
            // TODO Check if we can construct an ad-hoc ControllerContext and use uriFor
            $result = $routePartHandler->resolveWithParameters($values, $routeParameters);
            if ($result === true) {
                // TODO What to do here?
                continue;
            } elseif ($result instanceof ResolveResult) {
                $uri = '/' . $result->getResolvedValue();
                $nodeInfos[] = [
                    'routePath' => $uri,
                    'contextPath' => $liveContextPath,
                ];
            }
        }

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
}
