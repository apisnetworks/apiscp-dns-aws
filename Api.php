<?php declare(strict_types=1);
/**
 * Copyright (C) Apis Networks, Inc - All Rights Reserved.
 *
 * Unauthorized copying of this file, via any medium, is
 * strictly prohibited without consent. Any dissemination of
 * material herein is prohibited.
 *
 * For licensing inquiries email <licensing@apisnetworks.com>
 *
 * Written by Matt Saladna <matt@apisnetworks.com>, August 2018
 */


namespace Opcenter\Dns\Providers\Aws;

use Aws\AwsClient;
use Aws\Credentials\Credentials;
use Aws\Route53\Route53Client;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Response;

class Api {
	const API_VERISON = '2013-04-01';
	/**
	 * Create an Aws API instance
	 *
	 * @param string $key
	 * @param string $secret
	 * @param string $abstract
	 * @param array  $options
	 * @return AwsClient
	 */
	public static function api(string $key, string $secret, string $abstract, array $options = []): AwsClient
	{
		$credentials = new Credentials($key, $secret, $options['token'] ?? null, $options['expires'] ?? null);
		return new $abstract($options + [
			'credentials'  => $credentials,
			'version' => static::API_VERISON,
			'auth_headers' => [
				'User-Agent' => PANEL_BRAND . ' ' . APNSCP_VERSION,
			],
		]);
	}
}