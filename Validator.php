<?php
	declare(strict_types=1);

	/**
	 * Copyright (C) Apis Networks, Inc - All Rights Reserved.
	 *
	 * MIT License
	 *
	 * Written by Matt Saladna <matt@apisnetworks.com>, September 2018
	 */

	namespace Opcenter\Dns\Providers\Aws;

	use Aws\Exception\AwsException;
	use Aws\Route53\Route53Client;
	use Opcenter\Dns\Contracts\ServiceProvider;
	use Opcenter\Service\ConfigurationContext;

	class Validator implements ServiceProvider
	{
		const AWS_REGIONS = [
			'us-west-2',
			'us-west-1',
			'us-east-2',
			'us-east-1',
			'ap-south-1',
			'ap-northeast-2',
			'ap-southeast-1',
			'ap-southeast-2',
			'ap-northeast-1',
			'ca-central-1',
			'cn-north-1',
			'eu-central-1',
			'eu-west-1',
			'eu-west-2',
			'eu-west-3',
			'sa-east-1',
			'us-gov-west-1'
		];
		const AWS_DEFAULT = 'us-west-2';

		/**
		 * Validate service value
		 *
		 * @param ConfigurationContext $ctx
		 * @param                      $var service value
		 * @return bool
		 */
		public function valid(ConfigurationContext $ctx, &$var): bool
		{
			if (!\is_array($var) || !isset($var['key'], $var['secret'])) {
				return error('AWS key must provide both key and secret');
			}

			if (!isset($var['region'])) {
				$var['region'] = self::AWS_DEFAULT;
			}

			if (!\in_array($var['region'], self::AWS_REGIONS, true)) {
				return error("Unknown AWS region `%s'", $var['region']);
			}

			if (!static::keyValid($var['key'], (string)$var['secret'], $var['region'])) {
				return false;
			}

			return true;
		}

		public static function keyValid(string $key, string $secret, string $region = self::AWS_DEFAULT): bool
		{
			try {
				Api::api($key, $secret, Route53Client::class, ['region' => $region])->getHostedZoneCount();
			} catch (AwsException $e) {
				return error('AWS key failed: %s', $e->getAwsErrorMessage());
			}

			return true;
		}

	}
