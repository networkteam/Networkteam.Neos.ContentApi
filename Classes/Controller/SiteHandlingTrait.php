<?php
namespace Networkteam\Neos\ContentApi\Controller;

use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;

trait SiteHandlingTrait
{

    /**
     * @var DomainRepository
     */
    protected $domainRepository;

    /**
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @return Site
     * @throws \Neos\Neos\Domain\Exception
     */
    private function getActiveSite(): Site
    {
        $currentDomain = $this->domainRepository->findOneByActiveRequest();
        if ($currentDomain !== null) {
            $site = $currentDomain->getSite();
        } else {
            $site = $this->siteRepository->findDefault();
        }
        return $site;
    }
}
