<?php
namespace Networkteam\Neos\ContentApi\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Networkteam\Neos\ContentApi\Domain\Service\SitePropertiesRenderer;
use Networkteam\Neos\ContentApi\Http\DimensionsHelper;

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

		// Make sure to check for dimension values from routing parameters to support Flowpack.Neos.DimensionResolver
		$dimensionValues = DimensionsHelper::getDimensionValuesFromRequest($this->request->getHttpRequest());

		$siteProperties = $this->sitePropertiesRenderer->renderSiteProperties(
            $site,
            $this->controllerContext,
            $workspaceName,
			$dimensionValues
        );

        $this->view->assign('value', [
            'site' => $siteProperties,
        ]);
    }
}
