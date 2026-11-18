<?php

/**
 * @package     GitInSync
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Component\GitInSync\Administrator\Remote;

use Joomla\Http\HttpFactory;
use Joomla\Http\Http;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * HTTP through Joomla's own client (curl or streams, whatever the host has).
 */
final class JoomlaHttpTransport implements HttpTransport
{
	private ?Http $http = null;

	public function request(string $method, string $url, array $headers, ?string $body): array
	{
		$this->http ??= (new HttpFactory())->getHttp();

		$response = match ($method) {
			'GET'    => $this->http->get($url, $headers, 30),
			'POST'   => $this->http->post($url, $body ?? '', $headers, 30),
			'PATCH'  => $this->http->patch($url, $body ?? '', $headers, 30),
			'PUT'    => $this->http->put($url, $body ?? '', $headers, 30),
			'DELETE' => $this->http->delete($url, $headers, 30, $body),
			default  => throw new \InvalidArgumentException(\sprintf('Unsupported method %s.', $method)),
		};

		$responseHeaders = [];

		foreach ($response->getHeaders() as $name => $values) {
			$responseHeaders[strtolower((string) $name)] = implode(', ', (array) $values);
		}

		return [
			'status'  => $response->getStatusCode(),
			'headers' => $responseHeaders,
			'body'    => (string) $response->getBody(),
		];
	}
}
