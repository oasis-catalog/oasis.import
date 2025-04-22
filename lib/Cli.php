<?php

namespace Oasis\Import;

use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\SystemException;
use Oasis\Import\Config as OasisConsfig;
use CFile;
use Exception;

class Cli
{
	public static array $dbCategories = [];
	public static array $src_img_temp = [];

	public static OasisConsfig $cf;


	public static function OnGetFileSRC($arFile)
	{
		$cf = OasisConsfig::instance([
			'init' => true
		]);
		if($cf->is_cdn_photo){
			if(stripos($arFile['FILE_NAME'],'https://')===0 || stripos($arFile['FILE_NAME'],'http://')===0) {
				return $arFile['FILE_NAME'];
			}
		}
		return false;
	}

	public static function OnEpilog()
	{
		if (defined('ADMIN_SECTION') && ADMIN_SECTION === true) {
			$cf = OasisConsfig::instance([
				'init' => true
			]);
			if($cf->is_cdn_photo){
				global $APPLICATION;
				$APPLICATION->AddHeadScript('/bitrix/js/' . OasisConsfig::MODULE_ID . '/admin.js');
			}
		}
	}	


	public static function RunCron($cron_key, $cron_up, $opt = [])
	{
		$cf = OasisConsfig::instance($opt);

		$cf->lock(function() use ($cf, $cron_key, $cron_up){
			$cf->init();

			if (!$cf->checkCronKey($cron_key)) {
				$cf->log('Error! Invalid --key');
				die('Error! Invalid --key');
			}

			if (!$cron_up && !$cf->checkPermissionImport()) {
				$cf->log('Import once day');
				die('Import once day');
			}

			self::$cf = $cf;
			Main::$cf = $cf;
			if ($cron_up) {
				self::UpStock();
			} else {
				self::Import();
			}
		}, function(){
			die('Already running');
		});
	}

