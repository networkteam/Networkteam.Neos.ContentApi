<?php

namespace Networkteam\Neos\ContentApi\Fusion\Backend;

use Neos\Flow\Annotations as Flow;
use Neos\Fusion\Exception as FusionException;
use Neos\Fusion\FusionObjects\AbstractFusionObject;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Neos\Service\LinkingService;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Model\ImageVariant;
use Neos\Media\Domain\Model\ThumbnailConfiguration;
use Neos\Media\Domain\Service\AssetService;
use Neos\Flow\ResourceManagement\ResourceManager;
use Networkteam\Neos\ContentApi\Exception;

class ParseIncludesImplementation extends AbstractFusionObject
{

    protected function getHtml()
    {
        return $this->fusionValue('html');
    }

    /**
     * Parse given HTML and return a structured representation
     *
     * @return array
     * @throws Exception
     */
    public function evaluate()
    {
       return self::parseHTML($this->getHtml());
    }

    /**
     * @throws Exception
     * @internal It's public to make it easily testable
     */
    public static function parseHTML($html) {
        $dom = new \DOMDocument();
        $success = @$dom->loadHTML($html); // use @ to suppress warnings
        if ($success !== true) {
            throw new Exception('Failed to parse HTML', 1685618008);
        }
        $scripts = $dom->getElementsByTagName('script');
        $links = $dom->getElementsByTagName('link');

        $result = [];
        $counter = 1;

        foreach ($scripts as $script) {
            $key = 'script-' . $counter++;
            if ($script->nodeValue) {
                $result[] = ['key' => $key, 'type' => 'script', 'content' => $script->nodeValue];
            } else {
                $result[] = ['key' => $key, 'type' => 'script', 'src' => $script->getAttribute('src')];
            }
        }

        foreach ($links as $link) {
            $key = 'link-' . $counter++;
            $result[] = ['key' => $key, 'type' => 'link', 'href' => $link->getAttribute('href'), 'rel' => $link->getAttribute('rel')];
        }

        return $result;
    }

}
