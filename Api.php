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

	use Aws\Credentials\Credentials;
	use Aws\Exception\AwsException;

	class Api
	{
		const API_VERISON = '2013-04-01';
		protected $proxy;

		private function __construct(string $key, string $secret, string $abstract, array $options = [])
		{
			$credentials = new Credentials($key, $secret, $options['token'] ?? null, $options['expires'] ?? null);
			$this->proxy = new $abstract($options + [
					'credentials'  => $credentials,
					'version'      => static::API_VERISON,
					'auth_headers' => [
						'User-Agent' => PANEL_BRAND . ' ' . APNSCP_VERSION,
					],
				]);
		}

		/**
		 * Create an Aws API instance
		 *
		 * @param string $key
		 * @param string $secret
		 * @param string $abstract
		 * @param array  $options
		 * @return Api
		 */
		public static function api(string $key, string $secret, string $abstract, array $options = []): Api
		{
			return new static($key, $secret, $abstract, $options);
		}

		public function __call($name, $arguments)
		{
			try {
				return $this->proxy->$name(...$arguments);
			} catch (AwsException $e) {
				$code = $e->getStatusCode();
				if ($code === 429 || ($code === 400 && false !== strpos($e->getAwsErrorMessage(), 'Rate exceeded')) ) {
					usleep(250000);

					return $this->__call($name, $arguments);
				}
				throw $e;
			}

		}
	}