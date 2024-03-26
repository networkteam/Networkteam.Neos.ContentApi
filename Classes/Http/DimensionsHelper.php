<?php

namespace Networkteam\Neos\ContentApi\Http;

use Neos\Flow\Http\ServerRequestAttributes;
use Psr\Http\Message\ServerRequestInterface as HttpRequestInterface;

class DimensionsHelper
{
	/**
	 * Get dimension values from request (if available)
	 *
	 * This method is used to get dimension values from routing parameters e.g. for use with Flowpack.Neos.DimensionResolver.
	 *
	 * @param HttpRequestInterface $request
	 * @return array|mixed
	 */
	public static function getDimensionValuesFromRequest(HttpRequestInterface $request)
	{
		$routingParameters = $request->getAttribute(ServerRequestAttributes::ROUTING_PARAMETERS);
		if ($routingParameters->has('dimensionValues')) {
			return json_decode($routingParameters->getValue('dimensionValues'), true);
		}

		return [];
	}
}
