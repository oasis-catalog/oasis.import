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
	public static string $module_id = '';

	public static OasisConsfig $cf;


	public static function RunCron($cron_key, $cron_up)
	{
		$cf = new OasisConsfig();

		$cf->lock(function() use ($cf, $cron_key, $cron_up){
			$cf->init();

			if (!$cf->checkCronKey($cron_key)) {
				die('Error! Invalid --key');
			}

			if (!$cron_up && !$cf->checkPermissionImport()) {
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

	/**
	 * @throws LoaderException
	 * @throws Exception
	 */
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
			$args = [];
			self::$module_id = pathinfo(dirname(__DIR__))['basename'];
			$iblockIdCatalog = (int)Option::get(self::$module_id, 'iblock_catalog');
			$iblockIdOffers = (int)Option::get(self::$module_id, 'iblock_offers');
			$deleteExclude = Option::get(self::$module_id, 'delete_exclude') === 'Y';

			self::$dbCategories = Main::getSelectedCategories();

			if (empty($iblockIdCatalog) || empty($iblockIdOffers)) {
				throw new Exception('Infoblocks not selected');
			}

			$step = (int)Option::get(self::$module_id, 'step');
			$limit = (int)Option::get(self::$module_id, 'limit');
			$dataCalcPrice = [
				'factor'   => str_replace(',', '.', Option::get(self::$module_id, 'factor')),
				'increase' => str_replace(',', '.', Option::get(self::$module_id, 'increase')),
				'dealer'   => Option::get(self::$module_id, 'dealer'),
			];
			$dataCalcPrice = array_diff($dataCalcPrice, ['', 0]);

			if ($limit > 0) {
				$args['limit'] = $limit;
				$args['offset'] = $step * $limit;
			} else {
				Option::set(self::$module_id, 'progressItem', 0);
			}

			self::$cf->deleteLogFile();
			Main::checkStores();
			Main::checkUserFields($iblockIdCatalog);
			Main::checkProperties($iblockIdCatalog, $iblockIdOffers);

			$oasisProducts = Api::getProductsOasis($args);
			$oasisCategories = Api::getCategoriesOasis();
			$stat = Api::getStatProducts();

			$group_ids = [];
			$countProducts = 0;
			foreach ($oasisProducts as $product) {
				if ($deleteExclude) {
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

			if ($deleteExclude) {
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

			if ($group_ids) {
				Option::set(self::$module_id, 'progressTotal', $stat['products']);
				Option::set(self::$module_id, 'progressStepItem', 0);
				Option::set(self::$module_id, 'progressStepTotal', !empty($limit) ? $countProducts : 0);
				$moveFirstImg = Option::get(self::$module_id, 'move_first_img_to_detail') === 'Y';

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
							Main::upIblockElementProduct($productId, $product, $iblockIdCatalog, $oasisCategories);
							self::$cf->log('Up product id ' . $product->id);
						} else {
							$properties = Main::getPropertiesArray($product);
							$properties += Main::getProductImages($product, $moveFirstImg);
							$productId = Main::addIblockElementProduct($product, $oasisCategories, $properties, $iblockIdCatalog, ProductTable::TYPE_PRODUCT);
							Main::executeStoreProduct($productId, $product);
							self::$cf->log('Add product id ' . $product->id);
						}

						Main::upPropertiesFilter($productId, $product, $iblockIdCatalog);
						Main::executeProduct($productId, $product, $product->group_id, ProductTable::TYPE_PRODUCT);
						Main::executePriceProduct($productId, $product, $dataCalcPrice);
						Main::upProgressBar($limit);
						unset($dbProducts, $dbProduct, $productId, $properties);
					} else {
						$firstProduct = reset($products);
						$dbProduct = Main::checkProduct($firstProduct->group_id);

						if ($dbProduct) {
							$productId = (int)$dbProduct['ID'];
							Main::upIblockElementProduct($productId, $firstProduct, $iblockIdCatalog, $oasisCategories);
							self::$cf->log('Up product id ' . $firstProduct->id);
						} else {
							$properties = Main::getPropertiesArray($firstProduct);
							$properties += Main::getProductImages($firstProduct, $moveFirstImg);
							$productId = Main::addIblockElementProduct($firstProduct, $oasisCategories, $properties, $iblockIdCatalog, ProductTable::TYPE_SKU);
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
								$propertiesOffer = Main::getPropertiesArrayOffer($productId, $product, $firstIMG, $iblockIdOffers);
								$productOfferId = Main::addIblockElementProduct($product, $oasisCategories, $propertiesOffer, $iblockIdOffers, ProductTable::TYPE_OFFER);
								Main::executeMeasureRatioTable($productOfferId);
								Main::executeStoreProduct($productOfferId, $product);
							   self::$cf->log('Add offer id ' . $product->id);
							}

							Main::executeProduct($productOfferId, $product, $product->id, ProductTable::TYPE_OFFER);
							Main::executePriceProduct($productOfferId, $product, $dataCalcPrice);
							Main::upProgressBar($limit);
							unset($product, $dbOffer, $productOfferId, $propertiesOffer);
						}

						Main::upPropertiesFilterOffers($productId, $firstProduct, $products, $iblockIdCatalog);
						Main::upStatusFirstProduct($productId, $iblockIdCatalog);
						unset($firstProduct, $dbProduct, $productId, $properties);
					}
					self::$cf->log('Done ' . ++$itemGroup . ' from ' . $totalGroup);
					unset($products, $product);
				}
			} else {
				$nextStep = 0;
				Option::set(self::$module_id, 'progressItem', 0);
			}

			$objDateTime = (new \DateTime())->format('d.m.Y H:i:s');

			if (!empty($limit)) {
				Option::set(self::$module_id, 'step', $nextStep);
				Option::set(self::$module_id, 'progressStepItem', 0);
			} else {
				Option::set(self::$module_id, 'progressItem', $stat['products']);
			}

			if (empty($limit) || $nextStep == 0) {
				Option::set(self::$module_id, 'import_date', $objDateTime);
			}

			Option::set(self::$module_id, 'progressDate', $objDateTime);
		} catch (SystemException $e) {
			echo $e->getMessage() . PHP_EOL;
			exit();
		}
	}

	/**
	 * Cron - Up stock
	 *
	 * @return string
	 * @throws LoaderException
	 * @throws Exception
	 */
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