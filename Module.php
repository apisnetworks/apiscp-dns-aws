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

	use Aws\Route53\Exception\Route53Exception;
	use Aws\Route53\Route53Client;
	use Module\Provider\Contracts\ProviderInterface;

	class Module extends \Dns_Module implements ProviderInterface
	{
		use \NamespaceUtilitiesTrait;

		/**
		 * apex markers are marked with @
		 */
		protected const HAS_ORIGIN_MARKER = false;
		/**
		 * @var array
		 * @link https://docs.aws.amazon.com/Route53/latest/DeveloperGuide/ResourceRecordTypes.html
		 */
		protected static $permitted_records = [
			'A',
			'AAAA',
			'CAA',
			'CNAME',
			'MX',
			'NAPTR',
			'PTR',
			'SPF',
			'SRV',
			'TXT'
		];

		// @var array API credentials
		protected $metaCache = [];
		private $key;

		public function __construct()
		{
			parent::__construct();
			$this->key = $this->getServiceValue('dns', 'key', DNS_PROVIDER_KEY);
		}

		/**
		 * Add a DNS record
		 *
		 * @param string $zone
		 * @param string $subdomain
		 * @param string $rr
		 * @param string $param
		 * @param int    $ttl
		 * @return bool
		 */
		public function add_record(
			string $zone,
			string $subdomain,
			string $rr,
			string $param,
			int $ttl = self::DNS_TTL
		): bool {
			if (!$this->canonicalizeRecord($zone, $subdomain, $rr, $param, $ttl)) {
				return false;
			}
			$api = $this->makeApi();
			$record = self::createRecord($zone, [
				'name'      => $subdomain,
				'rr'        => $rr,
				'parameter' => $param,
				'ttl'       => $ttl,
			]);
			if ($record['name'] === '@') {
				$record['name'] = '';
			}
			try {
				$api->changeResourceRecordSets([
					'ChangeBatch'  => [
						'Changes' => [
							[
								'Action'            => 'CREATE',
								'ResourceRecordSet' => $this->formatRecord($record)
							]
						]
					],
					'HostedZoneId' => $this->getZoneId($zone)
				]);
				$this->addCache($record);
			} catch (Route53Exception $e) {
				$fqdn = ltrim(implode('.', [$subdomain, $zone]), '.');
				return error("Failed to create record `%s' type %s: %s", $fqdn, $rr, $e->getAwsErrorMessage());
			}
			$record->setMeta('id', $record->hash());
			$this->addCache($record);

			return true;
		}

		/**
		 * Get hosting nameservers
		 *
		 * @param string|null $domain
		 * @return array
		 */
		public function get_hosting_nameservers(string $domain = null): array
		{
			if (!$domain) {
				return error('Domain is required with AWS module');
			}
			$api = $this->makeApi();
			try {
				$set = $api->getHostedZone(['Id' => $this->getZoneId($domain)]);
			} catch (Route53Exception $e) {
				return [];
			}

			return $set['DelegationSet']['NameServers'] ?? [];
		}

		/**
		 * Add DNS zone to service
		 *
		 * @param string $domain
		 * @param string $ip
		 * @return bool
		 */
		public function add_zone_backend(string $domain, string $ip): bool
		{
			/**
			 * @var Zones $api
			 */
			$api = $this->makeApi();
			try {
				$api->createHostedZone([
					'Name'            => $domain,
					'CallerReference' => $domain . '-' . microtime(true)
				]);
			} catch (Route53Exception $e) {
				if ($e->getAwsErrorCode() === 'HostedZoneAlreadyExists') {
					return warn("Zone `%s' already exists", $domain);
				}

				return error("Failed to add zone `%s', error: %s", $domain, $e->getAwsErrorMessage());
			}

			return true;
		}

		/**
		 * Remove DNS zone from nameserver
		 *
		 * @param string $domain
		 * @return bool
		 */
		public function remove_zone_backend(string $domain): bool
		{
			$api = $this->makeApi();
			try {
				$data = (array)$this->get_zone_data($domain);
				// @todo batch to reduce API overhead
				foreach ($data as $rr => $records) {
					if ($rr === 'SOA' || $rr === 'NS') {
						continue;
					}
					foreach ($records as $record) {
						$this->remove_record($domain, $record['subdomain'], $rr, $record['parameter']);
					}
				}
				$api->deleteHostedZone([
					'Id' => $this->getZoneId($domain),
				]);
			} catch (Route53Exception $e) {
				return error("Failed to remove zone `%s', error: %s", $domain, $e->getAwsErrorMessage());
			}
			static::$zoneExistsCache[$domain] = null;

			return true;
		}

		/**
		 * @inheritDoc
		 */
		public function remove_record(string $zone, string $subdomain, string $rr, string $param = ''): bool
		{
			if (null === $param) {
				$param = '';
			}
			if (!$this->canonicalizeRecord($zone, $subdomain, $rr, $param, $ttl)) {
				return false;
			}
			$api = $this->makeApi();
			$id = $this->getRecordId($r = new Record($zone,
				['name' => $subdomain, 'rr' => $rr, 'parameter' => $param]));
			if (!$id) {
				$fqdn = ltrim(implode('.', [$subdomain, $zone]), '.');

				return error("Record `%s' (rr: `%s', param: `%s')  does not exist", $fqdn, $rr, $param);
			}
			if (!$param) {
				$r['parameter'] = array_get($this->getRecordFromCache($r), 'parameter');
			}

			try {
				$api->changeResourceRecordSets([
					'ChangeBatch'  => [
						'Changes' => [
							[
								'Action'            => 'DELETE',
								'ResourceRecordSet' => $this->formatRecord($r),
							]
						]
					],
					'HostedZoneId' => $this->getZoneId($zone)
				]);
			} catch (Route53Exception $e) {
				$fqdn = ltrim(implode('.', [$subdomain, $zone]), '.');

				return error("Failed to delete record `%s' type %s: %s", $fqdn, $rr, $e->getAwsErrorMessage());
			}

			$items = array_pull($this->zoneCache[$r->getZone()], $this->getCacheKey($r), []);
			if (\count($items) > 1) {
				foreach ($items as $k => $i) {
					if ($i->is($r)) {
						unset($items[$k]);
					}
				}
				array_set($this->zoneCache[$r->getZone()], $this->getCacheKey($r), $items);
			}

			return true;
		}

		/**
		 * Get raw zone data
		 *
		 * @param string $domain
		 * @return null|string
		 */
		protected function zoneAxfr(string $domain): ?string
		{
			$client = $this->makeApi();
			try {
				$zoneId = $this->getZoneId($domain);
				$marker = null;
				$records = [];
				do {
					$data = $client->listResourceRecordSets([
						'HostedZoneId' => $zoneId,
						'Marker'       => $marker
					]);
					$raw = array_map(static function ($zone) {
						return (array)$zone;
					}, $data->toArray()['ResourceRecordSets']);
					$records = array_merge($records, $raw);
					$marker = $data['Marker'] ?? null;
				} while ($data['IsTruncated']);
			} catch (Route53Exception $e) {
				return null;
			}

			$this->zoneCache[$domain] = [];
			$zoneText = [];
			foreach ($records as $r) {
				foreach ($r['ResourceRecords'] as $record) {
					$parameter = $record['Value'];
					$robj = new Record($domain,
						[
							'name'      => rtrim($r['Name'], '.'),
							'rr'        => $r['Type'],
							'ttl'       => $r['TTL'] ?? static::DNS_TTL,
							'parameter' => $parameter,
							'meta'      => [
								'id' => $r['SetIdentifier'] ?? null
							]
						]
					);
					$this->addCache($robj);
					if ($r['Type'] === 'SOA') {
						array_unshift($zoneText, (string)$robj);
					} else {
						$zoneText[] = (string)$robj;
					}

				}
			}

			return implode("\n", $zoneText);
		}

		/**
		 * Create AWS API
		 *
		 * @return Route53Client
		 */
		private function makeApi()
		{
			return Api::api(
				$this->key['key'],
				$this->key['secret'],
				Route53Client::class,
				[
					'region' => array_get($this->key, 'region', Validator::AWS_DEFAULT)
				]
			);
		}

		/**
		 * Get internal AWS zone ID
		 *
		 * @param string $domain
		 * @return null|string
		 */
		protected function getZoneId(string $domain): ?string
		{
			return $this->getZoneMeta($domain, 'Id');
		}

		/**
		 * Get zone meta information
		 *
		 * @param string $domain
		 * @param string $key
		 * @return mixed|null
		 */
		private function getZoneMeta(string $domain, string $key)
		{
			if (!isset($this->metaCache[$domain])) {
				$this->populateZoneMetaCache();
			}

			return $this->metaCache[$domain][$key] ?? null;
		}

		/**
		 * Populate all zone cache
		 */
		protected function populateZoneMetaCache()
		{
			$api = $this->makeApi();
			$marker = null;
			do {
				$data = $api->listHostedZones([
					'Marker' => $marker
				]);
				$raw = array_map(static function ($zone) {
					return (array)$zone;
				}, $data->toArray()['HostedZones']);
				$this->metaCache = array_merge(
					$this->metaCache,
					array_combine(
						array_map(static function ($domain) {
							return rtrim($domain, '.');
						},
							array_column($raw, 'Name')), $raw
					)
				);
				$marker = $data['Marker'] ?? null;
			} while ($data['IsTruncated']);
		}

		/**
		 * Modify a DNS record
		 *
		 * @param string $zone
		 * @param Record $old
		 * @param Record $new
		 * @return bool
		 */
		protected function atomicUpdate(string $zone, \Opcenter\Dns\Record $old, \Opcenter\Dns\Record $new): bool
		{
			if (!$this->canonicalizeRecord($zone, $old['name'], $old['rr'], $old['parameter'], $old['ttl'])) {
				return false;
			}
			if (!$this->getRecordId($old)) {
				return error("failed to find record ID in AWS zone `%s' - does `%s' (rr: `%s', parameter: `%s') exist?",
					$zone, $old['name'], $old['rr'], $old['parameter']);
			}
			if (!$this->canonicalizeRecord($zone, $new['name'], $new['rr'], $new['parameter'], $new['ttl'])) {
				return false;
			}
			$api = $this->makeApi();

			try {
				$merged = clone $old;
				$new = $merged->merge($new);
				$api->changeResourceRecordSets([
					'ChangeBatch'  => [
						'Changes' => [
							[
								'Action'            => 'DELETE',
								'ResourceRecordSet' => $this->formatRecord($old)
							],
							[
								'Action'            => 'CREATE',
								'ResourceRecordSet' => $this->formatRecord($new)
							]
						]
					],
					'HostedZoneId' => $this->getZoneId($zone)
				]);
			} catch (Route53Exception $e) {
				return error("Failed to update record `%s' on zone `%s' (old - rr: `%s', param: `%s'; new - rr: `%s', param: `%s'): %s",
					$old['name'],
					$zone,
					$old['rr'],
					$old['parameter'], $new['name'] ?? $old['name'], $new['parameter'] ?? $old['parameter'],
					$e->getAwsErrorMessage()
				);
			}
			array_forget($this->zoneCache[$old->getZone()], $this->getCacheKey($old));
			$this->addCache($new);

			return true;
		}

		/**
		 * Format AWS record
		 *
		 * @param Record $r
		 * @return array
		 */
		protected function formatRecord(Record $r)
		{
			$args = [
				'Type'   => strtoupper($r['rr']),
				'Weight' => $r['weight'] ?? Record::DEFAULT_WEIGHT
			];
			// AWS has a strict match on TTL with records.
			// Retrieve what we can from cache if it's missing
			if ($r['ttl'] === null) {
				$r['ttl'] = array_get($this->getRecordFromCache($r), 'ttl', self::DNS_TTL);
			}
			$args['TTL'] = $r['ttl'];
			$fqdn = ltrim($r['name'] . '.' . $r['zone'], '.');

			if (!($id = $this->getRecordId($r))) {
				//debug("Missed record ID for %s", var_export($r, true));
				$id = $r->hash();
			}

			return $args + [
					'Name'            => $fqdn,
					'SetIdentifier'   => $id,
					'ResourceRecords' => [['Value' => $r['parameter']]]
				];
		}

		protected function hasCnameApexRestriction(): bool
		{
			return true;
		}


	}