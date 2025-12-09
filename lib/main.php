<?php

namespace Oasis\Import;

use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\MeasureRatioTable;
use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Catalog\StoreTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\Model\PropertyFeature;
use Bitrix\Iblock\Model\Section;
use Bitrix\Iblock\PropertyEnumerationTable;
use Bitrix\Iblock\PropertyIndex\Manager;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Iblock\SectionPropertyTable;
use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Entity;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserFieldTable;
use CCatalogSKU;
use CFile;
use CIBlockElement;
use CIBlockSection;
use CUserTypeEntity;
use Exception;
use JetBrains\PhpStorm\NoReturn;
use Oasis\Import\Config as OasisConfig;


class Main
{
	public const ATTR_COLOR_ID    = 1000000001; // Цвет товара
	public const ATTR_MATERIAL_ID = 1000000002; // Материал товара
	public const ATTR_BARCODE_ID  = 1000000011; // Штрихкод
	public const ATTR_FLASH_ID    = 219;        // Объем памяти
	public const ATTR_MARKING_ID  = 254;        // Обязательная маркировка
	public const ATTR_REMOTE_ID   = 310;        // Минимальная сумма для удалённого склада
	public const ATTR_SIZE_NAME   = 'Размер';


	public static OasisConfig $cf;
	public static array $catSelected;

	private static array $oasisCategories;
	private static $hl_edc_color;
	private static $catalog_group_id;

	/**
	 * Load and prepare brands
	 * @return void
	 */
	public static function getBrands(): array
	{
		$list = Api::getBrands() ?? [];
		$brands = [];
		foreach ($list as $brand){
			$brands[$brand->id] = [
				'name' => $brand->name,
				'slug' => $brand->slug,
				'logotype' => $brand->logotype,
				'XML_ID' => null
			];
		}
		return $brands;
	}

	/**
	 * Get order id oasis
	 * @param int $orderId
	 * @return array|false
	 */
	public static function getOasisOrder(int $orderId)
	{
		$result = false;

		try {
			$result = Application::getConnection()->query("SELECT * FROM b_oasis_import_orders  WHERE `ID_ORDER` = " . $orderId)->fetch();
		} catch (SystemException $e) {
			echo $e->getMessage() . PHP_EOL;
		}

		return $result;
	}

	/**
	 * Insert order data in b_oasis_import_orders
	 * @param int $orderId
	 * @param int $queueId
	 */
	protected function addOasisOrder(int $orderId, int $queueId)
	{
		try {
			$queryRow = self::getOasisOrder($orderId);

			if (!$queryRow) {
				Application::getConnection()->queryExecute("INSERT INTO b_oasis_import_orders (ID_ORDER, ID_QUEUE) VALUES (" . $orderId . ", " . $queueId . ")");
			}
		} catch (SystemException $e) {
			echo $e->getMessage() . PHP_EOL;
		}
	}

	/**
	 * Get branding info
	 * @param array $data
	 * @param int $quantity
	 * @return array
	 */
	public static function getBrandingInfo(array $data, int $quantity)
	{
		$labels = [];
		$cost = 0;
		[
			'branding'  => $branding,
			'productId' => $productId,
		] = self::prepareBrandingData($data, $quantity);

		$brandingInfo = Api::brandingCalc($branding, ['timeout' => 10]);
		if (empty($brandingInfo->error) && !empty($brandingInfo->branding)) {

			$info = Api::getBrandingCoef($productId);
			if ($info) {
				foreach ($data as $data_item) {
					foreach ($info['methods'] as $method) {
						foreach ($method->types as $type) {
							if ($data_item['typeId'] == $type->id) {
								$labels[] = $type->name;
								break 2;
							}
						}
					}
				}
			}
			$cost = self::getBrandingPrice($brandingInfo);
		}
		else {
			return null;
		}
		return [
			'label' => implode(', ', $labels),
			'cost'  => $cost,
		];
	}

	/**
	 * Get array for Api request
	 * @return array data
	 * @return int quantity
	 * @return array
	 */
	private static function prepareBrandingData(array $data, int $quantity)
	{
		$productId = null;
		$brandings = [];
		$item = [
			'quantity' => $quantity
		];

		foreach ($data as $data_item) {
			if (empty($productId)) {
				$item['productId'] = $productId = $data_item['productId'];
				$item['branding'] = [];
			}

			$item['branding'][] = count($brandings);
			$brandings[] = array_intersect_key($data_item, array_flip(['placeId', 'typeId', 'width', 'height']));
		}

		return [
			'branding'  => ['items' => [$item], 'branding' => $brandings],
			'productId' => $productId
		];
	}

	/**
	 * Get price for branding data
	 * @return brandingInfo
	 * @return int
	 */
	private static function getBrandingPrice($brandingInfo)
	{
		return $brandingInfo->branding[0]->{0}->price->client->total ?? 0;
	}

