<?php
namespace Oasis\Import\Controller;

use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Loader;
Use Oasis\Import\Main;

class Branding extends Controller
{
	/**
	 * @return array
	 */
	public function configureActions()
	{
		return [
			'get' => [
				'prefilters' => [
					new ActionFilter\HttpMethod([
						ActionFilter\HttpMethod::METHOD_POST
					]),
					new ActionFilter\Csrf(),
				]
			]
		];
	}

	/**
	 * Action get OASIS_PRODUCT_ID
	 * @param $product_id
	 * @return int|null
	 */
	public static function getAction($product_id = null)
	{
		Loader::includeModule('catalog');

		if(!empty($product_id) && Loader::includeModule('iblock')) {
			$product = ProductTable::getList([
				'select' => [
					'UF_OASIS_PRODUCT_ID'
				],
				'filter' => [
					'ID' => $product_id,
					'!UF_OASIS_PRODUCT_ID' => null,
				]
			])->fetch();

			if (!empty($product)) {
				return $product['UF_OASIS_PRODUCT_ID'];
			}
		}
		return null;
	}
}