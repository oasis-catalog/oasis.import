<?php

namespace Oasis\Import;

use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Config\Option;
use Oasis\Import\Config as OasisConfig;

class Api
{
	public static OasisConfig $cf;

	/**
	 * Get order data Oasiscatalog
	 * @param $queueId
	 * @return array
	 */
	public static function getOrder($queueId): array
	{
		return self::curlQuery('reserves/by-queue/' . $queueId);
	}

	/**
	 * Send order to Oasiscatalog
	 * @param $data
	 * @return array|mixed
	 */
	public static function sendOrder($data): mixed
	{
		$apiKey = Option::get(OasisConfig::MODULE_ID, 'api_key');

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
	 * @param array $data
	 * @param array $params
	 * @return array|mixed
	 */
	public static function brandingCalc($data, $params) {
		return self::curlSend('branding/calc', $data, $params);
	}

	/**
	 * @param $id
	 * @param $admin
	 * @return array|mixed
	 */
	public static function getBrandingCoef($id, $admin = false) {
		return self::curlQuery('branding/coef', [
			'id' => $id 
		], 'v4');
	}

	/**
	 * Get stock oasis products
	 *
	 * @return array
	 */
	public static function getStockOasis(): array
	{
		return self::curlQuery('stock', ['fields' => 'id,stock,stock-remote,is-europe']);
	}

	/**
	 * Get brands
	 * @return array
	 */
	public static function getBrands(): array
	{
		return self::curlQuery('brands', [], 'v3');
	}

	/**
	 * Get stat products
	 * @param array $args
	 * @return array
	 */
	public static function getStatProducts(array $args = []): array
	{
		$data = [
			'not_on_order'  => self::$cf->is_not_on_order,
			'excludeDefect' => self::$cf->is_not_defect,
			'price_from'    => self::$cf->price_from,
			'price_to'      => self::$cf->price_to,
			'rating'        => self::$cf->rating,
			'moscow'        => self::$cf->is_wh_moscow,
			'europe'        => self::$cf->is_wh_europe,
			'remote'        => self::$cf->is_wh_remote,
			'category'      => implode(',', self::$cf->categories ?: array_keys(Main::getOasisMainCategories())),
		];
		foreach ($data as $key => $value) {
			if ($value) {
				$args[$key] = $value;
			}
		}
		return self::curlQuery('stat', $args);
	}

	/**
	 * Get oasis products by module settings
	 * @param array $args
	 * @return array
	 */
	public static function getProductsOasis(array $args = []): array
	{
		$data = [
			'fieldset'      => 'full',
			'not_on_order'  => self::$cf->is_not_on_order,
			'excludeDefect' => self::$cf->is_not_defect,
			'currency'      => self::$cf->currency,
			'no_vat'        => self::$cf->is_no_vat,
			'not_on_order'  => self::$cf->is_not_on_order,
			'price_from'    => self::$cf->price_from,
			'price_to'      => self::$cf->price_to,
			'rating'        => self::$cf->rating,
			'moscow'        => self::$cf->is_wh_moscow,
			'europe'        => self::$cf->is_wh_europe,
			'remote'        => self::$cf->is_wh_remote,
		];
		foreach ($data as $key => $value) {
			if ($value) {
				$args[$key] = $value;
			}
		}

		$products = self::curlQuery('products', $args);

		if (!empty($products) && Main::arrayKeysExists($args, ['limit', 'ids', 'articles'])) {
			unset($args['limit'], $args['offset'], $args['ids'], $args['articles']);

			$group_ids = [$products[array_key_first($products)]->group_id];

			if (count($products) > 1) {
				$group_ids[] = $products[array_key_last($products)]->group_id;
			}

			$args['group_id'] = implode(',', array_unique($group_ids));
			$addProducts = self::curlQuery('products', $args);

			foreach ($addProducts as $addProduct) {
				if (!Main::findItem($products, fn($item) => $item->id == $addProduct->id)) {
					$products[] = $addProduct;
				}
			}
		}
		return $products;
	}

	/**
	 * @param array $ids
	 * @return array
	 */
	public static function getProductsOasisOnlyFieldCategories(array $ids = []): array
	{
		$products = [];
		$args = [
			'fields' => 'id,categories',
			'strict' => true,
		];

		while (count($ids) > 0) {
			$args['ids'] = implode(',', array_splice($ids, 0 , 20));
			$products = array_merge($products, self::curlQuery('products', $args));
		}
		return $products;
	}

	/**
	 * Get categories oasis
	 * @param string $fields
	 * @return array
	 */
	public static function getCategoriesOasis(string $fields = ''): array
	{
		return self::curlQuery('categories', ['fields' => $fields ?? 'id,parent_id,root,level,slug,name,path']);
	}

	/**
	 * Get currencies oasis
	 * @return array
	 */
	public static function getCurrenciesOasis(): array
	{
		return self::curlQuery('currencies');
	}

	/**
	 * Send data by POST method
	 * @param string $type
	 * @param array $data
	 * @return array|mixed
	 */
	public static function curlSend( string $type, array $data, array $params = []) {
		if (empty(self::$cf->api_key)){
			return [];
		}

		$args_pref = [
			'key'    => self::$cf->api_key,
			'format' => 'json',
		];

		try {
			$ch = curl_init('https://api.oasiscatalog.com/v4/' . $type . '?' . http_build_query($args_pref));
			curl_setopt_array($ch, [
				CURLOPT_POST 			=> 1,
				CURLOPT_POSTFIELDS 		=> http_build_query($data, '', '&'),
				CURLOPT_RETURNTRANSFER	=> true,
				CURLOPT_SSL_VERIFYPEER	=> false,
				CURLOPT_HEADER			=> false,
				CURLOPT_TIMEOUT			=> $params['timeout'] ?? 0
			]);
			$content = curl_exec($ch);

			if ( $content === false ) {
				throw new Exception( 'Error: ' . curl_error( $ch ) );
			} else {
				$result = json_decode( $content );
			}

			$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );

			if ( $http_code === 401 ) {
				throw new Exception( 'Error Unauthorized. Invalid API key!' );
			} elseif ( $http_code != 200 && $http_code != 500 ) {
				throw new Exception( 'Error: ' . ( $result->error ?? '' ) . PHP_EOL . 'Code: ' . $http_code );
			}
		} catch ( Exception $e ) {
			if (PHP_SAPI === 'cli') {
				echo $e->getMessage() . PHP_EOL;
			}
			return [];
		}

		return $result;
	}

	/**
	 * Get api data
	 * @param $type
	 * @param array $args
	 * @return array
	 */
	public static function curlQuery($type, array $args = [], string $version = 'v4'): array
	{
		if (empty(self::$cf->api_key)){
			return [];
		}

		$args = array_merge(['format' => 'json'], $args);

		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_USERPWD => self::$cf->api_key,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_URL => 'https://api.oasiscatalog.com/'.$version.'/' . $type . '?' . http_build_query($args)
		]);
		$result = json_decode(curl_exec($ch), false);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if($http_code === 401){
			throw new Exception('Error Unauthorized. Invalid API key!');
		} elseif ($http_code != 200) {
			self::$cf->log('Error API: http_code: ' . $http_code);
			throw new Exception('Error. Code: ' . $http_code);
		}

		return (array)$result;
	}
}