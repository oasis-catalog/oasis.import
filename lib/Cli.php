<?php

namespace Oasis\Import;

use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use CFile;
use Exception;

class Cli
{
    public static array $dbCategories = [];
    const MODULE_ID = 'oasis.import';
    const MSG_STATUS = true;
    const MSG_TO_FILE = false;

    /**
     * @throws LoaderException
     * @throws Exception
     */
    public static function import()
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
            $module_id = pathinfo(dirname(__DIR__))['basename'];
            $iblockIdCatalog = (int)Option::get($module_id, 'iblock_catalog');
            $iblockIdOffers = (int)Option::get($module_id, 'iblock_offers');
            $deleteExclude = Option::get($module_id, 'delete_exclude');
            self::$dbCategories = explode(',', Option::get($module_id, 'categories'));

            if (empty($iblockIdCatalog) || empty($iblockIdOffers)) {
                throw new Exception('Infoblocks not selected');
            }

            $step = (int)Option::get($module_id, 'step');
            $limit = (int)Option::get($module_id, 'limit');
            $dataCalcPrice = [
                'factor'   => str_replace(',', '.', Option::get($module_id, 'factor')),
                'increase' => str_replace(',', '.', Option::get($module_id, 'increase')),
                'dealer'   => Option::get($module_id, 'dealer'),
            ];
            $dataCalcPrice = array_diff($dataCalcPrice, ['', 0]);

            if ($limit > 0) {
                $args['limit'] = $limit;
                $args['offset'] = $step * $limit;
            } else {
                Option::set($module_id, 'progressItem', 0);
            }

            Main::deleteLogFile();
            Main::checkStores();
            Main::checkUserFields($iblockIdCatalog);
            Main::checkProperties($iblockIdCatalog, $iblockIdOffers);

            $oasisProducts = Api::getProductsOasis($args);
            $oasisCategories = Api::getCategoriesOasis();
            $stat = Api::getStatProducts();

            $group_ids = [];
            $countProducts = 0;
            foreach ($oasisProducts as $product) {
                if (!empty($deleteExclude)) {
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

            if (!empty($deleteExclude)) {
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
                Option::set($module_id, 'progressTotal', $stat['products']);
                Option::set($module_id, 'progressStepItem', 0);
                Option::set($module_id, 'progressStepTotal', !empty($limit) ? $countProducts : 0);

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
                            Main::cliMsg('Up product id ' . $product->id, self::MSG_STATUS, self::MSG_TO_FILE);
                        } else {
                            $properties = Main::getPropertiesArray($product);
                            $properties += Main::getProductImages($product);
                            $productId = Main::addIblockElementProduct($product, $oasisCategories, $properties, $iblockIdCatalog, ProductTable::TYPE_PRODUCT);
                            Main::executeStoreProduct($productId, $product);
                            Main::cliMsg('Add product id ' . $product->id, self::MSG_STATUS, self::MSG_TO_FILE);
                        }

                        Main::upPropertiesFilter($productId, $product, $iblockIdCatalog);
                        Main::executeProduct($productId, $product, $product->group_id, ProductTable::TYPE_PRODUCT);
                        Main::executePriceProduct($productId, $product, $dataCalcPrice);
                        Main::upProgressBar($module_id, $limit);
                        unset($dbProducts, $dbProduct, $productId, $properties);
                    } else {
                        $firstProduct = reset($products);
                        $dbProduct = Main::checkProduct($firstProduct->group_id);

                        if ($dbProduct) {
                            $productId = (int)$dbProduct['ID'];
                            Main::upIblockElementProduct($productId, $firstProduct, $iblockIdCatalog, $oasisCategories);
                            Main::cliMsg('Up product id ' . $firstProduct->id, self::MSG_STATUS, self::MSG_TO_FILE);
                        } else {
                            $properties = Main::getPropertiesArray($firstProduct);
                            $productId = Main::addIblockElementProduct($firstProduct, $oasisCategories, $properties, $iblockIdCatalog, ProductTable::TYPE_SKU);
                            Main::cliMsg('Add product id ' . $firstProduct->id, self::MSG_STATUS, self::MSG_TO_FILE);
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
                                Main::cliMsg('Up offer id ' . $product->id, self::MSG_STATUS, self::MSG_TO_FILE);
                            } else {
                                $propertiesOffer = Main::getPropertiesArrayOffer($productId, $product, $firstIMG, $iblockIdOffers);
                                $productOfferId = Main::addIblockElementProduct($product, $oasisCategories, $propertiesOffer, $iblockIdOffers, ProductTable::TYPE_OFFER);
                                Main::executeMeasureRatioTable($productOfferId);
                                Main::executeStoreProduct($productOfferId, $product);
                                Main::cliMsg('Add offer id ' . $product->id, self::MSG_STATUS, self::MSG_TO_FILE);
                            }

                            Main::executeProduct($productOfferId, $product, $product->id, ProductTable::TYPE_OFFER);
                            Main::executePriceProduct($productOfferId, $product, $dataCalcPrice);
                            Main::upProgressBar($module_id, $limit);
                            unset($product, $dbOffer, $productOfferId, $propertiesOffer);
                        }

                        Main::upPropertiesFilterOffers($productId, $firstProduct, $products, $iblockIdCatalog);
                        Main::upStatusFirstProduct($productId, $iblockIdCatalog);
                        unset($firstProduct, $dbProduct, $productId, $properties);
                    }
                    Main::cliMsg('Done ' . ++$itemGroup . ' from ' . $totalGroup, self::MSG_STATUS, self::MSG_TO_FILE);
                    unset($products, $product);
                }
            } else {
                $nextStep = 0;
                Option::set($module_id, 'progressItem', 0);
            }

            if (!empty($limit)) {
                Option::set($module_id, 'step', $nextStep);
                Option::set($module_id, 'progressStepItem', 0);
            } else {
                Option::set($module_id, 'progressItem', $stat['products']);
            }

            $objDateTime = new DateTime();
            Option::set($module_id, 'progressDate', $objDateTime->toString());
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
            exit();
        }

        return "\\Oasis\\Import\\Cli::import();";
    }

    /**
     * Cron - Up stock
     *
     * @return string
     * @throws LoaderException
     * @throws Exception
     */
    public static function upStock()
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

        return "\\Oasis\\Import\\Cli::upStock();";
    }
}
