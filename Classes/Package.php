<?php

namespace Networkteam\Neos\ContentApi;

use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;
use Neos\Neos\Service\PublishingService;
use Neos\Neos\Fusion\Cache\ContentCacheFlusher;
use Networkteam\Neos\ContentApi\Domain\Service\RevalidateNotifier;

class Package extends BasePackage
{
    public function boot(Bootstrap $bootstrap)
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();

        // TODO Revalidate all if site was changed
        // $dispatcher->connect(Site::class, 'siteChanged', $flushConfigurationCache);

        // TODO Revalidate documents where asset was used
        // $dispatcher->connect(AssetService::class, 'assetUpdated', ContentCacheFlusher::class, 'registerAssetChange', false);

        // TODO Inform about published nodes
        $dispatcher->connect(PublishingService::class, 'nodePublished', RevalidateNotifier::class, 'registerNodeChange', false);

        // TODO Do we need to inform about discarded nodes?
        // $dispatcher->connect(PublishingService::class, 'nodeDiscarded', RevalidateNotifier::class, 'registerNodeChange', false);

        // TODO Revalidate on site prune?
        // $dispatcher->connect(SiteService::class, 'sitePruned', ContentCache::class, 'flush');

        // TODO Revalidate on site import?
        // $dispatcher->connect(SiteImportService::class, 'siteImported', ContentCache::class, 'flush');
    }
}
