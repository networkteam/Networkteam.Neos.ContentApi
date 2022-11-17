<?php
namespace Networkteam\Neos\ContentApi\Exception;

class NodeNotFoundException extends \Networkteam\Neos\ContentApi\Exception
{
    /**
     * @var integer
     */
    protected $statusCode = 404;
}