	public static function ImportAgent()
	{
		try {
			$cf = new OasisConsfig();

			$cf->lock(function() use ($cf){
				$cf->init();

				if (!$cf->checkPermissionImport()) {
					echo 'Import once day';
				}

				self::$cf = $cf;
				Main::$cf = $cf;
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
			$cf = new OasisConsfig();

			$cf->lock(function() use ($cf){
				$cf->init();

				self::$cf = $cf;
				Main::$cf = $cf;
				self::UpStock();
			}, function(){
				echo 'Already running';
			});
		}
		catch (Exception $e) { }

		return "\\Oasis\\Import\\Cli::UpStockAgent();";
	}

	public static function Import()
	{
		Loader::includeModule('iblock');
		Loader::includeModule('catalog');

		set_time_limit(0);
		ini_set('memory_limit', '2G');

		while (ob_get_level()) {
			ob_end_flush();
		}

		try {
			if (empty(self::$cf->iblock_catalog) || empty(self::$cf->iblock_offers)) {
				throw new Exception('Infoblocks not selected');
			}

			self::InitHandlerCDN();
			self::$dbCategories = Main::getSelectedCategories();

			$dataCalcPrice = [
				'factor'   => self::$cf->factor,
				'increase' => self::$cf->increase,
				'dealer'   => self::$cf->dealer,
			];
			$dataCalcPrice = array_diff($dataCalcPrice, ['', 0]);

			$args = [];
			$limit = self::$cf->limit;
			$step = self::$cf->progress['step'];

			if ($limit > 0) {
				$args['limit'] = $limit;
				$args['offset'] = $step * $limit;
			}

			self::$cf->deleteLogFile();
			Main::checkStores();
			Main::checkUserFields(self::$cf->iblock_catalog);
			Main::checkProperties(self::$cf->iblock_catalog, self::$cf->iblock_offers);

			$oasisProducts = Api::getProductsOasis($args);
			$oasisCategories = Api::getCategoriesOasis();
			$stat = Api::getStatProducts();

			$group_ids = [];
			$countProducts = 0;
			foreach ($oasisProducts as $product) {
				if (self::$cf->delete_exclude) {
					if (empty(array_intersect($product->categories, self::$dbCategories))) {
						Main::checkDeleteProduct($product->id);
						continue;
					}
				}

				if ($product->is_deleted === false) {
					$group_ids[$product->group_id][$product->id] = $product;
					$countProducts++;
				} else {
					Main::checkDeleteProduct($product->id);
				}
			}
			unset($product);

			if (self::$cf->delete_exclude) {
				$allOaProducts = Main::getAllOaProducts();

				if (!empty($allOaProducts)) {
					$resProducts = API::getProductsOasisOnlyFieldCategories(array_column($allOaProducts, 'UF_OASIS_PRODUCT_ID'));

					foreach ($resProducts as $resProduct) {
						if (empty(array_intersect($resProduct->categories, self::$dbCategories))) {
							Main::checkDeleteProduct($resProduct->id);
						}
					}
				}
				unset($allOaProducts, $resProducts, $resProduct);
			}

			self::$cf->progressStart($stat['products'], $countProducts);

			$nextStep = ++$step;
			$totalGroup = count($group_ids);
			$itemGroup = 0;

			foreach ($group_ids as $products) {
				if (count($products) === 1) {
					$product = reset($products);
					$dbProducts = Main::checkProduct($product->group_id, 0, true);

					if ($dbProducts) {
						if (count($dbProducts) > 1) {
							foreach ($dbProducts as $dbProductsItem) {
								if ($dbProductsItem['TYPE'] == ProductTable::TYPE_OFFER) {
									$dbProduct = $dbProductsItem;
								}
							}
						}

						$dbProduct = $dbProduct ?? reset($dbProducts);
						$productId = (int)$dbProduct['ID'];
						Main::upIblockElementProduct($productId, $product, self::$cf->iblock_catalog, $oasisCategories);
						self::$cf->log('Up product id ' . $product->id);
					} else {
						$properties = Main::getPropertiesArray($product);
						$properties += Main::getProductImages($product);
						$productId = Main::addIblockElementProduct($product, $oasisCategories, $properties, self::$cf->iblock_catalog, ProductTable::TYPE_PRODUCT);
						Main::executeStoreProduct($productId, $product);
						self::$cf->log('Add product id ' . $product->id);
					}

					Main::upPropertiesFilter($productId, $product, self::$cf->iblock_catalog);
					Main::executeProduct($productId, $product, $product->group_id, ProductTable::TYPE_PRODUCT);
					Main::executePriceProduct($productId, $product, $dataCalcPrice);
					self::$cf->progressUp();
					unset($dbProducts, $dbProduct, $productId, $properties);
				} else {
					$firstProduct = reset($products);
					$dbProduct = Main::checkProduct($firstProduct->group_id);

					if ($dbProduct) {
						$productId = (int)$dbProduct['ID'];
						Main::upIblockElementProduct($productId, $firstProduct, self::$cf->iblock_catalog, $oasisCategories);
						self::$cf->log('Up product id ' . $firstProduct->id);
					} else {
						$properties = Main::getPropertiesArray($firstProduct);
						$properties += Main::getProductImages($firstProduct);
						$productId = Main::addIblockElementProduct($firstProduct, $oasisCategories, $properties, self::$cf->iblock_catalog, ProductTable::TYPE_SKU);
						self::$cf->log('Add product id ' . $firstProduct->id);
					}

					Main::executeProduct($productId, $firstProduct, $firstProduct->group_id, ProductTable::TYPE_SKU, true);
					Main::executePriceProduct($productId, $firstProduct, $dataCalcPrice);

					foreach ($products as $product) {
						$firstIMG = Main::getUrlFirstImageProductForParentColorId($product, $products);
						$imageId = Main::getIDImageForHL($firstIMG, $product);
						Main::checkRowHLBlock(CFile::MakeFileArray($imageId), $product->full_name);

						$dbOffer = Main::checkProduct($product->id, ProductTable::TYPE_OFFER);

						if ($dbOffer) {
							$productOfferId = (int)$dbOffer['ID'];
							Main::upIblockElementProduct($productOfferId, $product, 0);
							self::$cf->log('Up offer id ' . $product->id);
						} else {
							$propertiesOffer = Main::getPropertiesArrayOffer($productId, $product, $firstIMG, self::$cf->iblock_offers);
							$productOfferId = Main::addIblockElementProduct($product, $oasisCategories, $propertiesOffer, self::$cf->iblock_offers, ProductTable::TYPE_OFFER);
							Main::executeMeasureRatioTable($productOfferId);
							Main::executeStoreProduct($productOfferId, $product);
						   self::$cf->log('Add offer id ' . $product->id);
						}

						Main::executeProduct($productOfferId, $product, $product->id, ProductTable::TYPE_OFFER);
						Main::executePriceProduct($productOfferId, $product, $dataCalcPrice);
						self::$cf->progressUp();
						unset($product, $dbOffer, $productOfferId, $propertiesOffer);
					}

					Main::upPropertiesFilterOffers($productId, $firstProduct, $products, self::$cf->iblock_catalog);
					Main::upStatusFirstProduct($productId, self::$cf->iblock_catalog);
					unset($firstProduct, $dbProduct, $productId, $properties);
				}
				self::$cf->log('Done ' . ++$itemGroup . ' from ' . $totalGroup);
				unset($products, $product);

				self::ClearTempCDNFile();
			}

			self::$cf->progressEnd();
		} catch (SystemException $e) {
			echo $e->getMessage() . PHP_EOL;
			exit();
		}
	}

	public static function InitHandlerCDN()
	{
		if(!self::$cf->is_cdn_photo){
			return;
		}

		AddEventHandler('main','OnMakeFileArray', function ($url, &$temp_path){
			if (!is_array($url) && (stripos($url,'http://') === 0 || stripos($url,'https://') === 0)) {
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
		   if(isset(self::$src_img_temp[$arFile['tmp_name']])) {
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

		set_time_limit(0);
		ini_set('memory_limit', '2G');

		try {
			Main::checkStores();
			$stock = Api::getOasisStock();
			Main::upQuantity($stock);
		} catch (SystemException $e) {
			echo $e->getMessage() . PHP_EOL;
		}
	}
}