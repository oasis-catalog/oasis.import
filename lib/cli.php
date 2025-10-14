<?php

namespace Oasis\Import;

use Bitrix\Catalog\ProductTable;
use Bitrix\Currency\CurrencyManager;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\SystemException;
use Bitrix\Sale\BasketItem;
use Oasis\Import\Config as OasisConfig;
use CFile;
use Exception;

class Cli
{
	public static array $src_img_temp = [];
	public static array $brands = [];
	public static bool  $handlerCDN_disable = false;

	public static OasisConfig $cf;


	public static function OnGetFileSRC($arFile)
	{
		$cf = OasisConfig::instance([
			'init' => true
		]);
		if($cf->is_cdn_photo){
			if(stripos($arFile['FILE_NAME'],'https://') === 0 || stripos($arFile['FILE_NAME'],'http://') === 0) {
				return $arFile['FILE_NAME'];
			}
		}
		return false;
	}

	public static function OnEpilog()
	{
		global $APPLICATION;
		$cf = OasisConfig::instance([
			'init' => true
		]);
		if (defined('ADMIN_SECTION') && ADMIN_SECTION === true && $cf->is_cdn_photo) {
			$APPLICATION->AddHeadScript('/bitrix/js/' . OasisConfig::MODULE_ID . '/admin.js');
		}
		elseif ((!defined('ADMIN_SECTION') || ADMIN_SECTION === false) && $cf->is_branding) {
			$locale = null;
			switch (LANGUAGE_ID)
			{
				case 'en':
					$locale = 'en-US'; break;
				case 'ua':
					$locale = 'ru-UA'; break;
				case 'tk':
					$locale = 'tr-TR'; break;
				case 'ru':
				default:
					$locale = 'ru-RU'; break;
			}

			$APPLICATION->AddHeadScript('//unpkg.com/@oasis-catalog/branding-widget@1.3.0/client/index.iife.js');
			$APPLICATION->SetAdditionalCSS('//unpkg.com/@oasis-catalog/branding-widget@1.3.0/client/style.css');
			$APPLICATION->AddHeadString('<script>
										if (!window.OaHelper) window.OaHelper = {};
										window.OaHelper.branding = "' . $cf->branding_box . '";
										window.OaHelper.currency = "' . CurrencyManager::getBaseCurrency() . '";
										window.OaHelper.locale = "' . $locale . '";
										</script>');
			$APPLICATION->AddHeadScript('/bitrix/js/' . OasisConfig::MODULE_ID . '/widget.js');
		}
	}

	public static function OnSaleBasketItemSaved(\Bitrix\Main\Event $event)
	{
		global $USER_FIELD_MANAGER;

		$request = Application::getInstance()->getContext()->getRequest();
		if ($request->get('action') == 'BUY' && !empty($branding = $request->get('branding'))) {
			$basketItem = $event->getParameter('ENTITY');
			if ($basketItem instanceof BasketItem) {
				$old_branding = $USER_FIELD_MANAGER->GetUserFieldValue('BASKET_ITEM', 'UF_OASIS_BRANDING', $basketItem->getId());

				if (empty($old_branding)) {
					OasisConfig::instance([
						'init' => true
					]);

					$USER_FIELD_MANAGER->Delete('BASKET_ITEM', $basketItem->getId());

					$result = Main::getBrandingInfo($branding, $basketItem->getQuantity());

					if ($result) {
						$USER_FIELD_MANAGER->Update('BASKET_ITEM', $basketItem->getId(), [
							'UF_OASIS_BRANDING'      => json_encode($branding),
							'UF_OASIS_BRANDING_COST' => $result['cost'],
							'UF_OASIS_BRANDING_AT'   => date('Y-m-d'),
						]);
						$property = $basketItem->getPropertyCollection()->createItem();
						$property->setFields([
							'NAME' => 'Нанесение',
							'CODE' => 'OASIS_BRANDING',
							'VALUE' => $result['label'],
							'SORT' => 100,
						]);
					}
				}
			}
		}
		elseif ($request->get('basketAction') == 'recalculateAjax') {
			$basketItem = $event->getParameter('ENTITY');
			if ($basketItem instanceof BasketItem) {
				$USER_FIELD_MANAGER->Update('BASKET_ITEM', $basketItem->getId(), [
					'UF_OASIS_BRANDING_AT'   => null,
					'UF_OASIS_BRANDING_COST' => null,
				]);
			}
		}
	}

	public static function OnSaleBasketBeforeSaved(\Bitrix\Main\Event $event)
	{
		global $USER_FIELD_MANAGER;
		OasisConfig::instance([
			'init' => true
		]);
		
		$basket = $event->getParameter('ENTITY');

		$price = 0;
		foreach ($basket->getBasketItems() as $basketItem) {
			if ($basketItem instanceof BasketItem && $basketItem->getField('NAME') !== 'Услуги нанесения') {
				$fields     = $USER_FIELD_MANAGER->GetUserFields('BASKET_ITEM', $basketItem->getId());
				$branding   = $fields['UF_OASIS_BRANDING']['VALUE'] ?? '';
				$updated_at = $fields['UF_OASIS_BRANDING_AT']['VALUE'] ?? null;
				$cost       = $fields['UF_OASIS_BRANDING_COST']['VALUE'] ?? 0;

				if ($updated_at === date('Y-m-d')) {
					$price += $cost;
				}
				else {
					try {
						$branding = json_decode($branding, true);
					}
					catch (Exception $e) {
						$branding = null;
					}
					if (!empty($branding)) {
						$result = Main::getBrandingInfo($branding, $basketItem->getQuantity());
						$USER_FIELD_MANAGER->Update('BASKET_ITEM', $basketItem->getId(), [
							'UF_OASIS_BRANDING_COST' => $result['cost'],
							'UF_OASIS_BRANDING_AT'   => date('Y-m-d'),
						]);

						$price += $result['cost'];
					}
				}
			}
		}

		if ($price > 0) {
			$basketItem = self::getBrandingBasketItem($basket);
			if (empty($basketItem)) {
				$basketItem = $basket->createItem('oasis.import', 0);
			}
			$basketItem->setFields([
				'NAME'                   => 'Услуги нанесения',
				'PRICE'                  => $price,
				'QUANTITY'               => 1,
				'CURRENCY'               => CurrencyManager::getBaseCurrency(),
				'LID'                    => \Bitrix\Main\Context::getCurrent()->getSite(),
				'PRODUCT_PROVIDER_CLASS' => false,
				'CUSTOM_PRICE'           => 'Y',
			]);
		}
		else {
			$basketItem = self::getBrandingBasketItem($basket);
			if (!empty($basketItem)) {
				$basketItem->delete();
			}
		}
	}

	private static function getBrandingBasketItem($basket)
	{
		foreach ($basket->getBasketItems() as $basketItem) {
			if ($basketItem instanceof BasketItem && $basketItem->getField('NAME') === 'Услуги нанесения') {
				return $basketItem;
			}
		}
		return null;
	}



	public static function RunCron($cron_key, $cron_opt = [], $opt = [])
	{
		set_time_limit(0);
		ini_set('memory_limit', '2G');

		while (ob_get_level()) {
			ob_end_flush();
		}

		$cf = OasisConfig::instance($opt);

		if (in_array($cron_opt['task'], ['add_image', 'up_image'])) {
			$cf->init();
			if (!$cf->checkCronKey($cron_key)) {
				$cf->log('Error! Invalid --key');
				die('Error! Invalid --key');
			}
			self::AddImage([
				'oid' => $cron_opt['oid'] ?? '',
				'is_up' => $cron_opt['task'] == 'up_image'
			]);
		}
		else {
			$cf->lock(function() use ($cf, $cron_key, $cron_opt){
				$cf->init();

				if (!$cf->checkCronKey($cron_key)) {
					$cf->log('Error! Invalid --key');
					die('Error! Invalid --key');
				}

				switch ($cron_opt['task']) {
					case 'import':
						if(!$cf->checkPermissionImport()) {
							$cf->log('Import once day');
							die('Import once day');
						}
						self::Import($cron_opt);
						break;

					case 'up':
						self::UpStock();
						break;
				}
			}, function(){
				die('Already running');
			});
		}
	}

	public static function ImportAgent()
	{
		try {
			$cf = OasisConfig::instance();

			$cf->lock(function() use ($cf){
				$cf->init();

				if (!$cf->checkPermissionImport()) {
					echo 'Import once day';
				}

				self::Import();
			}, function(){
				echo 'Already running';
			});
		}
		catch (Exception $e) { }

		return "\\Oasis\\Import\\Cli::ImportAgent();";
	}

	public static function UpStockAgent()
	{
		try {
			$cf = OasisConfig::instance();

			$cf->lock(function() use ($cf){
				$cf->init();

				self::UpStock();
			}, function(){
				echo 'Already running';
			});
		}
		catch (Exception $e) { }

		return "\\Oasis\\Import\\Cli::UpStockAgent();";
	}

	public static function Import($opt = [])
	{
		Loader::includeModule('iblock');
		Loader::includeModule('catalog');

		try {
			if (empty(self::$cf->iblock_catalog) || empty(self::$cf->iblock_offers)) {
				throw new Exception('Infoblocks not selected');
			}
			self::$cf->log('Начало обновления товаров');

			self::InitHandlerCDN();
			if (self::$cf->is_brands) {
				self::$brands = Main::getBrands();
			}
			Main::checkStores();
			Main::checkUserFields();
			Main::checkProperties();
			Main::prepareCategories();

			if (self::$cf->delete_exclude) {
				self::$cf->log('Delete exclude');
				Main::deleteProductsUnselectedCat();
				self::$cf->log('Delete exclude end');
			}

			$args = [];
			if (!empty($opt['oid'])) {
				$args['ids'] = is_array($opt['oid']) ? implode(',', $opt['oid']) : $opt['oid'];
			}
			elseif (!empty($opt['sku'])) {
				$args['articles'] = is_array($opt['sku']) ? implode(',', $opt['sku']) : $opt['sku'];
			}
			else {
				$args['category'] = implode(',', self::$cf->categories ?: array_keys(Main::getOasisMainCategories()));

				if (self::$cf->limit > 0) {
					$args['limit']  = self::$cf->limit;
					$args['offset'] = self::$cf->limit * self::$cf->progress['step'];
				}
			}

			$stat      = Api::getStatProducts();
			$groups    = [];
			$totalStep = 0;

			foreach (Api::getProductsOasis($args) as $product) {
				if (empty($product->is_deleted)) {
					$groups[$product->group_id][$product->id] = $product;
					$totalStep++;
				} else {
					Main::checkDeleteProduct($product->id);
				}
			}

			self::$cf->progressStart($stat['products'], $totalStep);

			$totalGroup = count($groups);
			$itemGroup = 0;

			foreach ($groups as $group_id => $products) {
				if (count($products) === 1) {
					$product	= reset($products);
					$dbProducts = Main::checkProducts($product->id);
					if (count($dbProducts) > 1) {
						Main::checkDeleteProduct($product->id);
						$dbProduct = null;
					} else {
						$dbProduct = reset($dbProducts);
					}
					$is_need_up = true;

					if ($dbProduct) {
						$productId	= (int)$dbProduct['ID'];
						$is_need_up	= Main::getNeedUp($product, $dbProduct);
						Main::upIblockElementProduct($dbProduct, $product, $is_need_up);
					} else {
						$properties = Main::getPropertiesArray($product);
						if(!self::$cf->is_fast_import){
							$properties += Main::getProductImages($product);
						}
						$productId = Main::addIblockElementProduct($product, $properties, ProductTable::TYPE_PRODUCT);
						Main::executeStoreProduct($productId, $product);
					}

					if ($is_need_up) {
						Main::upPropertiesFilter($productId, $product);
						Main::executeProduct($productId, $product, $group_id, ProductTable::TYPE_PRODUCT);
					}
					Main::executePriceProduct($productId, $product);

					self::ProcessLog($product->id, empty($dbProducts), $is_need_up);
					self::$cf->progressUp();
				} else {
					$firstProduct = reset($products);
					$dbProduct    = Main::checkProduct($firstProduct->id, ProductTable::TYPE_SKU);
					$is_need_up   = true;

					if ($dbProduct) {
						$productId = (int)$dbProduct['ID'];
						$is_need_up = Main::getNeedUp($firstProduct, $dbProduct);
						Main::upIblockElementProduct($dbProduct, $firstProduct, $is_need_up, ProductTable::TYPE_SKU);
					} else {
						Main::checkDeleteGroup($group_id);
						$properties = Main::getPropertiesArray($firstProduct);
						if(!self::$cf->is_fast_import){
							$properties += Main::getProductImages($firstProduct);
						}
						$productId = Main::addIblockElementProduct($firstProduct, $properties, ProductTable::TYPE_SKU);
					}

					if ($is_need_up) {
						Main::executeProduct($productId, $firstProduct, $group_id, ProductTable::TYPE_SKU);
					}
					Main::executePriceProduct($productId, $firstProduct);

					foreach ($products as $product) {
						$dbOffer = Main::checkProduct($product->id, ProductTable::TYPE_OFFER);
						$is_need_offer_up = true;

						if ($dbOffer) {
							$productOfferId = (int)$dbOffer['ID'];
							$is_need_offer_up = Main::getNeedUp($product, $dbOffer);
							Main::upIblockElementProduct($dbOffer, $product, $is_need_offer_up, ProductTable::TYPE_OFFER);
						} else {
							$propertiesOffer = Main::getPropertiesArrayOffer($productId, $product, $products);
							$productOfferId = Main::addIblockElementProduct($product, $propertiesOffer, ProductTable::TYPE_OFFER);
							Main::executeMeasureRatioTable($productOfferId);
							Main::executeStoreProduct($productOfferId, $product);
						}

						if ($is_need_offer_up) {
							Main::executeProduct($productOfferId, $product, $group_id, ProductTable::TYPE_OFFER);
						}
						Main::executePriceProduct($productOfferId, $product);

						self::ProcessLog($product->id, empty($dbOffer), $is_need_offer_up, true);
						self::$cf->progressUp();
					}

					Main::upPropertiesFilterOffers($productId, $firstProduct, $products);
					Main::upStatusSku($productId, $dbProduct, $products);
					self::ProcessLog($firstProduct->id, empty($dbProduct), $is_need_up);
				}
				self::ClearTempCDNFile();
				self::$cf->log('Done ' . ++$itemGroup . ' from ' . $totalGroup);
			}

			self::$cf->progressEnd();
			self::$cf->log('Окончание обновления товаров');
		} catch (SystemException $e) {
			echo $e->getMessage() . PHP_EOL;
			exit();
		}
	}

	private static function ProcessLog($id, $is_new, $is_up, $is_offer = false)
	{
		if ($is_offer) {
			if ($is_new) {
				self::$cf->log(' add offer id ' . $id);
			} else {
				self::$cf->log(($is_up ? ' up offer id ' : ' actual offer id ') . $id);
			}
		}
		else {
			if ($is_new) {
				self::$cf->log('Add product id ' . $id);
			} else {
				self::$cf->log(($is_up ? 'Up product id ' : 'Actual product id ') . $id);
			}
		}
	}


	public static function InitHandlerCDN()
	{
		if(!self::$cf->is_cdn_photo){
			return;
		}

		AddEventHandler('main','OnMakeFileArray', function ($url, &$temp_path){
			if (!self::$handlerCDN_disable
				&& !is_array($url)
				&& (stripos($url,'http://') === 0 || stripos($url,'https://') === 0)
			) {
				$image = file_get_contents($url, false, stream_context_create([
					 'http' => [
						'method' => 'GET',
						'timeout' => '10',
						'ignore_errors' => false,
					]
				]));
				$arUrl = parse_url($url);
				$arPath = pathinfo($arUrl['path']);
				mkdir(self::$cf->root_path.'/upload/module-oasis/http_image_tmp/', BX_DIR_PERMISSIONS, true);
				$temp_path = self::$cf->root_path.'/upload/module-oasis/http_image_tmp/'.$arPath['basename'];

				file_put_contents($temp_path, $image);

				$arImageSize = getimagesize($temp_path);

				self::$src_img_temp[$temp_path] = [
					'URL' => $url,
					'NAME' => $arPath['basename'],
					'SIZE' => filesize($temp_path),
					'TYPE' => image_type_to_mime_type(exif_imagetype($temp_path)),
					'WIDTH' => IntVal($arImageSize[0]),
					'HEIGHT' => IntVal($arImageSize[1]),
				];
				return true;
			}
			return false;
		});

		AddEventHandler('main','OnFileSave', function (&$arFile, $strFileName, $strSavePath, $bForceMD5 = false, $bSkipExt = false, $dirAdd = ''){
			if(!self::$handlerCDN_disable
				&& isset(self::$src_img_temp[$arFile['tmp_name']])
			) {
				$arFileTmp = self::$src_img_temp[$arFile['tmp_name']];

				$arFile['FILE_NAME'] = $arFileTmp['URL'];
				$arFile['ORIGINAL_NAME'] = $arFileTmp['NAME'];
				$arFile['size'] = $arFileTmp['SIZE'];
				$arFile['type'] = $arFileTmp['TYPE'];
				$arFile['WIDTH'] = $arFileTmp['WIDTH'];
				$arFile['HEIGHT'] = $arFileTmp['HEIGHT'];

				return true;
			}
		});
	}

	public static function ClearTempCDNFile()
	{
		foreach(self::$src_img_temp as $tmp_name => $data){
			@unlink($tmp_name);
		}
		self::$src_img_temp  = [];
	}

	public static function UpStock()
	{
		Loader::includeModule('iblock');
		Loader::includeModule('catalog');

		try {
			Main::checkStores();
			$oasisProducts = [];
			foreach (Main::getAllOaProducts() as $row) {
				if ($row['TYPE'] == ProductTable::TYPE_OFFER || empty($oasisProducts[$row['UF_OASIS_PRODUCT_ID']])) {
					$oasisProducts[$row['UF_OASIS_PRODUCT_ID']] = $row['ID'];
				}
			}

			$stock = [];
			foreach (Api::getStockOasis() as $item) {
				$stock[$item->id] = $item;
			}

			foreach ($oasisProducts as $product_id => $bx_id) {
				$stock_item = $stock[$product_id] ?? null;
				if ($stock_item) {
					ProductTable::update($bx_id, ['QUANTITY' => $stock_item->stock + $stock_item->{'stock-remote'}]);
					Main::executeStoreProduct($bx_id, $stock_item, true);
				}
				else {
					Main::checkDeleteProduct($product_id);
					self::$cf->log('Удаление OAId=' . $product_id . ' BID='. $bx_id);
				}
			}
		} catch (SystemException $e) {
			echo $e->getMessage() . PHP_EOL;
		}
	}

	public static function AddImage($opt = []) {
		Loader::includeModule('iblock');
		Loader::includeModule('catalog');

		try {
			if (empty(self::$cf->iblock_catalog) || empty(self::$cf->iblock_offers)) {
				throw new Exception('Infoblocks not selected');
			}

			self::InitHandlerCDN();

			$args = [];
			if(!empty($opt['oid'])){
				$args['ids'] =  is_array($opt['oid']) ? implode(',', $opt['oid']) : $opt['oid'];
			}

			$groups = [];
			foreach (Api::getProductsOasis($args) as $product) {
				if (empty($product->is_deleted)) {
					$groups[$product->group_id][$product->id] = $product;
				}
			}

			$totalGroup = count($groups);
			$itemGroup = 0;
			$is_up = !empty($opt['is_up']);

			foreach ($groups as $products) {
				if (count($products) === 1) {
					$product	= reset($products);
					$dbProducts = Main::checkProducts($product->id);
					if (count($dbProducts) > 1) {
						Main::checkDeleteProduct($product->id);
						$dbProduct = null;
					} else {
						$dbProduct = reset($dbProducts);
					}

					if ($dbProduct) {
						$productId = (int)$dbProduct['ID'];
						Main::iblockElementProductAddImage($productId, $product, $is_up);
						self::$cf->log('Up product image id ' . $product->id);
					}
				} else {
					$firstProduct = reset($products);
					$dbProduct    = Main::checkProduct($firstProduct->id, ProductTable::TYPE_SKU);

					if ($dbProduct) {
						$productId = (int)$dbProduct['ID'];
						Main::IblockElementProductAddImage($productId, $firstProduct, $is_up);
						self::$cf->log('Up product image id ' . $firstProduct->id);
					}
					foreach ($products as $product) {
						$dbOffer = Main::checkProduct($product->id, ProductTable::TYPE_OFFER);
						if ($dbOffer) {
							$productOfferId = (int)$dbOffer['ID'];
							Main::IblockElementProductAddImage($productOfferId, $product, $is_up);
							self::$cf->log('Up offer image id ' . $product->id);
						}
					}
				}
				self::$cf->log('Done ' . ++$itemGroup . ' from ' . $totalGroup);
			}
		} catch (SystemException $e) {
			echo $e->getMessage() . PHP_EOL;
			exit();
		}
	}
}