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
 * Written by Matt Saladna <matt@apisnetworks.com>, September 2018
 */

namespace Opcenter\Dns\Providers\Aws;

class Record extends \Opcenter\Dns\Record {
	/**
	 * @var int default DNS weight
	 */
	const DEFAULT_WEIGHT = 100;

	/**
	 * @var int $weight record weight
	 */
	protected $weight;

	public function __construct(string $zone, array $args)
	{
		if (isset($args['name']) && ends_with($args['name'], $zone)) {
			$args['name'] = rtrim((string)substr($args['name'], 0, \strlen($args['name']) - \strlen($zone)), '.');
		}
		parent::__construct($zone, $args);
	}


	/**
	 * @return array
	 */
	public function __debugInfo()
	{
		return [
			'zone'      => $this->zone,
			'name'      => $this->name,
			'rr'        => $this->rr,
			'parameter' => $this->parameter,
			'ttl'       => $this->ttl,
			'weight'    => $this->weight,
		];
	}

	public function hash() {
		// sha256 gets truncated in API
		return hash('md5', var_export($this, true));
	}
}