	/**
	 * Get all products oasis in DB
	 * @return array
	 * @throws SqlQueryException
	 */
	public static function getAllOaProducts(): array
	{
		return Application::getConnection()->query("
		SELECT
			P.ID, P.TYPE, U.UF_OASIS_PRODUCT_ID
		FROM
			 b_uts_product U
				 JOIN b_catalog_product P 
					 ON U.VALUE_ID=P.ID
		WHERE U.UF_OASIS_PRODUCT_ID IS NOT NULL
		")->fetchAll();
	}

	/**
	 * @param $productId
	 * @param int $type
	 * @return array|false
	 */
	public static function checkProduct($productId, ?int $type = null)
	{
		return reset(self::checkProducts($productId, $type));
	}

	/**
	 * @param $productId
	 * @param int $type
	 * @return array
	 */
	public static function checkProducts($productId, ?int $type = null)
	{
		$arFields = [
			'select' => ['ID', 'TYPE', 'UF_OASIS_UPDATE_AT'],
			'filter' => [
				'UF_OASIS_PRODUCT_ID' => $productId,
			],
		];
		if ($type) {
			$arFields['filter']['TYPE'] = $type;
		}

		$result   = ProductTable::getList($arFields);
		$products = [];
		while ($item = $result->fetch()) {
			$result_item = ElementTable::getList([
				'select' => ['ID', 'ACTIVE'],
				'filter' => [
					'ID'        => $item['ID'],
					'IBLOCK_ID' => $type == ProductTable::TYPE_OFFER ? self::$cf->iblock_offers : self::$cf->iblock_catalog,
				],
			])->fetch();

			$item['ACTIVE'] = $result_item['ACTIVE'];
			$products[] = $item;
		}
		return $products;
	}

	/**
	 * Add Iblock Element Product
	 * @param $product
	 * @param $properties
	 * @param $type
	 * @return false|mixed|void
	 */
	public static function addIblockElementProduct($product, $properties, $type)
	{
		try {
			$iblockId = $type == ProductTable::TYPE_OFFER ? self::$cf->iblock_offers : self::$cf->iblock_catalog;
			$data     = [];

			if (!empty($properties['DETAIL_PICTURE'])) {
				$data['DETAIL_PICTURE'] = $properties['DETAIL_PICTURE'];
				unset($properties['DETAIL_PICTURE']);
			}

			if (!empty($properties['PREVIEW_PICTURE'])) {
				$data['PREVIEW_PICTURE'] = $properties['PREVIEW_PICTURE'];
				unset($properties['PREVIEW_PICTURE']);
			}

			$data += [
				'NAME'             => $product->name,
				'CODE'             => self::getUniqueCodeElement($product->name),
				'IBLOCK_ID'        => $iblockId,
				'DETAIL_TEXT'      => '<p>' . htmlentities($product->description, ENT_QUOTES, 'UTF-8') . '</p>' . self::getProductDetailText($product),
				'DETAIL_TEXT_TYPE' => 'html',
				'PROPERTY_VALUES'  => $properties,
				'ACTIVE'           => self::getStatusProduct($product),
			];

			if ($type !== ProductTable::TYPE_OFFER) {
				$data += self::getIblockSectionProduct($product);
			}

			$el = new CIBlockElement;
			$productId = $el->Add($data);

			if (!empty($el->LAST_ERROR)) {
				$_err = ($type === ProductTable::TYPE_OFFER ? 'Ошибка добавления торгового предложения: ' : 'Ошибка добавления товара: ') . $el->LAST_ERROR;
				self::$cf->log($_err);
				throw new SystemException($_err);
			}
		} catch (SystemException $e) {
			echo $e->getMessage() . PHP_EOL;
			die();
		}

		return $productId;
	}

	/**
	 * Update Iblock Element Product
	 * @param $dbProduct
	 * @param $product
	 * @param $is_need_up
	 * @param $type
	 */
	public static function upIblockElementProduct($dbProduct, $product, $is_need_up, ?int $type = null): void
	{
		$iblockElementId = (int)$dbProduct['ID'];
		$active          = self::getStatusProduct($product);

		$data = [];
		if ($is_need_up) {
			$data += [
				'NAME'             => $product->name,
				'DETAIL_TEXT'      => '<p>' . htmlentities($product->description, ENT_QUOTES, 'UTF-8') . '</p>' . self::getProductDetailText($product),
				'DETAIL_TEXT_TYPE' => 'html',
				'ACTIVE'           => $active,
			];
		}
		else {
			if ($type !== ProductTable::TYPE_SKU && $dbProduct['ACTIVE'] !== $active) {
				$data += [
					'ACTIVE' => $active
				];
			}
		}
		if ($type !== ProductTable::TYPE_OFFER && !self::$cf->not_up_product_cat) {
			$data += self::getIblockSectionProduct($product);
		}
		
		if (self::$cf->up_photo || !self::checkDataImages($iblockElementId, $product)) {
			self::deleteImages($iblockElementId);

			$dataImages = self::getProductImages($product);
			CIBlockElement::SetPropertyValuesEx($iblockElementId, false, ['MORE_PHOTO' => $dataImages['MORE_PHOTO']]);

			$data['DETAIL_PICTURE'] = $dataImages['DETAIL_PICTURE'];
			$data['PREVIEW_PICTURE'] = $dataImages['PREVIEW_PICTURE'];
		}

		if (!empty($data)) {
			$el = new CIBlockElement;
			$el->Update($iblockElementId, $data);
			if (!empty($el->LAST_ERROR)) {
				$_err = ($type === ProductTable::TYPE_OFFER ? 'Ошибка обновления торгового предложения: ' : 'Ошибка обновления товара: ') . $el->LAST_ERROR;
				self::$cf->log($_err);
				throw new SystemException($_err);
			}
		}
	}

	/**
	 * Update Iblock Element Product
	 * @param $iblockElementId
	 * @param $product
	 * @param $is_up
	 */
	public static function iblockElementProductAddImage($iblockElementId, $product, $is_up = false): void
	{
		if ($is_up || !self::checkDataImages($iblockElementId, $product)) {
			self::deleteImages($iblockElementId);

			$dataImages = self::getProductImages($product);
			CIBlockElement::SetPropertyValuesEx($iblockElementId, false, ['MORE_PHOTO' => $dataImages['MORE_PHOTO']]);

			$el = new CIBlockElement;
			$el->Update($iblockElementId, [
				'DETAIL_PICTURE'  => $dataImages['DETAIL_PICTURE'],
				'PREVIEW_PICTURE' => $dataImages['PREVIEW_PICTURE'],
			]);
		}
	}

	/**
	 * Check need update product
	 * @param $groupId
	 * @param $product
	 * @return bool
	 */
	public static function getNeedUp($product, $dbProduct)
	{
		return ($product->updated_at ?? '1') > ($dbProduct['UF_OASIS_UPDATE_AT'] ?? '');
	}

	/**
	 * Check and delete group Oasis
	 * @param $groupId
	 */
	public static function checkDeleteGroup($groupId)
	{
		$dbResult = ProductTable::getList([
			'select' => ['ID'],
			'filter' => [
				'UF_OASIS_GROUP_ID' => $groupId,
			],
		]);
		while ($dbProduct = $dbResult->fetch()) {
			self::deleteIblockElement($dbProduct['ID']);
		}
	}

	/**
	 * Check and delete product Oasis
	 * @param $productId
	 */
	public static function checkDeleteProduct($productId)
	{
		$dbResult = ProductTable::getList([
			'select' => ['ID', 'TYPE'],
			'filter' => [
				'UF_OASIS_PRODUCT_ID' => $productId,
			],
		]);

		while ($dbProduct = $dbResult->fetch()) {
			if ($dbProduct['TYPE'] == ProductTable::TYPE_SKU) {
				$res_offers = CCatalogSKU::getOffersList($dbProduct['ID']);
				if (!empty($res_offers)) {
					foreach (reset($res_offers) as $offer) {
						self::deleteIblockElement(intval($offer['ID']));
					}
				}
				self::deleteIblockElement($dbProduct['ID']);
			}
			else {
				self::deleteIblockElement($dbProduct['ID']);
			}
		}
	}

	/**
	 * Delete products unselected categories
	 */
	public static function deleteProductsUnselectedCat()
	{
		$data = self::getAllOaProducts();
		if (!empty($data)) {
			$resProducts = API::getProductsOasisOnlyFieldCategories(array_column($data, 'UF_OASIS_PRODUCT_ID'));

			foreach ($resProducts as $resProduct) {
				if (empty(array_intersect($resProduct->categories, self::$catSelected))) {
					self::checkDeleteProduct($resProduct->id);
				}
			}
		}
	}	

	/**
	 * Delete iblock element
	 * @param $iblockElementId
	 */
	private static function deleteIblockElement($iblockElementId): void
	{
		try {
			self::deleteImages($iblockElementId);
			if (!CIBlockElement::Delete($iblockElementId)) {
				throw new SystemException('Iblock element not deleted. ID-' . $iblockElementId);
			}
			self::$cf->log('Delete iblock element id: ' . $iblockElementId);
		} catch (SystemException $e) {
			echo $e->getMessage() . PHP_EOL;
		}
	}

	/**
	 * Update status iblock element type sku
	 * @param $productId
	 * @param $dbProduct
	 * @param $products
	 */
	public static function upStatusSku($productId, $dbProduct, $products)
	{
		$active = 'N';
		foreach ($products as $product) {
			if (($active = self::getStatusProduct($product)) === 'Y') {
				break;
			}
		}

		if (empty($dbProduct) || $dbProduct['ACTIVE'] !== $active) {
			$el = new CIBlockElement;
			$el->Update($productId, ['ACTIVE' => $active]);

			if (!empty($el->LAST_ERROR)) {
				throw new SystemException($el->LAST_ERROR);
			}
		}
	}

	/**
	 * Execute ProductTable
	 * @param $productId
	 * @param $product
	 * @param $groupId
	 * @param int $type
	 */
	public static function executeProduct($productId, $product, $groupId, int $type)
	{
		$data = [
			'UF_OASIS_UPDATE_AT'  => $product->updated_at ?? '',
		];
		if ($type == ProductTable::TYPE_SKU) {
			$data['QUANTITY'] = 0;
			$data['QUANTITY_TRACE'] = 'N';
			$data['TYPE'] = ProductTable::TYPE_SKU;
		}
		if ($type == ProductTable::TYPE_SKU || $product->rating === 5) {
			$data['CAN_BUY_ZERO'] = 'Y';
			$data['NEGATIVE_AMOUNT_TRACE'] = 'Y';
		}

		$dbProduct = ProductTable::getList([
			'select' => ['ID'],
			'filter' => ['ID' => $productId]
		])->fetch();

		if ($dbProduct) {
			ProductTable::update($dbProduct['ID'], $data);
		} else {
			$data += [
				'ID'                  => $productId,
				'QUANTITY'            => empty($product->total_stock) ? 0 : $product->total_stock,
				'RECUR_SCHEME_LENGTH' => null,
				'SELECT_BEST_PRICE'   => 'N',
				'PURCHASING_CURRENCY' => 'RUB',
				'LENGTH'              => null,
				'WIDTH'               => null,
				'HEIGHT'              => null,
				'MEASURE'             => 5,
				'AVAILABLE'           => 'Y',
				'BUNDLE'              => 'N',
				'UF_OASIS_GROUP_ID'   => $groupId,
				'UF_OASIS_PRODUCT_ID' => $product->id,
				'TYPE'                => $type,
			];
			ProductTable::add($data);
		}
	}

	/**
	 * Checking stores and adding in the absence
	 */
	public static function checkStores()
	{
		$arStores = StoreTable::getList()->fetchAll();

		$arFieldsStores = [
			[
				'TITLE'   => 'Москва',
				'ACTIVE'  => 'Y',
				'ADDRESS' => 'Москва',
				'XML_ID'  => 'OASIS_STOCK_MOSCOW',
				'SORT'    => 500,
				'CODE'    => 'OASIS_STOCK_MOSCOW'
			],
			[
				'TITLE'   => 'Удаленый склад',
				'ACTIVE'  => 'Y',
				'ADDRESS' => 'Удаленый склад',
				'XML_ID'  => 'OASIS_STOCK_REMOTE',
				'SORT'    => 510,
				'CODE'    => 'OASIS_STOCK_REMOTE'
			],
			[
				'TITLE'   => 'Европа',
				'ACTIVE'  => 'Y',
				'ADDRESS' => 'Европа',
				'XML_ID'  => 'OASIS_STOCK_EUROPE',
				'SORT'    => 520,
				'CODE'    => 'OASIS_STOCK_EUROPE'
			]
		];

		foreach ($arFieldsStores as $arFieldsStore) {
			$neededStock = array_filter($arStores, function ($e) use ($arFieldsStore) {
				return $e['CODE'] == $arFieldsStore['CODE'];
			});
			if (empty($neededStock)) {
				StoreTable::add($arFieldsStore);
			}
		}
	}

	/**
	 * Execute StoreProductTable
	 * @param $productId
	 * @param $data
	 * @param bool $upStock
	 * @throws \Exception
	 */
	public static function executeStoreProduct($productId, $data, bool $upStock = false)
	{
		$stocks = [
			'main'   => 0,
			'remote' => 0,
			'europe' => 0,
		];

		if ($upStock) {
			$stocks['main'] = (int)$data->stock;

			if ($data->{'is-europe'}) {
				$stocks['europe'] = (int)$data->{'stock-remote'};
			} else {
				$stocks['remote'] = (int)$data->{'stock-remote'};
			}
		} else {
			$stocks['main'] = (int)$data->total_stock;
		}

		try {
			$stores = [
				'main' => [
					'ID'     => (int)Option::get(OasisConfig::MODULE_ID, 'main_stock'),
					'AMOUNT' => $stocks['main'],
				]
			];

			$multiStocks = Option::get(OasisConfig::MODULE_ID, 'stocks');

			if ($upStock && !empty($multiStocks)) {
				$stores['remote'] = [
					'ID'     => (int)Option::get(OasisConfig::MODULE_ID, 'remote_stock'),
					'AMOUNT' => $stocks['remote'],
				];
				$stores['europe'] = [
					'ID'     => (int)Option::get(OasisConfig::MODULE_ID, 'europe_stock'),
					'AMOUNT' => $stocks['europe'],
				];

				if (empty($stores['main']['ID']) || empty($stores['remote']['ID']) || empty($stores['europe']['ID'])) {
					throw new SystemException('Stock not updated. No main stock ID or no remote stock ID or no europe stock ID. Select stocks in module settings.');
				}

				foreach ($stores as $store) {
					$arField = [
						'PRODUCT_ID' => (int)$productId,
						'STORE_ID'   => $store['ID'],
						'AMOUNT'     => $store['AMOUNT'],
					];

					$rsStoreProduct = StoreProductTable::getList([
						'filter' => [
							'=PRODUCT_ID' => (int)$productId,
							'STORE.ID'    => $store['ID'],
						],
					])->fetch();

					if (!empty($rsStoreProduct)) {
						StoreProductTable::update($rsStoreProduct['ID'], $arField);
					} else {
						StoreProductTable::add($arField);
					}
				}
			} else {
				$rsStoreProduct = StoreProductTable::getList([
					'filter' => [
						'=PRODUCT_ID' => $productId,
						'STORE.ID'    => $stores['main']['ID']
					],
				])->fetch();

				$arField = [
					'PRODUCT_ID' => (int)$productId,
					'STORE_ID'   => $stores['main']['ID'],
					'AMOUNT'     => array_sum($stocks),
				];

				if (!empty($rsStoreProduct)) {
					StoreProductTable::update($rsStoreProduct['ID'], $arField);
				} else {
					StoreProductTable::add($arField);
				}

				$arDeleteStoreProducts = StoreProductTable::getList([
					'filter' => [
						'=PRODUCT_ID' => $productId,
						'!=STORE.ID'  => $stores['main']['ID']
					],
				])->fetchAll();

				foreach ($arDeleteStoreProducts as $arDeleteStoreProduct) {
					StoreProductTable::delete($arDeleteStoreProduct['ID']);
				}
			}
		} catch (SystemException $e) {
			echo $e->getMessage() . PHP_EOL;
			exit();
		}
	}

	/**
	 * Execute PriceTable
	 * @param $productId
	 * @param $product
	 */
	public static function executePriceProduct($productId, $product)
	{
		if (empty(self::$catalog_group_id)) {
			$rsGroup = GroupTable::getList([
				'filter' => ['BASE' => 'Y']
			]);

			if ($arGroup = $rsGroup->fetch()) {
				self::$catalog_group_id = (int)$arGroup['ID'];
			} else {
				self::$catalog_group_id = 1;
			}
		}

		$price = self::$cf->dealer ? $product->discount_price : $product->price;
		if (!empty(self::$cf->factor)) {
			$price = $price * (float)self::$cf->factor;
		}
		if (!empty(self::$cf->increase)) {
			$price = $price + (float)self::$cf->increase;
		}

		$arField = [
			'CATALOG_GROUP_ID' => self::$catalog_group_id,
			'PRODUCT_ID'       => $productId,
			'PRICE'            => $price,
			'PRICE_SCALE'      => $price,
			'CURRENCY'         => 'RUB',
		];

		$dbPrice = PriceTable::getList([
			'filter' => ['PRODUCT_ID' => $productId]
		])->fetch();

		if (empty($dbPrice)) {
			PriceTable::add($arField);
		} else {
			PriceTable::update($dbPrice['ID'], $arField);
		}
	}

	/**
	 * Execute MeasureRatio
	 * @param $productId
	 * @throws Exception
	 */
	public static function executeMeasureRatioTable($productId)
	{
		$result = MeasureRatioTable::add([
			'PRODUCT_ID' => $productId,
			'IS_DEFAULT' => 'Y',
			'RATIO'      => 1,
		]);

		if (!$result->isSuccess()) {
			throw new SystemException(sprintf('ErrorMessages: ' . print_r($result->getErrorMessages(), true) . 'Error add MeasureRatio PRODUCT_ID="%s".', $productId));
		}
	}

	/**
	 * Get images product
	 * @param $product
	 * @return array
	 */
	public static function getProductImages($product): array
	{
		$result = [];
		$i = 0;
		$n = 0;

		foreach ($product->images as $image) {
			$value = CFile::MakeFileArray($image->superbig);

			if ($value['type'] !== 'text/html') {
				if ($i == 0) {
					$result['DETAIL_PICTURE'] = $result['PREVIEW_PICTURE'] = $value;
				}

				if (self::$cf->move_first_img_to_detail === false || $i != 0) {
					$result['MORE_PHOTO']['n' . $n++] = [
						'VALUE' => $value,
					];
				}

				$i++;
			}
		}

		return $result;
	}

	/**
	 * Checking product images for relevance
	 * Usage:
	 * Check is good - true
	 * Check is bad - false
	 * @param $productId
	 * @param $product
	 * @return bool
	 */
	public static function checkDataImages($productId, $product): bool
	{
		$res = CIBlockElement::GetList([], ['ID' => $productId]);

		while ($ob = $res->GetNextElement()) {
			$props = $ob->GetProperties();
			$dbImageIDS = [];

			if (self::$cf->move_first_img_to_detail && !empty($ob->fields['DETAIL_PICTURE'])) {
				$dbImageIDS[] = $ob->fields['DETAIL_PICTURE'];
			}

			if (!empty($props['MORE_PHOTO']['VALUE'])) {
				$dbImageIDS = array_merge($dbImageIDS, $props['MORE_PHOTO']['VALUE']);
			}

			if (count($product->images) !== count($dbImageIDS)) {
				return false;
			}

			$dbResFiles = CFile::GetList([], ['@ID' => implode(',', $dbImageIDS)]);
			$dbFiles = [];

			while ($dbResFile = $dbResFiles->Fetch()) {
				$dbFiles[] = $dbResFile;
			}
			foreach ($product->images as $image) {
				if (empty($image->superbig)) {
					return false;
				}
				$keyNeeded = array_search(basename($image->superbig), array_column($dbFiles, 'ORIGINAL_NAME'));

				if ($keyNeeded === false || $image->updated_at > strtotime($dbFiles[$keyNeeded]['TIMESTAMP_X'])) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Delete images
	 * @param $iblockElementId
	 * @return void
	 */
	private static function deleteImages($iblockElementId): void
	{
		$res = CIBlockElement::GetList([], [
			'ID' => $iblockElementId
		]);

		while ($ob = $res->GetNextElement()) {
			if (!empty($ob->fields['DETAIL_PICTURE'])) {
				CFile::Delete($ob->fields['DETAIL_PICTURE']);
			}
			if (!empty($ob->fields['PREVIEW_PICTURE'])) {
				CFile::Delete($ob->fields['PREVIEW_PICTURE']);
			}
			$props = $ob->GetProperties();
			if (!empty($props['MORE_PHOTO']['VALUE'])) {
				foreach ($props['MORE_PHOTO']['VALUE'] as $oldImg) {
					CFile::Delete($oldImg);
				}
			}
		}
	}

	/**
	 * @param $imgUrl
	 * @param $product
	 * @return int
	 */
	public static function getIDImageForHLColor($imgUrl, $product): int
	{
		if (empty($imgUrl)) {
			return 0;
		}

		$dataImage = pathinfo(basename($imgUrl));
		if (!empty($product->parent_volume_id)) {
			$HLNameImg = $product->parent_volume_id . '_hl.' . $dataImage['extension'];
		}
		elseif (!empty($product->color_group_id)) {
			$HLNameImg = $product->color_group_id . '_hl.' . $dataImage['extension'];
		}
		else {
			$HLNameImg = $product->id . '_hl.' . $dataImage['extension'];
		}

		$row = CFile::GetList([], ['ORIGINAL_NAME' => $HLNameImg])->Fetch();
		if (empty($row)) {
			$dataMake = self::getDataMakeFileArray($imgUrl, $HLNameImg);
			return empty($dataMake) ? 0 : CFile::SaveFile($dataMake, 'iblock');
		} else {
			return intval($row['ID']);
		}
	}

	/**
	 * Fix upload file in MakeFileArray
	 *
	 * @param $imgUrl
	 * @param $HLNameImg
	 * @param int $i
	 * @return bool|array|null
	 */
	static public function getDataMakeFileArray($imgUrl, $HLNameImg, int $i = 0): bool|array|null
	{
		$result = CFile::MakeFileArray($imgUrl);

		if (!empty($result['type']) && $result['type'] === 'unknown') {
			$i++;
			if ($i < 6) {
				$result = self::getDataMakeFileArray($imgUrl, $HLNameImg, $i);
			} else {
				return [];
			}
		}

		CFile::ResizeImage(
			$result,
			[
				'width'  => 70,
				'height' => 70
			],
			BX_RESIZE_IMAGE_PROPORTIONAL_ALT,
		);

		$result['name'] = $HLNameImg;
		$result['MODULE_ID'] = OasisConfig::MODULE_ID;

		return $result;
	}

	/**
	 * Get status product
	 *
	 * @param $product
	 * @return string
	 */
	public static function getStatusProduct($product): string
	{
		if ($product->total_stock > 0 || $product->rating === 5) {
			$status = 'Y';
		} else {
			$status = 'N';
		}

		return $status;
	}

	/**
	 * Get iblock section product
	 * @param $product
	 * @return array
	 */
	public static function getIblockSectionProduct($product): array
	{
		$categories = [];
		foreach ($product->categories as $categoryId) {
			if (in_array($categoryId, self::$catSelected)) {
				$categories[] = self::getCategoryId($categoryId);
			}
		}

		if (count($categories) == 0) {
			self::$cf->log('Раздел товара не определен');
			throw new Exception('Раздел товара не определен');
		}
		elseif (count($categories) > 1) {
			$result['IBLOCK_SECTION'] = $categories;
		} else {
			$result['IBLOCK_SECTION_ID'] = reset($categories);
		}

		return $result;
	}

	/**
	 * Checking properties product and create if absent
	 * @throws LoaderException
	 * @throws Exception
	 */
	public static function checkProperties()
	{
		if (empty(self::$cf->iblock_catalog) || empty(self::$cf->iblock_offers)) {
			throw new Exception('Infoblocks not selected');
		}
		$arProperties = [
			self::$cf->iblock_catalog => [
				[
					'CODE'             => 'MORE_PHOTO',
					'NAME'             => 'Картинки',
					'PROPERTY_TYPE'    => 'F',
					'COL_COUNT'        => 30,
					'MULTIPLE'         => 'Y',
					'FILE_TYPE'        => 'jpg, gif, bmp, png, jpeg',
					'WITH_DESCRIPTION' => 'Y',
					'SORT'             => 500,
					'extend'           => [
						'section' => [
							'smartFilter'     => 'N',
							'displayType'     => '',
							'displayExpanded' => 'N',
						],
					],
				],
				[
					'CODE'          => 'ARTNUMBER',
					'NAME'          => 'Артикул',
					'PROPERTY_TYPE' => 'S',
					'COL_COUNT'     => 30,
					'SEARCHABLE'    => 'N',
					'FILTRABLE'     => 'N',
					'SORT'          => 310,
					'extend'        => [
						'feature' => [
							'LIST_PAGE_SHOW'   => 'Y',
							'DETAIL_PAGE_SHOW' => 'Y',
						],
						'section' => [
							'smartFilter'     => 'N',
							'displayType'     => 'F',
							'displayExpanded' => 'N',
						],
					],
				],
				[
					'CODE'          => 'MANUFACTURER',
					'NAME'          => 'Производитель',
					'PROPERTY_TYPE' => 'S',
					'SORT'          => 320,
					'extend'        => [
						'feature' => [
							'LIST_PAGE_SHOW'   => 'Y',
							'DETAIL_PAGE_SHOW' => 'Y',
						],
						'section' => [
							'smartFilter'     => 'Y',
							'displayType'     => 'F',
							'displayExpanded' => 'N',
						],
					],
				],
				[
					'CODE'          => 'MATERIAL',
					'NAME'          => 'Материал',
					'PROPERTY_TYPE' => 'S',
					'MULTIPLE'      => 'Y',
					'MULTIPLE_CNT'  => 1,
					'SORT'          => 330,
					'extend'        => [
						'feature' => [
							'LIST_PAGE_SHOW'   => 'Y',
							'DETAIL_PAGE_SHOW' => 'Y',
						],
						'section' => [
							'smartFilter'     => 'Y',
							'displayType'     => 'F',
							'displayExpanded' => 'N',
						],
					],
				],
				[
					'CODE'          => 'COLOR',
					'NAME'          => 'Цвет',
					'PROPERTY_TYPE' => 'S',
					'MULTIPLE'      => 'Y',
					'MULTIPLE_CNT'  => 3,
					'SORT'          => 340,
					'extend'        => [
						'section' => [
							'smartFilter'     => 'Y',
							'displayType'     => 'F',
							'displayExpanded' => 'N',
						],
					],
				],
				[
					'CODE'                    => 'COLOR_OA_REF',
					'NAME'                    => 'Цвет',
					'PROPERTY_TYPE'           => 'S',
					'COL_COUNT'               => 30,
					'MULTIPLE'                => 'Y',
					'FILTRABLE'               => 'Y',
					'SORT'                    => 341,
					'USER_TYPE'               => 'directory',
					'USER_TYPE_SETTINGS_LIST' => [
						'size'       => 1,
						'width'      => 0,
						'group'      => 'N',
						'multiple'   => 'Y',
						'TABLE_NAME' => 'oasis_color_reference'
					],
					'extend'                  => [
						'feature' => [
							'IN_BASKET'        => 'N',
							'OFFER_TREE'       => 'N',
							'LIST_PAGE_SHOW'   => 'N',
							'DETAIL_PAGE_SHOW' => 'N',
						],
						'section' => [
							'smartFilter'     => 'Y',
							'displayType'     => 'G',
							'displayExpanded' => 'Y',
						],
					],
				],
				[
					'CODE'          => 'GENDER',
					'NAME'          => 'Гендер',
					'PROPERTY_TYPE' => 'S',
					'MULTIPLE'      => 'Y',
					'MULTIPLE_CNT'  => 1,
					'SORT'          => 510,
					'extend'        => [
						'feature' => [
							'LIST_PAGE_SHOW'   => 'Y',
							'DETAIL_PAGE_SHOW' => 'Y',
						],
						'section' => [
							'smartFilter'     => 'Y',
							'displayType'     => 'F',
							'displayExpanded' => 'N',
						],
					],
				],
				[
					'CODE'          => 'BRANDING',
					'NAME'          => 'Метод нанесения',
					'PROPERTY_TYPE' => 'S',
					'MULTIPLE'      => 'Y',
					'MULTIPLE_CNT'  => 1,
					'SORT'          => 520,
					'extend'        => [
						'feature' => [
							'LIST_PAGE_SHOW'   => 'Y',
							'DETAIL_PAGE_SHOW' => 'Y',
						],
						'section' => [
							'smartFilter'     => 'Y',
							'displayType'     => 'F',
							'displayExpanded' => 'N',
						],
					],
				],
				[
					'CODE'          => 'MECHANISM',
					'NAME'          => 'Вид механизма',
					'PROPERTY_TYPE' => 'S',
					'MULTIPLE'      => 'Y',
					'MULTIPLE_CNT'  => 1,
					'SORT'          => 530,
					'extend'        => [
						'feature' => [
							'LIST_PAGE_SHOW'   => 'Y',
							'DETAIL_PAGE_SHOW' => 'Y',
						],
						'section' => [
							'smartFilter'     => 'Y',
							'displayType'     => 'F',
							'displayExpanded' => 'N',
						],
					],
				],
				[
					'CODE'          => 'INK_COLOR',
					'NAME'          => 'Цвет чернил',
					'PROPERTY_TYPE' => 'S',
					'MULTIPLE'      => 'Y',
					'MULTIPLE_CNT'  => 1,
					'SORT'          => 540,
					'extend'        => [
						'feature' => [
							'LIST_PAGE_SHOW'   => 'Y',
							'DETAIL_PAGE_SHOW' => 'Y',
						],
						'section' => [
							'smartFilter'     => 'Y',
							'displayType'     => 'F',
							'displayExpanded' => 'N',
						],
					],
				],
				[
					'CODE'          => 'ROD_TYPE',
					'NAME'          => 'Тип стержня',
					'PROPERTY_TYPE' => 'S',
					'MULTIPLE'      => 'Y',
					'MULTIPLE_CNT'  => 1,
					'SORT'          => 550,
					'extend'        => [
						'feature' => [
							'LIST_PAGE_SHOW'   => 'Y',
							'DETAIL_PAGE_SHOW' => 'Y',
						],
						'section' => [
							'smartFilter'     => 'Y',
							'displayType'     => 'F',
							'displayExpanded' => 'N',
						],
					],
				],
			],
			self::$cf->iblock_offers  => [
				[
					'CODE'             => 'MORE_PHOTO',
					'NAME'             => 'Картинки',
					'PROPERTY_TYPE'    => 'F',
					'COL_COUNT'        => 60,
					'MULTIPLE'         => 'Y',
					'FILE_TYPE'        => 'jpg, gif, bmp, png, jpeg',
					'WITH_DESCRIPTION' => 'N',
					'SORT'             => 500,
					'extend'           => [
						'section' => [
							'smartFilter'     => 'N',
							'displayType'     => '',
							'displayExpanded' => 'N',
						],
					],
				],
				[
					'CODE'          => 'ARTNUMBER',
					'NAME'          => 'Артикул',
					'PROPERTY_TYPE' => 'S',
					'COL_COUNT'     => 60,
					'SEARCHABLE'    => 'Y',
					'FILTRABLE'     => 'Y',
					'SORT'          => 1010,
					'extend'        => [
						'feature' => [
							'IN_BASKET'        => 'Y',
							'LIST_PAGE_SHOW'   => 'Y',
							'DETAIL_PAGE_SHOW' => 'Y',
						],
						'section' => [
							'smartFilter'     => 'N',
							'displayType'     => 'P',
							'displayExpanded' => 'N',
						],
					],
				],
				[
					'CODE'          => 'COLOR_CLOTHES',
					'NAME'          => 'Цвет',
					'PROPERTY_TYPE' => 'L',
					'SORT'          => 1020,
					'extend'        => [
						'feature' => [
							'IN_BASKET'        => 'Y',
							'OFFER_TREE'       => 'Y',
							'LIST_PAGE_SHOW'   => 'N',
							'DETAIL_PAGE_SHOW' => 'N',
						],
					],
				],
				[
					'CODE'                    => 'COLOR_ES_REF',
					'NAME'                    => 'Цвет',
					'PROPERTY_TYPE'           => 'S',
					'COL_COUNT'               => 30,
					'MULTIPLE'                => 'N',
					'FILTRABLE'               => 'N',
					'SORT'                    => 1030,
					'USER_TYPE'               => 'directory',
					'USER_TYPE_SETTINGS_LIST' => [
						'size'       => 1,
						'width'      => 0,
						'group'      => 'N',
						'multiple'   => 'N',
						'TABLE_NAME' => 'ex_color_reference'
					],
					'extend'                  => [
						'feature' => [
							'IN_BASKET'        => 'Y',
							'OFFER_TREE'       => 'Y',
							'LIST_PAGE_SHOW'   => 'N',
							'DETAIL_PAGE_SHOW' => 'N',
						],
						'section' => [
							'smartFilter'     => 'N',
							'displayType'     => 'G',
							'displayExpanded' => 'Y',
						],
					],
				],
				[
					'CODE'          => 'SIZES_CLOTHES',
					'NAME'          => 'Размеры одежды',
					'PROPERTY_TYPE' => 'L',
					'SORT'          => 1040,
					'extend'        => [
						'feature' => [
							'IN_BASKET'        => 'Y',
							'OFFER_TREE'       => 'Y',
							'LIST_PAGE_SHOW'   => 'N',
							'DETAIL_PAGE_SHOW' => 'N',
						],
					],
				],
				[
					'CODE'          => 'SIZES_FLASH',
					'NAME'          => 'Объем памяти',
					'PROPERTY_TYPE' => 'L',
					'SORT'          => 1050,
					'extend'        => [
						'feature' => [
							'IN_BASKET'        => 'Y',
							'OFFER_TREE'       => 'Y',
							'LIST_PAGE_SHOW'   => 'N',
							'DETAIL_PAGE_SHOW' => 'N',
						],
						'section' => [
							'smartFilter'     => 'Y',
							'displayType'     => 'F',
							'displayExpanded' => 'Y',
						],
					],
				],
			],
		];

		self::checkHLblock();

		try {
			Loader::includeModule('iblock');

			foreach ($arProperties as $iblockId => $properties) {

				foreach ($properties as $property) {
					$dbProperty = PropertyTable::getList([
						'filter' => [
							'IBLOCK_ID' => $iblockId,
							'CODE'      => $property['CODE']
						],
					])->fetch();

					if (!$dbProperty) {
						if (!empty($property['extend'])) {
							$extend = $property['extend'];
							unset($property['extend']);
						}

						$arFields = array_merge([
							'IBLOCK_ID' => $iblockId,
							'XML_ID'    => $property['CODE'],
						], $property);

						$propertyId = self::addProperty($arFields);

						if (!empty($extend)) {
							if (!empty($extend['feature'])) {
								self::propertySetFeatures($propertyId, $extend['feature']);
							}

							if (!empty($extend['section'])) {
								self::addSectionProperty($iblockId, $propertyId, $extend['section']);
							}
						}
					}
				}
			}

		} catch (SystemException $e) {
			echo $e->getMessage() . PHP_EOL;
		}
	}

	/**
	 * Add property product
	 *
	 * @param $data
	 * @return array|int
	 * @throws \Exception
	 */
	private static function addProperty($data)
	{
		$result = [];

		$arFields = array_merge([
			'SORT'             => 1000,
			'WITH_DESCRIPTION' => 'N',
			'IS_REQUIRED'      => 'N',
		], $data);

		try {
			$result = PropertyTable::add($arFields)->getId();
		} catch (SystemException $e) {
			echo $e->getMessage() . PHP_EOL;
		}

		return $result;
	}

	/**
	 * Check highloadblock OasisColorReference
	 *
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\LoaderException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 * @throws \Exception
	 */
	private static function checkHLblock()
	{
		Loader::includeModule('highloadblock');
		$imgPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR;

		$sort = 0;
		$colorValues = [];

		$colors = [
			'AZURE'      => [
				'XML_ID'    => 1480,
				'PATH'      => 'colors/azure.jpg',
				'FILE_NAME' => 'azure.jpg',
				'FILE_TYPE' => 'image/jpeg',
				'TITLE'     => '',
				'OASIS_ID'  => 1480
			],
			'BEIGE'      => [
				'XML_ID'    => 1483,
				'PATH'      => 'colors/beige.jpg',
				'FILE_NAME' => 'beige.jpg',
				'FILE_TYPE' => 'image/jpeg',
				'TITLE'     => '',
				'OASIS_ID'  => 1483
			],
			'BLACK'      => [
				'XML_ID'    => 1471,
				'PATH'      => 'colors/black.jpg',
				'FILE_NAME' => 'black.jpg',
				'FILE_TYPE' => 'image/jpeg',
				'TITLE'     => '',
				'OASIS_ID'  => 1471
			],
			'BLUE'       => [
				'XML_ID'    => 1472,
				'PATH'      => 'colors/blue.jpg',
				'FILE_NAME' => 'blue.jpg',
				'FILE_TYPE' => 'image/jpeg',
				'TITLE'     => '',
				'OASIS_ID'  => 1472
			],
			'BROWN'      => [
				'XML_ID'    => 1482,
				'PATH'      => 'colors/brown.jpg',
				'FILE_NAME' => 'brown.jpg',
				'FILE_TYPE' => 'image/jpeg',
				'TITLE'     => '',
				'OASIS_ID'  => 1482
			],
			'BURGUNDY'   => [
				'XML_ID'    => 1478,
				'PATH'      => 'colors/burgundy.jpg',
				'FILE_NAME' => 'burgundy.jpg',
				'FILE_TYPE' => 'image/jpeg',
				'TITLE'     => '',
				'OASIS_ID'  => 1478
			],
			'DARKBLUE'   => [
				'XML_ID'    => 1488,
				'PATH'      => 'colors/darkblue.jpg',
				'FILE_NAME' => 'darkblue.jpg',
				'FILE_TYPE' => 'image/jpeg',
				'TITLE'     => '',
				'OASIS_ID'  => 1488
			],
			'GOLDEN'     => [
				'XML_ID'    => 1484,
				'PATH'      => 'colors/golden.jpg',
				'FILE_NAME' => 'golden.jpg',
				'FILE_TYPE' => 'image/jpeg',
				'TITLE'     => '',
				'OASIS_ID'  => 1484
			],
			'GREEN'      => [
				'XML_ID'    => 1474,
				'PATH'      => 'colors/green.jpg',
				'FILE_NAME' => 'green.jpg',
				'FILE_TYPE' => 'image/jpeg',
				'TITLE'     => '',
				'OASIS_ID'  => 1474
			],
			'GREENAPPLE' => [
				'XML_ID'    => 1475,
				'PATH'      => 'colors/greenapple.jpg',
				'FILE_NAME' => 'greenapple.jpg',
				'FILE_TYPE' => 'image/jpeg',
				'TITLE'     => '',
				'OASIS_ID'  => 1475
			],
			'GREY'       => [
				'XML_ID'    => 1481,
				'PATH'      => 'colors/grey.jpg',
				'FILE_NAME' => 'grey.jpg',
				'FILE_TYPE' => 'image/jpeg',
				'TITLE'     => '',
				'OASIS_ID'  => 1481
			],
			'MULTICOLOR' => [
				'XML_ID'    => 1486,
				'PATH'      => 'colors/multicolor.jpg',
				'FILE_NAME' => 'multicolor.jpg',
				'FILE_TYPE' => 'image/jpeg',
				'TITLE'     => '',
				'OASIS_ID'  => 1486
			],
			'ORANGE'     => [
				'XML_ID'    => 1476,
				'PATH'      => 'colors/orange.jpg',
				'FILE_NAME' => 'orange.jpg',
				'FILE_TYPE' => 'image/jpeg',
				'TITLE'     => '',
				'OASIS_ID'  => 1476
			],
			'PINK'       => [
				'XML_ID'    => 1487,
				'PATH'      => 'colors/pink.jpg',
				'FILE_NAME' => 'pink.jpg',
				'FILE_TYPE' => 'image/jpeg',
				'TITLE'     => '',
				'OASIS_ID'  => 1487
			],
			'PURPLE'     => [
				'XML_ID'    => 1479,
				'PATH'      => 'colors/purple.jpg',
				'FILE_NAME' => 'purple.jpg',
				'FILE_TYPE' => 'image/jpeg',
				'TITLE'     => '',
				'OASIS_ID'  => 1479
			],
			'RED'        => [
				'XML_ID'    => 1473,
				'PATH'      => 'colors/red.jpg',
				'FILE_NAME' => 'orangered.jpg',
				'FILE_TYPE' => 'image/jpeg',
				'TITLE'     => '',
				'OASIS_ID'  => 1473
			],
			'SILVER'     => [
				'XML_ID'    => 1485,
				'PATH'      => 'colors/silver.jpg',
				'FILE_NAME' => 'silver.jpg',
				'FILE_TYPE' => 'image/jpeg',
				'TITLE'     => '',
				'OASIS_ID'  => 1485
			],
			'WHITE'      => [
				'XML_ID'    => 1470,
				'PATH'      => 'colors/white.jpg',
				'FILE_NAME' => 'white.jpg',
				'FILE_TYPE' => 'image/jpeg',
				'TITLE'     => '',
				'OASIS_ID'  => 1470
			],
			'YELLOW'     => [
				'XML_ID'    => 1477,
				'PATH'      => 'colors/yellow.png',
				'FILE_NAME' => 'yellow.png',
				'FILE_TYPE' => 'image/png',
				'TITLE'     => '',
				'OASIS_ID'  => 1477
			]
		];

		foreach (array_keys($colors) as $index) {
			$colors[$index]['TITLE'] = Loc::getMessage('FILTER_COLOR_' . $index);
		}
		unset($index);

		foreach ($colors as $row) {
			$sort += 100;
			$colorValues[] = [
				'UF_NAME'   => $row['TITLE'],
				'UF_FILE'   => [
					'name'     => $row['FILE_NAME'],
					'type'     => $row['FILE_TYPE'],
					'tmp_name' => $imgPath . $row['PATH']
				],
				'UF_SORT'   => $sort,
				'UF_DEF'    => '0',
				'UF_XML_ID' => $row['XML_ID']
			];
		}
		unset($row);

		$tables = [
			'oasis_color_reference' => [
				'name'   => 'OasisColorReference',
				'fields' => [
					[
						'FIELD_NAME'        => 'UF_NAME',
						'USER_TYPE_ID'      => 'string',
						'XML_ID'            => 'UF_COLOR_NAME',
						'IS_SEARCHABLE'     => 'Y',
						'EDIT_FORM_LABEL'   => [
							'ru' => 'Название',
							'en' => 'Name',
						],
						'LIST_COLUMN_LABEL' => [
							'ru' => 'Название',
							'en' => 'Name',
						],
						'LIST_FILTER_LABEL' => [
							'ru' => 'Название',
							'en' => 'Name',
						]
					],
					[
						'FIELD_NAME'        => 'UF_FILE',
						'USER_TYPE_ID'      => 'file',
						'XML_ID'            => 'UF_COLOR_FILE',
						'IS_SEARCHABLE'     => 'Y',
						'EDIT_FORM_LABEL'   => [
							'ru' => 'Файл',
							'en' => 'File',
						],
						'LIST_COLUMN_LABEL' => [
							'ru' => 'Файл',
							'en' => 'File',
						],
						'LIST_FILTER_LABEL' => [
							'ru' => 'Файл',
							'en' => 'File',
						]
					],
					[
						'FIELD_NAME'        => 'UF_SORT',
						'USER_TYPE_ID'      => 'double',
						'XML_ID'            => 'UF_COLOR_SORT',
						'EDIT_FORM_LABEL'   => [
							'ru' => 'Сортировка',
							'en' => 'Sort',
						],
						'LIST_COLUMN_LABEL' => [
							'ru' => 'Сортировка',
							'en' => 'Sort',
						],
						'LIST_FILTER_LABEL' => [
							'ru' => 'Сортировка',
							'en' => 'Sort',
						]
					],
					[
						'FIELD_NAME'        => 'UF_DEF',
						'USER_TYPE_ID'      => 'boolean',
						'XML_ID'            => 'UF_COLOR_DEF',
						'EDIT_FORM_LABEL'   => [
							'ru' => 'По умолчанию',
							'en' => 'Default',
						],
						'LIST_COLUMN_LABEL' => [
							'ru' => 'По умолчанию',
							'en' => 'Default',
						],
						'LIST_FILTER_LABEL' => [
							'ru' => 'По умолчанию',
							'en' => 'Default',
						]
					],
					[
						'FIELD_NAME'        => 'UF_XML_ID',
						'USER_TYPE_ID'      => 'string',
						'XML_ID'            => 'UF_XML_ID',
						'MANDATORY'         => 'Y',
						'EDIT_FORM_LABEL'   => [
							'ru' => 'XML ID',
							'en' => 'XML ID',
						],
						'LIST_COLUMN_LABEL' => [
							'ru' => 'XML ID',
							'en' => 'XML ID',
						],
						'LIST_FILTER_LABEL' => [
							'ru' => 'XML ID',
							'en' => 'XML ID',
						]
					]
				],
				'values' => $colorValues
			],
			'ex_color_reference'    => [
				'name'   => 'ExColorReference',
				'fields' => [
					[
						'FIELD_NAME'        => 'UF_NAME',
						'USER_TYPE_ID'      => 'string',
						'XML_ID'            => 'UF_COLOR_NAME',
						'IS_SEARCHABLE'     => 'Y',
						'EDIT_FORM_LABEL'   => [
							'ru' => 'Название',
							'en' => 'Name',
						],
						'LIST_COLUMN_LABEL' => [
							'ru' => 'Название',
							'en' => 'Name',
						],
						'LIST_FILTER_LABEL' => [
							'ru' => 'Название',
							'en' => 'Name',
						]
					],
					[
						'FIELD_NAME'        => 'UF_FILE',
						'USER_TYPE_ID'      => 'file',
						'XML_ID'            => 'UF_COLOR_FILE',
						'IS_SEARCHABLE'     => 'Y',
						'EDIT_FORM_LABEL'   => [
							'ru' => 'Файл',
							'en' => 'File',
						],
						'LIST_COLUMN_LABEL' => [
							'ru' => 'Файл',
							'en' => 'File',
						],
						'LIST_FILTER_LABEL' => [
							'ru' => 'Файл',
							'en' => 'File',
						]
					],
					[
						'FIELD_NAME'        => 'UF_LINK',
						'USER_TYPE_ID'      => 'string',
						'XML_ID'            => 'UF_COLOR_LINK',
						'IS_SEARCHABLE'     => 'Y',
						'EDIT_FORM_LABEL'   => [
							'ru' => 'Ссылка',
							'en' => 'Link',
						],
						'LIST_COLUMN_LABEL' => [
							'ru' => 'Ссылка',
							'en' => 'Link',
						],
						'LIST_FILTER_LABEL' => [
							'ru' => 'Ссылка',
							'en' => 'Link',
						]
					],
					[
						'FIELD_NAME'        => 'UF_SORT',
						'USER_TYPE_ID'      => 'double',
						'XML_ID'            => 'UF_COLOR_SORT',
						'EDIT_FORM_LABEL'   => [
							'ru' => 'Сортировка',
							'en' => 'Sort',
						],
						'LIST_COLUMN_LABEL' => [
							'ru' => 'Сортировка',
							'en' => 'Sort',
						],
						'LIST_FILTER_LABEL' => [
							'ru' => 'Сортировка',
							'en' => 'Sort',
						]
					],
					[
						'FIELD_NAME'        => 'UF_DEF',
						'USER_TYPE_ID'      => 'boolean',
						'XML_ID'            => 'UF_COLOR_DEF',
						'EDIT_FORM_LABEL'   => [
							'ru' => 'По умолчанию',
							'en' => 'Default',
						],
						'LIST_COLUMN_LABEL' => [
							'ru' => 'По умолчанию',
							'en' => 'Default',
						],
						'LIST_FILTER_LABEL' => [
							'ru' => 'По умолчанию',
							'en' => 'Default',
						]
					],
					[
						'FIELD_NAME'        => 'UF_XML_ID',
						'USER_TYPE_ID'      => 'string',
						'XML_ID'            => 'UF_XML_ID',
						'MANDATORY'         => 'Y',
						'EDIT_FORM_LABEL'   => [
							'ru' => 'XML ID',
							'en' => 'XML ID',
						],
						'LIST_COLUMN_LABEL' => [
							'ru' => 'XML ID',
							'en' => 'XML ID',
						],
						'LIST_FILTER_LABEL' => [
							'ru' => 'XML ID',
							'en' => 'XML ID',
						]
					],
					[
						'FIELD_NAME'        => 'UF_COLOR_ID',
						'USER_TYPE_ID'      => 'string',
						'XML_ID'            => 'UF_COLOR_ID',
						'MANDATORY'         => 'Y',
						'SHOW_IN_LIST'      => 'N',
						'EDIT_IN_LIST'      => 'N',
						'EDIT_FORM_LABEL'   => [
							'ru' => 'ID цвета',
							'en' => 'ID color',
						],
						'LIST_COLUMN_LABEL' => [
							'ru' => 'ID цвета',
							'en' => 'ID color',
						],
						'LIST_FILTER_LABEL' => [
							'ru' => 'ID цвета',
							'en' => 'ID color',
						]
					]
				],
			]
		];

		foreach ($tables as $tableName => &$table) {
			$tableId = null;

			$res = HighloadBlockTable::getList([
				'select' => ['ID', 'NAME', 'TABLE_NAME'],
				'filter' => [
					'=NAME'       => $table['name'],
					'=TABLE_NAME' => $tableName
				]
			]);

			$row = $res->fetch();
			unset($res);

			if (empty($row)) {
				$result = HighloadBlockTable::add([
					'NAME'       => $table['name'],
					'TABLE_NAME' => $tableName
				]);

				if ($result->isSuccess()) {
					$sort = 100;
					$tableId = $result->getId();
					$userField = new CUserTypeEntity;

					foreach ($table['fields'] as $field) {
						$field['ENTITY_ID'] = 'HLBLOCK_' . $tableId;
						$res = CUserTypeEntity::getList(
							[],
							[
								'ENTITY_ID'  => $field['ENTITY_ID'],
								'FIELD_NAME' => $field['FIELD_NAME']
							]
						);

						if (!$res->Fetch()) {
							$field['SORT'] = $sort;
							$userField->Add($field);
							$sort += 100;
						}
					}
				}
			} else {
				$tableId = (int)$row['ID'];
			}

			if (!empty($tableId)) {
				$hldata = HighloadBlockTable::getById($tableId)->fetch();
				$hlentity = HighloadBlockTable::compileEntity($hldata);
				$entityClass = $hlentity->getDataClass();

				foreach ($table['values'] as $item) {
					$rowColor = $entityClass::getList([
						'select' => ['ID'],
						'filter' => [
							'=UF_XML_ID' => $item['UF_XML_ID']
						],
					])->fetch();

					if (empty($rowColor)) {
						$entityClass::add($item);
					}
				}
			}
		}
	}

	/**
	 * High load block
	 * @param $imageId
	 * @param $name
	 * @throws SystemException
	 */
	public static function getHLColorXmlId($imageId, $name): string
	{
		if (empty($imageId)) {
			return '';
		}

		if (empty(self::$hl_edc_color)) {
			self::$hl_edc_color = self::getHlEntityClass('ExColorReference', 'ex_color_reference');

			if (empty(self::$hl_edc_color)) {
				self::$cf->log('HighloadBlockTable ExColorReference not found');
				throw new SystemException('HighloadBlockTable ExColorReference not found');
			}
		}

		$arrImg = CFile::MakeFileArray($imageId);

		$uf_xml_id = pathinfo($arrImg['name'])['filename'];
		$row = self::$hl_edc_color::getList([
			'select' => ['ID'],
			'filter' => [
				'=UF_XML_ID' => $uf_xml_id
			],
		])->fetch();

		if (empty($row)) {
			self::$hl_edc_color::add([
				'UF_NAME'     => $name,
				'UF_FILE'     => [
					'name'     => $arrImg['name'],
					'type'     => $arrImg['type'],
					'tmp_name' => $arrImg['tmp_name']
				],
				'UF_SORT'     => 0,
				'UF_DEF'      => '0',
				'UF_XML_ID'   => $uf_xml_id,
				'UF_COLOR_ID' => $uf_xml_id
			]);
		}
		return $uf_xml_id;
	}

	/**
	 * Set features class PropertyFeature
	 * @param int $propertyId
	 * @param array $data
	 */
	private static function propertySetFeatures(int $propertyId, array $data)
	{
		$arFields = [];

		foreach ($data as $featureId => $isEnabled) {
			if ($featureId === 'IN_BASKET' || $featureId === 'OFFER_TREE') {
				$moduleId = 'catalog';
			} elseif ($featureId === 'LIST_PAGE_SHOW' || $featureId === 'DETAIL_PAGE_SHOW') {
				$moduleId = 'iblock';
			}

			$arFields[] = [
				'MODULE_ID'  => $moduleId ?? '',
				'FEATURE_ID' => $featureId,
				'IS_ENABLED' => $isEnabled,
			];
		}

		PropertyFeature::setFeatures($propertyId, $arFields);
	}

	/**
	 * Add section property table
	 * @param int $iblockId
	 * @param int $propertyId
	 * @param array $data
	 * @throws \Exception
	 */
	private static function addSectionProperty(int $iblockId, int $propertyId, array $data)
	{
		SectionPropertyTable::add([
			'IBLOCK_ID'        => $iblockId,
			'SECTION_ID'       => 0,
			'PROPERTY_ID'      => $propertyId,
			'SMART_FILTER'     => $data['smartFilter'],
			'DISPLAY_TYPE'     => $data['displayType'],
			'DISPLAY_EXPANDED' => $data['displayExpanded'],
			'FILTER_HINT'      => $data['filterHint'] ?? '',
		]);
	}

	/**
	 * Check property product
	 *
	 * @param $propertyCode
	 * @param $value
	 * @param $iblockId
	 * @return array|int|void
	 */
	public static function checkPropertyEnum($propertyCode, $value, $iblockId)
	{
		Loader::includeModule('iblock');

		$dbProperty = PropertyTable::getList([
			'filter' => [
				'CODE'      => $propertyCode,
				'IBLOCK_ID' => $iblockId
			],
		])->fetch();

		$dbPropertyEnum = PropertyEnumerationTable::getList([
			'filter' => [
				'PROPERTY_ID' => $dbProperty['ID'],
				'VALUE'       => $value,
			],
		])->fetch();

		if (!$dbPropertyEnum) {
			return self::addPropertyEnum($dbProperty['ID'], $value);
		} else {
			return (int)$dbPropertyEnum['ID'];
		}
	}

	/**
	 * Add property product
	 * @param $propertyId
	 * @param $value
	 * @return array|int
	 * @throws Exception
	 */
	public static function addPropertyEnum($propertyId, $value)
	{
		$res = PropertyEnumerationTable::add([
			'PROPERTY_ID' => $propertyId,
			'VALUE'       => $value,
			'XML_ID'      => md5($propertyId . $value),
			'SORT'        => 1000,
		]);

		if (!$res->isSuccess()) {
			throw new SystemException(sprintf('Error add property enumeration value "%s" not list type.', $value));
		} else {
			return $res->getId();
		}
	}

	/**
	 * Get array properties products
	 * @param $product
	 * @return array
	 */
	public static function getPropertiesArray($product): array
	{
		$result = [
			'ARTNUMBER' => $product->article
		];

		foreach ($product->attributes as $attribute) {
			if (isset($attribute->id) && $attribute->id === self::ATTR_MATERIAL_ID) {
				$result['MATERIAL'] = $attribute->value;
			}
		}

		if (!is_null($product->brand)) {
			$result['MANUFACTURER'] = $product->brand;
		}

		if(self::$cf->is_brands && !empty($product->brand_id)){
			$brand = CLI::$brands[$product->brand_id] ?? null;
			if($brand){
				if(!isset($brand['XML_ID'])){
					$entityClass = self::getHlEntityClass('BrandReference', 'eshop_brand_reference');
					if ($entityClass) {
						$hl_item = $entityClass::getList([
							'select' => ['ID'],
							'filter' => [
								'=UF_XML_ID' => $brand['slug']
							],
						])->fetch();

						if (empty($hl_item)) {
							CLI::$handlerCDN_disable = true;
							$logotype = CFile::MakeFileArray($brand['logotype']);
							CLI::$handlerCDN_disable = false;

							if ($logotype['type'] !== 'text/html') {
								$entityClass::add([
									'UF_NAME'   => $brand['name'],
									'UF_FILE'   => [
										'name'     => $logotype['name'],
										'type'     => $logotype['type'],
										'tmp_name' => $logotype['tmp_name']
									],
									'UF_SORT'   => 500,
									'UF_XML_ID' => $brand['slug']
								]);
							}
						}
						CLI::$brands[$product->brand_id]['XML_ID'] = $brand['XML_ID'] = $brand['slug'];
					}
				}
				if(!empty($brand['XML_ID'])){
					$result['BRAND_REF'] = $brand['XML_ID'];
				}
			}
		}

		return $result;
	}

	/**
	 * Highloadblock Entity Class
	 * @param $name
	 * @param $table_name
	 * @return DataManager | null
	 */
	public static function getHlEntityClass(string $name, string $table_name)
	{
		$row = HighloadBlockTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=NAME'       => $name,
				'=TABLE_NAME' => $table_name
			]
		])->fetch();
		if (!empty($row)) {
			$hldata = HighloadBlockTable::getById((int)$row['ID'])->fetch();
			$hlentity = HighloadBlockTable::compileEntity($hldata);
			return $hlentity->getDataClass();
		}
		return null;
	}

	/**
	 * Update product offers properties for filter
	 * @param $productId
	 * @param $firstProduct
	 * @param $products
	 */
	public static function upPropertiesFilterOffers($productId, $firstProduct, $products)
	{
		$properties = [];
		foreach ($products as $product) {
			$properties = self::preparePropertiesFilter($product, $properties);
		}
		self::upPropertiesFilter($productId, $firstProduct, $properties);
	}

	/**
	 * Update product properties for filter
	 * @param $productId
	 * @param $product
	 * @param array $properties
	 */
	public static function upPropertiesFilter($productId, $product, array $properties = [])
	{
		if (empty($properties)) {
			$properties = self::preparePropertiesFilter($product);
		}
		foreach ($properties as $code => $dataProperty) {
			self::checkPropertyValues($productId, $product, $code, $dataProperty);
		}
		Manager::updateElementIndex(self::$cf->iblock_catalog, $productId);
	}

	/**
	 * Prepare properties for filter
	 * @param $product
	 * @param array $properties
	 * @return array
	 */
	public static function preparePropertiesFilter($product, array $properties = []): array
	{
		if (!empty($product->colors)) {
			foreach ($product->colors as $color) {
				if (empty($properties['COLOR_OA_REF']) || array_search($color->parent_id, $properties['COLOR_OA_REF']) === false) {
					$properties['COLOR_OA_REF'][] = $color->parent_id;
				}
			}
		}

		foreach ($product->attributes as $attribute) {
			$attribute->name = str_replace(['\'', '"'], ['', ''], $attribute->name);
			$attribute->value = str_replace(['\'', '"'], ['', ''], $attribute->value);

			if (isset($attribute->id)) {
				switch ($attribute->id) {
					case 65:
						if (empty($properties['GENDER']) || array_search($attribute->value, $properties['GENDER']) === false) {
							$properties['GENDER'][] = $attribute->value;
						}
						break;
					case 1000000008:
						if (empty($properties['BRANDING']) || array_search($attribute->value, $properties['BRANDING']) === false) {
							$properties['BRANDING'][] = $attribute->value;
						}
						break;
					case 8:
						if (empty($properties['MECHANISM']) || array_search($attribute->value, $properties['MECHANISM']) === false) {
							$properties['MECHANISM'][] = $attribute->value;
						}
						break;
					case 6:
						if (empty($properties['INK_COLOR']) || array_search($attribute->value, $properties['INK_COLOR']) === false) {
							$properties['INK_COLOR'][] = $attribute->value;
						}
						break;
					case 105:
						if (empty($properties['ROD_TYPE']) || array_search($attribute->value, $properties['ROD_TYPE']) === false) {
							$properties['ROD_TYPE'][] = $attribute->value;
						}
						break;
				}
			}
		}

		return $properties;
	}

	/**
	 * Check and set property values for filter
	 * @param $productId
	 * @param $product
	 * @param $code
	 * @param array $properties
	 */
	public static function checkPropertyValues($productId, $product, $code, array $properties)
	{
		$result = [];
		CIBlockElement::GetPropertyValuesArray(
			$result,
			self::$cf->iblock_catalog,
			[
				'ID' => [$productId]
			],
			[
				'CODE' => $code
			]
		);
		$arValues = !empty($result[$productId][$code]['VALUE']) ? $result[$productId][$code]['VALUE'] : [];

		$new_properties = null;
		if (self::getStatusProduct($product) === 'Y') {
			$new_properties = empty($arValues) ? $properties : array_unique(array_merge($arValues, $properties));
		}
		elseif (!empty($arValues)) {
			$new_properties = array_values(array_diff($arValues, $properties));
		}
		if (isset($new_properties)) {
			CIBlockElement::SetPropertyValuesEx($productId, self::$cf->iblock_catalog, [$code => $new_properties]);
		}
	}

	/**
	 * Get array properties product offer
	 * @param $productId
	 * @param $product
	 * @param $products
	 * @return array
	 */
	public static function getPropertiesArrayOffer($productId, $product, $products): array
	{
		$result = [
			'CML2_LINK' => $productId,
			'ARTNUMBER' => $product->article,
		];

		if (!empty($product->size)) {
			if (in_array(3070, $product->full_categories)) {
				$result['SIZES_CLOTHES'] = self::checkPropertyEnum('SIZES_CLOTHES', $product->size, self::$cf->iblock_offers);
			}

			if (self::searchObject($product->attributes, self::ATTR_FLASH_ID)) {
				$result['SIZES_FLASH'] = self::checkPropertyEnum('SIZES_FLASH', $product->size, self::$cf->iblock_offers);
			}
		}

		if (!self::$cf->is_fast_import) {
			$result += self::getProductImages($product);
		}

		$parenProduct 	= self::searchObject($products, $product->color_group_id);
		$imgs 			= empty($parenProduct) ? $product->images : $parenProduct->images;
		$img 			= reset($imgs);
		$imgUrl			= (empty($img) || empty($img->small)) ? '' : $img->small;
		$img_uf_xml_id	= self::getHLColorXmlId(self::getIDImageForHLColor($imgUrl, $product), $product->full_name);
		$result['COLOR_ES_REF'] = $img_uf_xml_id;

		return $result;
	}

	/**
	 * Get detail text from properties
	 * @param $product
	 * @return string
	 */
	public static function getProductDetailText($product): string
	{
		$properties = [];

		foreach ($product->attributes ?? [] as $attr) {
			switch ($attr->id ?? null) {
				case self::ATTR_COLOR_ID:
				case self::ATTR_MATERIAL_ID:
				case self::ATTR_BARCODE_ID:
				case self::ATTR_MARKING_ID:
				case self::ATTR_REMOTE_ID:
					continue 2;

				case self::ATTR_FLASH_ID:
					if (!empty($product->size)) {
						continue 2;
					}

				case null:
					if ($attr->name == self::ATTR_SIZE_NAME) {
						continue 2;
					}

				default:
					$dim 	= isset($attr->dim) ? (' ' . $attr->dim) : '';
					$value	= $attr->value . $dim;
					$needed	= array_search($attr->name, array_column($properties, 'name'));
					if ($needed === false) {
						$properties[] = [
							'name'  => htmlentities($attr->name, ENT_QUOTES, 'UTF-8'),
							'value' => htmlentities($value, ENT_QUOTES, 'UTF-8'),
						];
					} else {
						$properties[$needed]['value'] .= (', ' . $value);
					}
					break;
			}
		}

		$result = '';
		if (!empty($product->defect)) {
			$result .= ("\r\n<p>" . $product->defect . '</p>');
		}
		if ($properties) {
			$result .= "\r\n<p><b>Дополнительное описание:</b></p>\r\n<ul>"
						. implode('', array_map(fn($p) => "\r\n<li><b>{$p['name']}</b>: {$p['value']}</li>", $properties))
						. "\r\n</ul>";
		}
		return $result;
	}

	/**
	 * Load categories oasis
	 */
	public static function prepareCategories()
	{
		self::$oasisCategories = Api::getCategoriesOasis();

		if (empty(self::$cf->categories)) {
			self::$catSelected = array_map(fn($cat) => $cat->id, self::$oasisCategories);
		}
		else {
			$result = [];
			foreach (self::$cf->categories as $cat_id) {
				$result[] = $cat_id;

				// все родительские
				$_cat_id = $cat_id;
				while (true) {
					foreach (self::$oasisCategories as $cat) {
						if ($cat->id == $_cat_id && !empty($cat->parent_id)) {
							$result[] = $cat->parent_id;
							$_cat_id = $cat->parent_id;
							continue 2;
						}
					}
					break;
				}

				// все дочерние
				$parants = [$cat_id];
				while (true) {
					if ($parants) {
						$_parants = [];
						foreach ($parants as $parent_id) {
							foreach (self::$oasisCategories as $cat) {
								if ($cat->parent_id == $parent_id) {
									$result[] = $cat->id;
									$_parants[] = $cat->id;
								}
							}
						}
						$parants = $_parants;
					}
					else {
						break;
					}
				}
			}
			self::$catSelected = array_values(array_unique($result));
		}
	}

	/**
	 * Get iblock section id | add category and get iblock section id
	 * @param $categoryId
	 * @return int
	 */
	public static function getCategoryId($categoryId): int
	{
		$sectionCategory = self::getSectionByOasisCategoryId($categoryId);

		if ($sectionCategory) {
			$result = $sectionCategory['ID'];
		} else {
			$iblockSectionId = null;
			$oasisCategory = self::searchObject(self::$oasisCategories, $categoryId);

			if (!empty($oasisCategory->parent_id)) {
				$parentSectionCategory = self::getSectionByOasisCategoryId($oasisCategory->parent_id);

				if ($parentSectionCategory) {
					$iblockSectionId = $parentSectionCategory['ID'];
				} else {
					$iblockSectionId = self::getCategoryId($oasisCategory->parent_id);
				}
			}

			$result = self::addCategory($oasisCategory, $iblockSectionId);
		}

		return (int)$result;
	}

	/**
	 * Get iblock section id by user field UF_OASIS_ID_CATEGORY
	 * @param $categoryId
	 * @return array|false|void
	 */
	public static function getSectionByOasisCategoryId($categoryId)
	{
		$rel_id = self::$cf->getRelCategoryId($categoryId);
		if(isset($rel_id)){
			return ['ID' => $rel_id];
		}
		else{
			$entity = Section::compileEntityByIblock(self::$cf->iblock_catalog);
			try {
				return $entity::getList([
					'select' => ['ID'],
					'filter' => ['UF_OASIS_ID_CATEGORY' => $categoryId]
				])->fetch();
			} catch (ObjectPropertyException|ArgumentException|SystemException $e) {
				echo $e->getMessage() . PHP_EOL;
			}
		}
	}

	/**
	 * Add category
	 * @param $category
	 * @param $iblockSectionId
	 * @return false|int|mixed
	 */
	public static function addCategory($category, $iblockSectionId)
	{
		$objDateTime = new DateTime();
		$iblockSection = new CIBlockSection;

		$arFields = [
			'DATE_CREATE'          => $objDateTime->format('Y-m-d H:i:s'),
			'IBLOCK_ID'            => self::$cf->iblock_catalog,
			'IBLOCK_SECTION_ID'    => $iblockSectionId,
			'ACTIVE'               => 'Y',
			'NAME'                 => $category->name,
			'DEPTH_LEVEL'          => $category->level,
			'DESCRIPTION_TYPE'     => 'text',
			'CODE'                 => self::getUniqueCodeSection($category->slug),
			'UF_OASIS_ID_CATEGORY' => $category->id,
		];

		$result = $iblockSection->Add($arFields);

		if (!empty($iblockSection->LAST_ERROR)) {
			throw new SystemException('ErrorMessages: ' . $iblockSection->LAST_ERROR . print_r($arFields, true));
		}
		return $result;
	}

	/**
	 * Get unique (not DB) section code (alias)
	 * @param $slug
	 * @param int $i
	 * @return string
	 */
	public static function getUniqueCodeSection($slug, int $i = 0): string
	{
		$code = self::transliteration($slug);
		$code = $i === 0 ? $code : $code . '-' . $i;

		$dbCode = SectionTable::getList([
			'filter' => [
				'CODE' => $code,
			],
			'select' => ['ID'],
		])->fetch();

		if ($dbCode) {
			$code = self::getUniqueCodeSection($slug, ++$i);
		}
		return $code;
	}

	/**
	 * Get unique (not DB) element code (alias)
	 * @param $name
	 * @param int $i
	 * @return string
	 */
	public static function getUniqueCodeElement($name, int $i = 0): string
	{
		$code = self::transliteration($name);
		$code = $i === 0 ? $code : $code . '-' . $i;

		$dbCode = ElementTable::getList([
			'filter' => ['CODE' => $code],
		])->fetch();

		if ($dbCode) {
			$code = self::getUniqueCodeElement($name, ++$i);
		}
		return $code;
	}

	/**
	 * Check user fields
	 */
	public static function checkUserFields()
	{
		Loader::includeModule('iblock');

		$dataFields = [
			[
				'ENTITY_ID'		=> 'PRODUCT',
				'FIELD_NAME'	=> 'UF_OASIS_GROUP_ID',
				'LABEL'			=> [
					'ru' => 'Oasis группа',
					'en' => 'Oasis group',
				],
			],[
				'ENTITY_ID'		=> 'PRODUCT',
				'FIELD_NAME'	=> 'UF_OASIS_PRODUCT_ID',
				'LABEL'      	=> [
					'ru' => 'Oasis ID товара',
					'en' => 'Oasis product ID',
				],
			],[	
				'ENTITY_ID'		=> 'PRODUCT',
				'FIELD_NAME'	=> 'UF_OASIS_UPDATE_AT',
				'SETTINGS_SIZE'	=> '20',
				'LABEL'			=> [
					'ru' => 'Oasis update',
					'en' => 'Oasis update',
				],
			],[
				'ENTITY_ID'		=> 'IBLOCK_' . self::$cf->iblock_catalog . '_SECTION',
				'FIELD_NAME'	=> 'UF_OASIS_ID_CATEGORY',
				'LABEL'			=> [
					'ru' => 'Oasis ID категории',
					'en' => 'Oasis ID category',
				],
			],
		];

		foreach ($dataFields as $data) {
			$result = UserFieldTable::getList([
				'select' => ['ID'],
				'filter' => [
					'FIELD_NAME' => $data['FIELD_NAME'],
					'ENTITY_ID'  => $data['ENTITY_ID']
				],
			])->fetch();

			if (empty($result)) {
				(new CUserTypeEntity())->Add([
					'ENTITY_ID'			=> $data['ENTITY_ID'],
					'FIELD_NAME'		=> $data['FIELD_NAME'],
					'USER_TYPE_ID'		=> 'string',
					'XML_ID'			=> $data['FIELD_NAME'],
					'MULTIPLE'			=> 'N',
					'MANDATORY'			=> 'N',
					'SHOW_FILTER'		=> 'N',
					'SHOW_IN_LIST'		=> 'N',
					'EDIT_IN_LIST'		=> 'N',
					'IS_SEARCHABLE'		=> 'N',
					'SETTINGS'			=> [
						'DEFAULT_VALUE'	=> '',
						'SIZE'			=> $data['SETTINGS_SIZE'] ?? '11',
						'ROWS'			=> '1',
						'MIN_LENGTH'	=> '0',
						'MAX_LENGTH'	=> '0',
						'REGEXP'		=> '',
					],
					'EDIT_FORM_LABEL'	=> $data['LABEL'],
					'LIST_COLUMN_LABEL'	=> $data['LABEL'],
					'LIST_FILTER_LABEL'	=> $data['LABEL'],
					'HELP_MESSAGE'		=> [
						'ru' => '',
						'en' => '',
					],
				]);
			}
		}
	}

	/**
	 * Get array active stores for selectbox in page options
	 * @return array
	 */
	public static function getActiveStoresForOptions(): array
	{
		Loader::includeModule('catalog');

		$result = [];
		$arStores = StoreTable::getList([
			'filter' => ['ACTIVE' => 'Y'],
		])->fetchAll();

		foreach ($arStores as $arStore) {
			$result[$arStore['ID']] = '(ID=' . $arStore['ID'] . ') ' . $arStore['TITLE'];
		}
		return $result;
	}

	/**
	 * Get array active iblocks for selectbox in page options
	 * @return array
	 */
	public static function getActiveIblocksForOptions(): array
	{
		Loader::includeModule('iblock');

		$result = [];
		$arIblocks = IblockTable::getList([
			'select' => ['ID', 'NAME', 'IBLOCK_TYPE_ID'],
			'filter' => ['ACTIVE' => 'Y'],
		])->fetchAll();

		foreach ($arIblocks as $arIblock) {
			$result[$arIblock['ID']] = '(ID=' . $arIblock['ID'] . ') ' . $arIblock['NAME'];
		}
		return $result;
	}

	/**
	 * Get Oasis array of categories for a tree
	 * @return array
	 */
	public static function getOasisCategoriesToTree(): array
	{
		$result = [];
		$categories = Api::getCategoriesOasis();

		foreach ($categories as $category) {
			if (empty($result[intval($category->parent_id)])) {
				$result[intval($category->parent_id)] = [];
			}
			$result[intval($category->parent_id)][] = (array)$category;
		}
		return $result;
	}

	/**
	 * Get an array of categories for a tree
	 * @return array
	 */
	public static function getCategoriesToTree(): array
	{
		Loader::includeModule('iblock');
		$categories = CIBlockSection::GetTreeList(["IBLOCK_ID" => self::$cf->iblock_catalog], ['ID', 'NAME', 'IBLOCK_SECTION_ID']);

		$result = [];
		while($arSection = $categories->Fetch()) {
			$parent = $arSection['IBLOCK_SECTION_ID'] ?? 0;
			if (empty($result[$parent])) {
				$result[$parent] = [];
			}
			$result[$parent][] = [
				'id' => $arSection['ID'],
				'name' => $arSection['NAME'],
			];
		}
		return $result;
	}

	/**
	 * Get categories level 1
	 * @return array
	 * @throws ArgumentNullException
	 * @throws ArgumentOutOfRangeException
	 */
	public static function getOasisMainCategories(): array
	{
		$result = [];
		$categories = Api::getCategoriesOasis();

		foreach ($categories as $category) {
			if ($category->level === 1) {
				$result[$category->id] = $category->name;
			}
		}

		return $result;
	}

	/**
	 * Get currencies array
	 * @return array
	 * @throws ArgumentNullException
	 * @throws ArgumentOutOfRangeException
	 */
	public static function getCurrenciesOasisArray(): array
	{
		$result = [];

		if ($currenciesOasis = Api::getCurrenciesOasis()) {
			foreach ($currenciesOasis as $currency) {
				$result[$currency->code] = $currency->full_name;
			}
		}

		return $result;
	}

	/**
	 * Search object by id
	 * @param $data
	 * @param $id
	 * @return mixed|null
	 */
	public static function searchObject($data, $id)
	{
		foreach ($data as $item) {
			if (($item->id ?? null) == $id) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Find item
	 * @param $array
	 * @param $callback
	 * @return mixed|null
	 */
	public static function findItem(array $array, callable $callback)
	{
		foreach ($array as $key => $value) {
			if ($callback($value, $key)) {
				return $value;
			}
		}
		return null;
	}

	public static function simplifyOptionCategories($values = []): array
	{
		if ($values) {
			$categories = Api::getCategoriesOasis();
			$arr_cat	= [];
			foreach ($categories as $item) {
				$l = $item->level;
				if (empty($arr_cat[$l])) {
					$arr_cat[$l] = [];
				}
				if (empty($arr_cat[$l][$item->id])) {
					$arr_cat[$l][$item->id] = [];
				}
				if ($item->parent_id) {
					if (empty($arr_cat[$l][$item->parent_id])) {
						$arr_cat[$l][$item->parent_id] = [];
					}
					$arr_cat[$l][$item->parent_id][] = $item->id;
				}
			}
			ksort($arr_cat);
			while (true) {
				foreach (array_reverse($arr_cat) as $arr) {
					foreach ($arr as $id => $childs) {
						if (count($childs) > 0 && count(array_diff($childs, $values)) == 0){
							$values = array_diff($values, $childs);
							$values[] = $id;
							continue 3;
						}
					}
				}
				break;
			}

			$values = array_values(array_unique($values));
		}
		return $values ?? [];
	}

	/**
	 * String transliteration for url
	 * @param $string
	 * @return string
	 */
	public static function transliteration($string): string
	{
		$arr_trans = [
			'А'  => 'A',
			'Б'  => 'B',
			'В'  => 'V',
			'Г'  => 'G',
			'Д'  => 'D',
			'Е'  => 'E',
			'Ё'  => 'E',
			'Ж'  => 'J',
			'З'  => 'Z',
			'И'  => 'I',
			'Й'  => 'Y',
			'К'  => 'K',
			'Л'  => 'L',
			'М'  => 'M',
			'Н'  => 'N',
			'О'  => 'O',
			'П'  => 'P',
			'Р'  => 'R',
			'С'  => 'S',
			'Т'  => 'T',
			'У'  => 'U',
			'Ф'  => 'F',
			'Х'  => 'H',
			'Ц'  => 'TS',
			'Ч'  => 'CH',
			'Ш'  => 'SH',
			'Щ'  => 'SCH',
			'Ъ'  => '',
			'Ы'  => 'YI',
			'Ь'  => '',
			'Э'  => 'E',
			'Ю'  => 'YU',
			'Я'  => 'YA',
			'а'  => 'a',
			'б'  => 'b',
			'в'  => 'v',
			'г'  => 'g',
			'д'  => 'd',
			'е'  => 'e',
			'ё'  => 'e',
			'ж'  => 'j',
			'з'  => 'z',
			'и'  => 'i',
			'й'  => 'y',
			'к'  => 'k',
			'л'  => 'l',
			'м'  => 'm',
			'н'  => 'n',
			'о'  => 'o',
			'п'  => 'p',
			'р'  => 'r',
			'с'  => 's',
			'т'  => 't',
			'у'  => 'u',
			'ф'  => 'f',
			'х'  => 'h',
			'ц'  => 'ts',
			'ч'  => 'ch',
			'ш'  => 'sh',
			'щ'  => 'sch',
			'ъ'  => 'y',
			'ы'  => 'yi',
			'ь'  => '',
			'э'  => 'e',
			'ю'  => 'yu',
			'я'  => 'ya',
			'.'  => '-',
			' '  => '-',
			'?'  => '-',
			'/'  => '-',
			'\\' => '-',
			'*'  => '-',
			':'  => '-',
			'>'  => '-',
			'|'  => '-',
			'\'' => '',
			'('  => '',
			')'  => '',
			'!'  => '',
			'@'  => '',
			'%'  => '',
			'`'  => '',
		];
		$string = str_replace(['-', '+', '.', '?', '/', '\\', '*', ':', '*', '|'], ' ', $string);
		$string = htmlspecialchars_decode($string);
		$string = strip_tags($string);
		$pattern = '/[\w\s\d]+/u';
		preg_match_all($pattern, $string, $result);
		$string = implode('', $result[0]);
		$string = preg_replace('/[\s]+/us', ' ', $string);

		return strtolower(strtr($string, $arr_trans));
	}

	/**
	 * @param $array
	 * @param $keys
	 * @return bool
	 */
	public static function arrayKeysExists($array, $keys): bool
	{
		foreach ($keys as $key) {
			if (array_key_exists($key, $array)) {
				return true;
			}
		}
		return false;
	}
}