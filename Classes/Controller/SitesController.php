<?php
namespace Networkteam\Neos\ContentApi\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Networkteam\Neos\ContentApi\Domain\Service\SitePropertiesRenderer;

class SitesController extends ActionController
{

    use ErrorHandlingTrait;
    use SiteHandlingTrait;

    /**
     * @var string
     */
    protected $defaultViewObjectName = JsonView::class;

    /**
     * @Flow\Inject
     * @var SitePropertiesRenderer
     */
    protected $sitePropertiesRenderer;

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
     * Render current site for content API
     *
     * @param string $workspaceName
     * @throws \Neos\Flow\Security\Exception
     * @throws \Neos\Fusion\Exception
     * @throws \Neos\Neos\Domain\Exception
     */
    public function currentAction(string $workspaceName = 'live'): void
    {
        $site = $this->getActiveSite();

        $siteProperties = $this->sitePropertiesRenderer->renderSiteProperties(
            $site,
            $this->controllerContext,
            $workspaceName
        );

        $this->view->assign('value', [
            'site' => $siteProperties,
        ]);
    }
}
