<?php

namespace Oasis\Import;

use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Config\Option;
use Oasis\Import\Config as OasisConsfig;

class Api
{
	/**
	 * Get order data Oasiscatalog
	 *
	 * @param $queueId
	 * @return array
	 */
	public static function getOrder($queueId): array
	{
		return self::curlQuery('reserves/by-queue/' . $queueId);
	}

	/**
	 * Send order to Oasiscatalog
	 *
	 * @param $data
	 * @return array|mixed
	 */
	public static function sendOrder($data): mixed
	{
		$apiKey = Option::get(pathinfo(dirname(__DIR__))['basename'], 'api_key');

		if (empty($apiKey)) {
			return [];
		}

		$result = [];

		try {
			$options = [
				'http' => [
					'method' => 'POST',
					'header' => 'Content-Type: application/json' . PHP_EOL .
						'Accept: application/json' . PHP_EOL,

					'content' => json_encode($data),
				],
			];

			$result = json_decode(file_get_contents('https://api.oasiscatalog.com/v4/reserves/?key=' . $apiKey, 0, stream_context_create($options)));

		} catch (\Exception $exception) {
		}

		return $result;
	}

	/**
	 * Get stock oasis products
	 *
	 * @return array
	 */
	public static function getOasisStock(): array
	{
		return self::curlQuery('stock', ['fields' => 'id,stock,stock-remote,is-europe']);
	}

	/**
	 * Get stat products
	 *
	 * @return array
	 * @throws ArgumentNullException
	 * @throws ArgumentOutOfRangeException
	 */
	public static function getStatProducts(): array
	{
		$data = [
			'not_on_order' => (int)Option::get(OasisConsfig::MODULE_ID, 'not_on_order'),
			'price_from'   => (float)Option::get(OasisConsfig::MODULE_ID, 'price_from'),
			'price_to'     => (float)Option::get(OasisConsfig::MODULE_ID, 'price_to'),
			'rating'       => Option::get(OasisConsfig::MODULE_ID, 'rating') ? (int)Option::get(OasisConsfig::MODULE_ID, 'rating') : '0,1,2,3,4,5',
			'moscow'       => (int)Option::get(OasisConsfig::MODULE_ID, 'warehouse_moscow'),
			'europe'       => (int)Option::get(OasisConsfig::MODULE_ID, 'warehouse_europe'),
			'remote'       => (int)Option::get(OasisConsfig::MODULE_ID, 'remote_warehouse'),
		];

		$categories = Option::get(OasisConsfig::MODULE_ID, 'categories');

		if (!$categories) {
			$categories = implode(',', array_keys(Main::getOasisMainCategories()));
		}

		$args = [
			'category' => $categories,
		];

		foreach ($data as $key => $value) {
			if ($value) {
				$args[$key] = $value;
			}
		}
		unset($category, $data, $key, $value);

		return self::curlQuery('stat', $args);
	}

	/**
	 * Get oasis products by module settings
	 *
	 * @param array $args
	 * @return array
	 */
	public static function getProductsOasis(array $args = []): array
	{
		try {
			$args['fieldset'] = 'full';

			$data = [
				'currency'         => Option::get(OasisConsfig::MODULE_ID, 'currency') ?? 'rub',
				'no_vat'           => (bool)Option::get(OasisConsfig::MODULE_ID, 'no_vat') ?? 0,
				'not_on_order'     => (bool)Option::get(OasisConsfig::MODULE_ID, 'not_on_order'),
				'price_from'       => (float)Option::get(OasisConsfig::MODULE_ID, 'price_from'),
				'price_to'         => (float)Option::get(OasisConsfig::MODULE_ID, 'price_to'),
				'rating'           => (bool)Option::get(OasisConsfig::MODULE_ID, 'rating'),
				'warehouse_moscow' => (bool)Option::get(OasisConsfig::MODULE_ID, 'warehouse_moscow'),
				'warehouse_europe' => (bool)Option::get(OasisConsfig::MODULE_ID, 'warehouse_europe'),
				'remote_warehouse' => (bool)Option::get(OasisConsfig::MODULE_ID, 'remote_warehouse'),
			];

			$categories = Option::get(OasisConsfig::MODULE_ID, 'categories');

			if (!$categories) {
				$categories = implode(',', array_keys(Main::getOasisMainCategories()));
			}

			$args += [
				'category' => $categories,
			];

			foreach ($data as $key => $value) {
				if ($value) {
					$args[$key] = $value;
				}
			}
			unset($category, $data, $key, $value);
		} catch (\Exception $e) {
		}

		return self::curlQuery('products', $args);
	}

	/**
	 * Get
	 * @param array $IDS
	 * @return array
	 */
	public static function getProductsOasisOnlyFieldCategories(array $IDS = []): array
	{
		$args = [
			'fields' => 'id,categories',
			'strict' => true,
		];

		if (!empty($IDS)) {
			$args['ids'] = implode(',', $IDS);
		}

		$products = self::curlQuery('products', $args);

		if (!empty($products)) {
			foreach ($products as $product) {
				unset($product->included_branding, $product->full_categories);
			}
		}

		return $products;
	}

	/**
	 * Get categories oasis
	 *
	 * @param string $fields
	 * @return array
	 */
	public static function getCategoriesOasis(string $fields = ''): array
	{
		return self::curlQuery('categories', ['fields' => $fields ?? 'id,parent_id,root,level,slug,name,path']);
	}

	/**
	 * Get currencies oasis
	 *
	 * @return array
	 */
	public static function getCurrenciesOasis(): array
	{
		return self::curlQuery('currencies');
	}

	/**
	 * Get api data
	 *
	 * @param $type
	 * @param array $args
	 *
	 * @return array
	 */
	public static function curlQuery($type, array $args = []): array
	{
		$apiKey = Option::get(pathinfo(dirname(__DIR__))['basename'], 'api_key');

		if (empty($apiKey)) {
			return [];
		}

		$args = array_merge(['format' => 'json'], $args);

		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_USERPWD => $apiKey,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_URL => 'https://api.oasiscatalog.com/v4/' . $type . '?' . http_build_query($args)
		]);
		$result = json_decode(curl_exec($ch), false);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		sleep(1);

		if($http_code === 401){
			return [];
		}

		if($http_code !== 200){
			throw new \Exception('Error API');
		}

		return (array)$result;
	}
}